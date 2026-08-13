<?php

namespace App\Services;

use App\Http\Controllers\Helpers\DemoEventosPushHelper;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoEvento;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Unico punto de entrada de los eventos de la demo (mision 50).
 *
 * Todo evento se persiste primero en demo_eventos y despues se intenta empujar al admin.
 * El push nunca ocurre dentro del request del usuario: se agenda para despues de mandada
 * la respuesta (ver programar_flush()).
 *
 * 🔴 Este servicio se despliega en las instancias de los ~40 clientes reales, no solo en
 * las de demo. Por eso el orden de las guardas de emitir() no es cosmetico: la primera no
 * toca la base, y es la que hace que este codigo sea un no-op de costo cero en el camino
 * caliente de un cliente real (ArticleController@store, SaleController@store).
 */
class DemoEventoEmitter
{
    /**
     * Eventos que puede reportar el SPA por POST /api/demo/evento.
     *
     * Es una lista blanca y no una validacion de formato: sin ella el endpoint es un
     * buzon abierto que escribe filas arbitrarias en la base desde el navegador.
     *
     * @var array<int, string>
     */
    const NOMBRES_UX = [
        'clip.abierto',
        'clip.terminado',
        'nota.escrita',
        'seccion.completada',
    ];

    /**
     * Eventos que emite el servidor. No son aceptables desde el SPA: si el front pudiera
     * mandarlos serian falsificables.
     *
     * @var array<int, string>
     */
    const NOMBRES_NEGOCIO = [
        'demo.ingreso',
        'articulo.creado',
        'venta.creada',
        'compra.creada',
        'importacion.completada',
    ];

    /**
     * Eventos "grandes": los que el panel del admin espera ver en vivo, asi que disparan
     * un flush despues de la respuesta en vez de esperar al barrido del minuto.
     *
     * @var array<int, string>
     */
    const NOMBRES_CON_PUSH_INMEDIATO = [
        'demo.ingreso',
        'clip.terminado',
        'articulo.creado',
        'venta.creada',
        'compra.creada',
        /**
         * En la practica este nunca consigue el push inmediato: su unico emisor es
         * FinalizeArticleImport, que corre en el worker, y ahi programar_flush() no agenda
         * nada porque no hay respuesta que esperar. Llega por el barrido del minuto. Queda
         * declarado igual para el dia que se emita desde un request.
         */
        'importacion.completada',
    ];

    /** Tope de `datos` ya serializado. El campo de notas del panel manda texto libre del lead. */
    const MAX_BYTES_DATOS = 2048;

    /** Tope de claves de `datos`. El canal del admin rechaza con 422 definitivo por encima de 50. */
    const MAX_CLAVES_DATOS = 50;

    /**
     * Marca si ya se agendo el flush posterior a la respuesta en este request. Sin esto,
     * tres eventos en un mismo request agendarian tres flush identicos.
     *
     * @var bool
     */
    private static $flush_agendado = false;

    /**
     * Registra un evento de la demo y agenda su empuje al admin.
     *
     * @param string $nombre Nombre del catalogo (NOMBRES_UX o NOMBRES_NEGOCIO).
     * @param string|null $clip_id Clip del plan al que pertenece, cuando aplica.
     * @param array $datos Carga util. Para los eventos de negocio, el id del registro creado y nada mas.
     * @return \App\Models\DemoEvento|null La fila creada, o null si no habia nada que registrar.
     */
    public static function emitir($nombre, $clip_id = null, $datos = [])
    {
        /**
         * Se normaliza ANTES del try, no adentro: el catch de abajo concatena $nombre en el
         * mensaje del log, asi que un $nombre que no sea string haria tirar al propio catch
         * ("Array to string conversion" sube como ErrorException) y la excepcion escaparia
         * justo por donde este servicio promete que nunca escapa nada.
         */
        $nombre = is_scalar($nombre) ? trim((string) $nombre) : '';

        /*
         * Un evento de tracking NO puede romper la operacion que lo produjo. Si esto tira, el lead se
         * queda sin poder crear el articulo — y el articulo es el producto, el evento es la metrica.
         * Por eso todo el cuerpo va adentro de try/catch. Lo que NO se hace es tragarse el error en
         * silencio: se loguea con el nombre del evento, porque un catch mudo alrededor de una escritura
         * produce un resultado parcial plausible y nadie se entera nunca (APRENDER_NO_PARCHEAR.md).
         * Mision 50.
         */
        try {
            /**
             * Guarda 1, y la unica que corre para el 100% de los requests de un cliente real:
             * NO toca la base. Un request que no viene de una sesion de demo no tiene nada que
             * registrar, y esa condicion se resuelve mirando la sesion y nada mas.
             */
            if (self::fuera_de_una_sesion_de_demo($nombre)) {
                return null;
            }

            /** Guarda 2: el interruptor maestro. Una query, cacheada por request. */
            if (!DemoTrackingConfigHelper::hay_canal()) {
                return null;
            }

            /**
             * Defensa en profundidad: el endpoint del SPA ya filtra por NOMBRES_UX, pero el
             * emisor no confia en su llamador. Un nombre desconocido no se escribe.
             */
            if (!in_array($nombre, self::NOMBRES_UX, true) && !in_array($nombre, self::NOMBRES_NEGOCIO, true)) {
                Log::info('DemoEventoEmitter: nombre de evento fuera del catalogo, se descarta: ' . $nombre);

                return null;
            }

            $evento = DemoEvento::create([
                'uuid' => (string) Str::uuid(),
                'nombre' => $nombre,
                'clip_id' => self::normalizar_clip_id($clip_id),
                'datos' => self::acotar_datos($datos, $nombre),
                'ocurrido_at' => Carbon::now(),
                'sincronizado_at' => null,
                'intentos' => 0,
                'ultimo_error' => null,
            ]);

            if (in_array($nombre, self::NOMBRES_CON_PUSH_INMEDIATO, true)) {
                self::programar_flush();
            }

            return $evento;
        } catch (\Throwable $e) {
            Log::warning('DemoEventoEmitter: fallo al emitir el evento "' . $nombre . '": ' . $e->getMessage());

            return null;
        }
    }

    /**
     * ¿El nombre es uno de los que el SPA puede reportar?
     *
     * @param string $nombre
     * @return bool
     */
    public static function nombre_ux_aceptado($nombre)
    {
        return in_array(trim((string) $nombre), self::NOMBRES_UX, true);
    }

    /**
     * Reinicia el estado de proceso. Solo lo usan los tests, que emiten muchos eventos
     * dentro del mismo proceso y no quieren arrastrar el flag del flush entre casos.
     *
     * @return void
     */
    public static function reiniciar_estado_de_proceso()
    {
        self::$flush_agendado = false;
    }

    /**
     * ¿Se puede descartar, sin tocar la base, que esto ocurra dentro de una demo?
     *
     * La demo entera se sostiene sobre la sesion: DemoIngresoController hace Auth::login()
     * y deja el marcador `demo_ingreso_token_id`, y DemoSessionVigente corta el turno
     * leyendo ese mismo marcador. Entonces una sesion arrancada SIN ese marcador es
     * concluyente: no es una demo. Y eso es todo el trafico del SPA de un cliente real,
     * que es stateful por cookie (`withCredentials` + /sanctum/csrf-cookie), resuelto sin
     * consultar nada.
     *
     * Cuando NO hay sesion arrancada esta guarda NO descarta: devuelve false y decide la
     * guarda 2, que si consulta. Dos casos caen ahi, y conviene tenerlos escritos:
     *
     * 1. El worker de cola que cierra una importacion. Una query por importacion terminada.
     * 2. Un consumidor autenticado por Bearer (Personal Access Token de Sanctum) en vez de
     *    por cookie. **Hoy no existe ninguno** —no hay un solo createToken() en el repo, y
     *    el SPA es cookie puro—, pero si mañana se agrega una app movil o una integracion
     *    que pegue a estos endpoints, va a pagar una query (memoizada por request). O sea
     *    que el costo cero no lo sostiene esta guarda sola: lo sostiene que el unico
     *    consumidor autenticado sea stateful.
     *
     * @param string $nombre Nombre del evento en curso, solo para que el log sea correlacionable.
     * @return bool true si se puede descartar la demo sin consultar nada.
     */
    private static function fuera_de_una_sesion_de_demo($nombre)
    {
        try {
            $request = request();

            if (is_null($request) || !$request->hasSession() || !$request->session()->isStarted()) {
                return false;
            }

            /** El marcador lo pone DemoIngresoController al abrir la sesion del lead. */
            return empty($request->session()->get('demo_ingreso_token_id'));
        } catch (\Throwable $e) {
            /**
             * Resolver el request no deberia fallar nunca, pero si falla, la respuesta
             * segura es "no puedo descartar" y que decida la guarda 2, que tiene su propio
             * try/catch. Ni un camino de este servicio puede dejar de responder.
             */
            Log::warning('DemoEventoEmitter: no se pudo leer la sesion al emitir "' . $nombre . '": ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Agenda un flush para despues de mandada la respuesta HTTP.
     *
     * Es exactamente lo que hace dispatch()->afterResponse() por debajo (registra un
     * callback de terminating en el contenedor), pero sin pasar por el serializador de
     * closures de la cola. No se usa un job encolado a proposito: queue:work corre
     * everyMinute() en este repo, asi que un job tendria la misma latencia que el
     * barrido y no compraria nada, y el panel del admin polea cada 10 segundos
     * esperando ver el movimiento en vivo.
     *
     * En consola (worker o comando) no hay respuesta que esperar: el flush queda para
     * el barrido de demo:flush-eventos.
     *
     * @return void
     */
    private static function programar_flush()
    {
        if (self::$flush_agendado || app()->runningInConsole()) {
            return;
        }

        self::$flush_agendado = true;

        /**
         * 🔴 El try/catch adentro del closure no es redundante con el de flush().
         *
         * Este callback corre despues de que la respuesta ya se mando, y public/index.php
         * NO envuelve $kernel->terminate() en nada. Una excepcion que se escape de aca llega
         * al handler global, que intenta renderizar una segunda respuesta sobre un socket con
         * los headers ya enviados: el HTML del error queda pegado al final del JSON que el
         * usuario ya recibio. O sea, exactamente "el evento de tracking rompio algo visible".
         *
         * Que flush() sea total hoy no alcanza: seria una proteccion prestada de otro archivo.
         * Mismo patron que UserHelper::schedule_company_owner_context_updated_broadcast().
         */
        app()->terminating(function () {
            try {
                DemoEventosPushHelper::flush();
            } catch (\Throwable $e) {
                Log::warning('DemoEventoEmitter: fallo el flush posterior a la respuesta: ' . $e->getMessage());
            }
        });
    }

    /**
     * Recorta `clip_id` al ancho de la columna. Un clip mas largo que eso no existe en
     * el catalogo; guardarlo truncado es preferible a que la escritura entera falle.
     *
     * @param string|null $clip_id
     * @return string|null
     */
    private static function normalizar_clip_id($clip_id)
    {
        if (is_null($clip_id) || $clip_id === '') {
            return null;
        }

        return substr(trim((string) $clip_id), 0, 10);
    }

    /**
     * Acota `datos` a lo que el canal del admin acepta: 50 claves y 2 KB serializados.
     *
     * No se recorta el contenido a la mitad (dejaria un json invalido del otro lado): se
     * reemplaza por una marca explicita, y se loguea. Un evento con los datos marcados
     * como truncados dice la verdad; uno con los datos partidos, no.
     *
     * @param mixed $datos
     * @param string $nombre Nombre del evento, para que el log sea accionable.
     * @return array|null
     */
    private static function acotar_datos($datos, $nombre)
    {
        if (!is_array($datos) || count($datos) === 0) {
            return null;
        }

        if (count($datos) > self::MAX_CLAVES_DATOS) {
            Log::warning('DemoEventoEmitter: "' . $nombre . '" trajo ' . count($datos) . ' claves en datos, se recorta a ' . self::MAX_CLAVES_DATOS . '.');
            $datos = array_slice($datos, 0, self::MAX_CLAVES_DATOS, true);
        }

        /**
         * json_encode puede devolver false ante contenido no serializable (recursos,
         * UTF-8 invalido). En ese caso no se guarda basura: se guarda la marca.
         */
        $serializado = json_encode($datos);

        if ($serializado === false) {
            Log::warning('DemoEventoEmitter: "' . $nombre . '" trajo datos no serializables, se descartan.');

            return ['_descartado' => 'no_serializable'];
        }

        if (strlen($serializado) > self::MAX_BYTES_DATOS) {
            Log::warning('DemoEventoEmitter: "' . $nombre . '" trajo ' . strlen($serializado) . ' bytes en datos, por encima del tope de ' . self::MAX_BYTES_DATOS . '. Se descartan.');

            return ['_descartado' => 'excede_tope', '_bytes' => strlen($serializado)];
        }

        return $datos;
    }
}
