<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoEvento;
use App\Services\DemoMediaUrlsFetcher;
use Illuminate\Http\Request;

/**
 * El plan de la demo, para el panel lateral del lead (mision 51).
 *
 * Devuelve lo que la mision 50 persistio en `demo_tracking_config`, con la URL de cada clip
 * ya resuelta contra `media_urls`. Las URLs viajan aparte del plan a proposito: el plan es
 * estructura y se congela del lado del admin, las URLs son contenido y se cargan despues.
 *
 * 🔴 Este codigo corre en las instancias de los ~40 clientes reales. Fuera de una sesion de
 * demo responde 204 sin cuerpo y sin tocar la base -- no 403, porque el mismo empresa-spa se
 * despliega en todas y un 403 les llenaria la consola de errores ajenos.
 */
class DemoPlanController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        /**
         * Guarda primera y sin base: el marcador lo pone DemoIngresoController al abrir la
         * sesion del lead, y es la misma senal que usan DemoSessionVigente y el emisor de
         * eventos. Sin el, no hay nada que devolver.
         */
        if (!$request->hasSession() || empty($request->session()->get('demo_ingreso_token_id'))) {
            return response(null, 204);
        }

        $config = DemoTrackingConfigHelper::actual();

        /**
         * Una demo de una instancia que nunca recibio plan (setup viejo, o admin sin
         * desplegar) es un caso previsto: el lead entra igual y el panel no se muestra.
         */
        if (is_null($config) || !is_array($config->plan)) {
            return response(null, 204);
        }

        /* El progreso para restaurar la pantalla se deriva de la tabla LOCAL demo_eventos, no se le
         * pregunta al admin. Dos motivos, y el segundo es el que manda: (1) el dato ya está acá, porque
         * todo evento se persiste local antes de empujarse; (2) la misión 50 se construyó explícitamente
         * para que la demo funcione con el admin caído — hay un test de eso. Si la pantalla del lead
         * dependiera del admin para saber qué clips ya miró, una caída del admin dejaría al lead mirando
         * de nuevo videos que ya vio, que es exactamente el momento en que abandona.
         * El admin sigue siendo la fuente de verdad de lo que MIRA LUCAS (lead_demo_hitos); son los
         * mismos eventos con dos consumidores distintos. Misión 52. */
        $progreso = self::progreso_por_clip();

        /* Las URLs de los videos SI se le piden al admin, y la asimetria con el progreso de arriba
         * es deliberada: el progreso nace acá y el admin es un espectador, pero las URLs nacen del
         * admin y esta instancia solo tiene la foto del momento del setup. Esa foto se saca antes
         * de que Lucas cargue los links (medido: setup 15:02, links 15:51), asi que el mapa
         * guardado llega vacio de todos los clips y el panel dice "Este video todavia no esta
         * disponible" en los 28.
         *
         * 🔴 Y el admin sigue sin poder romper el panel: el fetcher no deja escapar NINGUNA
         * Throwable y devuelve null ante cualquier falla --red, timeout, 401, 404, JSON invalido--,
         * asi que la respuesta cae al `media_urls` guardado, que es exactamente lo que este
         * endpoint devolvia antes de esta mision. Mision demo-panel-recorrido. */
        $frescas = DemoMediaUrlsFetcher::frescas($config);

        return response()->json([
            'notas' => self::ultimas_notas(),
            'secciones' => self::secciones_con_urls(
                $config->plan,
                is_array($frescas) ? $frescas : $config->media_urls,
                $progreso
            ),
        ], 200);
    }

    /**
     * Estado de cada clip segun los eventos locales, en DOS queries acotadas.
     *
     * No se filtra por `sincronizado_at`: para restaurarle la pantalla al lead da exactamente lo
     * mismo si el admin ya se entero o no.
     *
     * 🔴 Son dos queries y no una, y eso NO es un descuido que haya que "optimizar" juntandolas.
     * La primera agrupa por `clip_id, nombre` y no trae `datos`, que es lo que la hace barata; la
     * segunda SI necesita leer `datos` --`probado` sale de `tour.completado` con
     * `datos.completo === true`, y el porcentaje de `datos.porcentaje`--, y un `groupBy` sobre una
     * columna JSON no agrupa nada util. Meterlas en una sola obligaria a traer `datos` de todas
     * las filas, incluidas las que no lo usan.
     *
     * El volumen esta acotado por construccion: esta tabla es de UNA instancia de demo (un lead),
     * y son ~10 filas de `clip.progreso` por video mirado.
     *
     * @return array<string, array{abierto:bool, visto:bool, probado:bool, porcentaje_visto:int}>
     *         Mapa clip_id => estado.
     */
    private static function progreso_por_clip()
    {
        $progreso = [];

        $filas = DemoEvento::select('clip_id', 'nombre')
            ->whereIn('nombre', ['clip.abierto', 'clip.terminado'])
            ->whereNotNull('clip_id')
            ->groupBy('clip_id', 'nombre')
            ->get();

        foreach ($filas as $fila) {
            $clip_id = (string) $fila->clip_id;

            if (!isset($progreso[$clip_id])) {
                $progreso[$clip_id] = self::estado_inicial();
            }

            if ($fila->nombre === 'clip.terminado') {
                $progreso[$clip_id]['visto'] = true;
                /** El video llego al final: el porcentaje deja de ser una estimacion. */
                $progreso[$clip_id]['porcentaje_visto'] = 100;
            } else {
                $progreso[$clip_id]['abierto'] = true;
            }
        }

        $con_datos = DemoEvento::select('clip_id', 'nombre', 'datos')
            ->whereIn('nombre', ['tour.completado', 'clip.progreso'])
            ->whereNotNull('clip_id')
            ->get();

        foreach ($con_datos as $fila) {
            $clip_id = (string) $fila->clip_id;

            if (!isset($progreso[$clip_id])) {
                $progreso[$clip_id] = self::estado_inicial();
            }

            $datos = is_array($fila->datos) ? $fila->datos : [];

            if ($fila->nombre === 'tour.completado') {
                /**
                 * 🔴 `completo` es lo unico que separa un tour terminado de uno abandonado: al
                 * saltear pasos tambien se llega al final, y el motor marca `completo: false`
                 * cuando se mostro menos de la mitad de los pasos. Sin este chequeo, un tour que
                 * el lead corto en el paso 2 pintaria el boton verde igual.
                 *
                 * Se aceptan las cuatro formas de "si" porque `datos` es JSON libre que entro
                 * desde el navegador: la nuestra manda un booleano, pero un `"true"` no puede
                 * quedar en false silencioso.
                 */
                $completo = isset($datos['completo']) ? $datos['completo'] : null;

                if ($completo === true || $completo === 1 || $completo === '1' || $completo === 'true') {
                    $progreso[$clip_id]['probado'] = true;
                }

                continue;
            }

            /** `clip.progreso` llega ~10 veces por video: se queda el MAXIMO, nunca el ultimo. */
            $porcentaje = self::porcentaje_de($datos);

            if ($porcentaje > $progreso[$clip_id]['porcentaje_visto']) {
                $progreso[$clip_id]['porcentaje_visto'] = $porcentaje;
            }
        }

        return $progreso;
    }

    /**
     * Estado de un clip del que todavia no se leyo ningun evento.
     *
     * Vive aparte porque lo arman los dos recorridos de progreso_por_clip(), y un clip puede
     * entrar por cualquiera de los dos: el que solo tiene `clip.progreso` (miro medio video y no
     * lo termino) nunca aparece en la primera query.
     *
     * @return array
     */
    private static function estado_inicial()
    {
        return [
            'abierto' => false,
            'visto' => false,
            'probado' => false,
            'porcentaje_visto' => 0,
        ];
    }

    /**
     * Porcentaje de `datos`, clampeado a 0..100.
     *
     * 🔴 `datos` es JSON libre que entro por POST /api/demo/evento desde el navegador: la clave
     * puede faltar, venir null, venir texto o venir absurda. Cualquiera de esas cosas vale 0 --lo
     * mismo que no haber mirado nada--, que es el default seguro: se puede quedar corto, nunca
     * inventa progreso que el lead no hizo.
     *
     * @param array $datos
     * @return int
     */
    private static function porcentaje_de(array $datos)
    {
        if (!isset($datos['porcentaje']) || !is_numeric($datos['porcentaje'])) {
            return 0;
        }

        $porcentaje = (int) round((float) $datos['porcentaje']);

        if ($porcentaje < 0) {
            return 0;
        }

        if ($porcentaje > 100) {
            return 100;
        }

        return $porcentaje;
    }

    /**
     * Texto del ULTIMO `nota.escrita`. El panel manda el texto completo en cada reporte, no
     * incrementos, asi que el ultimo evento es el estado actual del campo.
     *
     * @return string
     */
    private static function ultimas_notas()
    {
        /**
         * 🔴 Se busca el ultimo evento CON TEXTO, no el ultimo a secas.
         *
         * DemoEventoEmitter::acotar_datos() descarta el contenido cuando `datos` supera 2 KB
         * serializados y guarda en su lugar una marca `_descartado`. Con `first()` a secas, un
         * lead que escribio dos parrafos --cosa normal en una demo de 40 minutos-- recargaba y
         * este metodo encontraba ese ultimo evento sin `texto`, devolvia '' y el panel le
         * PISABA las notas con vacio. Y el texto bueno estaba ahi, en el evento anterior.
         *
         * El tope de 5 acota el escaneo: mas atras que eso ya no es "lo ultimo que escribio".
         */
        $eventos = DemoEvento::where('nombre', 'nota.escrita')
            ->orderBy('ocurrido_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(5)
            ->get();

        foreach ($eventos as $evento) {
            if (is_array($evento->datos) && isset($evento->datos['texto']) && $evento->datos['texto'] !== '') {
                return (string) $evento->datos['texto'];
            }
        }

        return '';
    }

    /**
     * Arma las secciones del plan con la URL de cada clip resuelta.
     *
     * Un clip sin URL cargada **viaja igual**, con `url: null`. Filtrarlos dejaria el panel
     * vacio mientras los videos no esten grabados, que es hoy: solo esta subido `intro.mp4`.
     * El panel sabe que hacer con un clip sin video.
     *
     * @param array $plan Plan congelado tal como lo dejo el admin (mision 48).
     * @param array|null $media_urls Mapa slot_id => url.
     * @param array $progreso Mapa clip_id => estado, de progreso_por_clip() (mision 52).
     * @return array
     */
    private static function secciones_con_urls(array $plan, $media_urls, array $progreso = [])
    {
        $urls = is_array($media_urls) ? $media_urls : [];

        $secciones = isset($plan['secciones']) && is_array($plan['secciones']) ? $plan['secciones'] : [];

        $salida = [];

        foreach ($secciones as $seccion) {
            if (!is_array($seccion)) {
                continue;
            }

            $id = isset($seccion['id']) ? (string) $seccion['id'] : '';
            $clips = isset($seccion['clips']) && is_array($seccion['clips']) ? $seccion['clips'] : [];

            $clips_salida = [];

            foreach ($clips as $clip) {
                if (!is_array($clip) || !isset($clip['id'])) {
                    continue;
                }

                $clip_id = (string) $clip['id'];

                $clips_salida[] = [
                    'id' => $clip_id,
                    'titulo' => isset($clip['titulo']) ? $clip['titulo'] : '',
                    'tipo' => isset($clip['tipo']) ? $clip['tipo'] : 'nucleo',
                    /**
                     * Default true y no false: desde el 24/8/2026 la SPA oculta el botón
                     * "Probar" cuando practica es false EXPLÍCITO. Un plan congelado que no
                     * traiga el campo tiene que comportarse como siempre (botón visible) — con
                     * default false, todos los clips de ese lead perderían el botón.
                     */
                    'practica' => isset($clip['practica']) ? (bool) $clip['practica'] : true,
                    'url' => isset($urls[$clip_id]) && trim((string) $urls[$clip_id]) !== ''
                        ? (string) $urls[$clip_id]
                        : null,
                    /** Estado restaurado (mision 52). Sin eventos, los dos en false. */
                    'abierto' => isset($progreso[$clip_id]) ? $progreso[$clip_id]['abierto'] : false,
                    'visto' => isset($progreso[$clip_id]) ? $progreso[$clip_id]['visto'] : false,
                    /**
                     * Campos AGREGADOS el 1/9/2026, ninguno renombrado ni sacado: un panel viejo
                     * simplemente no los lee y sigue funcionando igual.
                     *
                     * `probado` es lo que hace que el boton "Probar" verde con el check sobreviva
                     * al F5, por el mismo motivo y con el mismo mecanismo que `visto`: si solo
                     * viviera en memoria, el lead que recarga ve como no probado un tour que si
                     * completo.
                     */
                    'probado' => isset($progreso[$clip_id]) ? $progreso[$clip_id]['probado'] : false,
                    'porcentaje_visto' => isset($progreso[$clip_id]) ? $progreso[$clip_id]['porcentaje_visto'] : 0,
                ];
            }

            $salida[] = [
                'id' => $id,
                /**
                 * Los ids del catalogo vienen con la forma "S1 - Listado". El titulo que se
                 * muestra es lo que va despues del guion; si no hay guion, el id entero.
                 */
                'titulo' => self::titulo_de_seccion($id),
                'clips' => $clips_salida,
            ];
        }

        return $salida;
    }

    /**
     * @param string $id
     * @return string
     */
    private static function titulo_de_seccion($id)
    {
        $partes = explode(' - ', $id, 2);

        return count($partes) === 2 ? trim($partes[1]) : trim($id);
    }
}
