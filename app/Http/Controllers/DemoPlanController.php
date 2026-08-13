<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoEvento;
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

        return response()->json([
            'notas' => self::ultimas_notas(),
            'secciones' => self::secciones_con_urls($config->plan, $config->media_urls, $progreso),
        ], 200);
    }

    /**
     * Estado de cada clip segun los eventos locales, en UNA sola query agrupada.
     *
     * No se filtra por `sincronizado_at`: para restaurarle la pantalla al lead da exactamente lo
     * mismo si el admin ya se entero o no.
     *
     * @return array<string, array{abierto:bool, visto:bool}> Mapa clip_id => estado.
     */
    private static function progreso_por_clip()
    {
        $filas = DemoEvento::select('clip_id', 'nombre')
            ->whereIn('nombre', ['clip.abierto', 'clip.terminado'])
            ->whereNotNull('clip_id')
            ->groupBy('clip_id', 'nombre')
            ->get();

        $progreso = [];

        foreach ($filas as $fila) {
            $clip_id = (string) $fila->clip_id;

            if (!isset($progreso[$clip_id])) {
                $progreso[$clip_id] = ['abierto' => false, 'visto' => false];
            }

            if ($fila->nombre === 'clip.terminado') {
                $progreso[$clip_id]['visto'] = true;
            } else {
                $progreso[$clip_id]['abierto'] = true;
            }
        }

        return $progreso;
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
                    'practica' => isset($clip['practica']) ? (bool) $clip['practica'] : false,
                    'url' => isset($urls[$clip_id]) && trim((string) $urls[$clip_id]) !== ''
                        ? (string) $urls[$clip_id]
                        : null,
                    /** Estado restaurado (mision 52). Sin eventos, los dos en false. */
                    'abierto' => isset($progreso[$clip_id]) ? $progreso[$clip_id]['abierto'] : false,
                    'visto' => isset($progreso[$clip_id]) ? $progreso[$clip_id]['visto'] : false,
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
