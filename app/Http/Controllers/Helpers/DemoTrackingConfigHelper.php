<?php

namespace App\Http\Controllers\Helpers;

use App\Models\DemoTrackingConfig;
use Illuminate\Support\Facades\Log;

/**
 * Configuracion del canal de eventos de una instancia de demo (mision 50).
 *
 * `hay_canal()` es el interruptor maestro de toda la mision: si no hay fila, o el token
 * esta vacio, NADA de lo que agrego la mision 50 hace nada. Este codigo se despliega en
 * las instancias de los ~40 clientes reales, no solo en las tres de demo.
 *
 * El resultado se cachea en una propiedad estatica, o sea por request/proceso, y NO por
 * config ni por Cache::. La fila se reescribe en cada migrate:fresh del setup, asi que
 * un cache persistente serviria la configuracion de la demo anterior.
 */
class DemoTrackingConfigHelper
{
    /** Ancho de la columna `eventos_token`. Ver la guarda de guardar(). */
    const LARGO_MAX_TOKEN = 64;

    /**
     * Fila cacheada dentro de este request. Tres estados distintos y los tres importan:
     * null = todavia no se resolvio, false = se resolvio y no hay canal,
     * DemoTrackingConfig = se resolvio y hay canal.
     *
     * @var \App\Models\DemoTrackingConfig|false|null
     */
    private static $cache = null;

    /**
     * Persiste la configuracion del canal que llego en el payload del setup.
     *
     * Se llama al final de DemoSetupHelper::run(), despues del migrate:fresh, por el
     * mismo motivo que DemoIngresoTokenHelper::guardar(): si se llamara antes, el
     * migrate la borraria.
     *
     * @param string $eventos_token Token emitido por admin-api (header X-Demo-Eventos-Key).
     * @param string $eventos_url URL del canal de ingesta del admin.
     * @param array|null $plan Plan de la demo ya resuelto y congelado del lado del admin.
     * @param array|null $media_urls Mapa slot_id => url de los videos.
     * @return \App\Models\DemoTrackingConfig|null La fila creada, o null si el token vino vacio.
     */
    public static function guardar($eventos_token, $eventos_url, $plan = null, $media_urls = null)
    {
        $token = trim((string) $eventos_token);
        $url = trim((string) $eventos_url);

        /**
         * Sin token o sin url no hay canal posible. Se sale sin escribir nada en vez de
         * dejar una fila a medias: una fila con token vacio haria que hay_canal() diera
         * false igual, pero cada emision pagaria la query de descubrirlo.
         */
        if ($token === '' || $url === '') {
            return null;
        }

        /**
         * 🔴 Un token mas largo que la columna NO se trunca y NO se intenta guardar.
         *
         * Truncarlo dejaria el canal armado con una credencial que el admin va a rechazar
         * con 401, o sea un canal roto que se ve sano. E intentar el insert es peor: con
         * `strict` prendido, MySQL responde "Data too long" y Laravel arma el mensaje de la
         * QueryException interpolando los bindings, asi que el token EN CLARO termina en el
         * Log::error de DemoSetupController y en el cuerpo del 500 que ese controller le
         * devuelve al admin. El log de esta excepcion no nombra el token por lo mismo.
         */
        if (strlen($token) > self::LARGO_MAX_TOKEN) {
            Log::error('DemoTrackingConfigHelper: el token del canal vino con ' . strlen($token) . ' caracteres y la columna acepta ' . self::LARGO_MAX_TOKEN . '. No se configura el canal.');

            return null;
        }

        self::$cache = null;

        /**
         * El try/catch existe por el mismo motivo que la guarda de arriba: cualquier fallo del
         * insert (deadlock, conexion caida, tabla ausente porque la instancia todavia no migro)
         * trae el token en el mensaje de la excepcion, y ese mensaje sube hasta el body de la
         * respuesta del setup. Se registra con el token enmascarado y el setup sigue: quedarse
         * sin canal es un caso previsto; filtrar la credencial, no.
         *
         * El delete() va ADENTRO: la tabla ausente lo hace tirar a el primero, asi que dejarlo
         * afuera era dejar sin cubrir justo el caso que este docblock nombra.
         */
        try {
            /**
             * La tabla es de una sola fila. En el flujo real el migrate:fresh ya la dejo
             * vacia, pero se borra igual por si este metodo se llama fuera del setup.
             */
            DemoTrackingConfig::query()->delete();

            return DemoTrackingConfig::create([
                'eventos_token' => $token,
                'eventos_url' => $url,
                'plan' => is_array($plan) ? $plan : null,
                'media_urls' => is_array($media_urls) ? $media_urls : null,
            ]);
        } catch (\Throwable $e) {
            self::$cache = null;

            Log::error('DemoTrackingConfigHelper: no se pudo guardar la configuracion del canal: ' . self::ocultar_token($e->getMessage(), $token));

            return null;
        }
    }

    /**
     * Reemplaza el token por una marca en cualquier texto que vaya a un log, a una columna
     * o a una respuesta.
     *
     * Hace falta porque hay dos caminos por los que el token vuelve reflejado sin que este
     * codigo lo haya escrito: el mensaje de una QueryException (Laravel interpola los
     * bindings) y el cuerpo de la respuesta del admin, que es texto ajeno y puede citar la
     * key que rechazo.
     *
     * @param string $texto
     * @param string|null $token Token a ocultar. Si no se pasa, se usa el del canal actual.
     * @return string
     */
    public static function ocultar_token($texto, $token = null)
    {
        $texto = (string) $texto;

        if (is_null($token)) {
            $config = self::actual();
            $token = is_null($config) ? '' : (string) $config->eventos_token;
        }

        $token = trim((string) $token);

        /** str_replace con aguja vacia no reemplaza nada, pero se corta igual por claridad. */
        if ($token === '') {
            return $texto;
        }

        /**
         * No alcanza con buscar el token tal cual: el texto que llega es de otro sistema y
         * puede traerlo transformado. Las dos formas que aparecen de verdad son la del JSON de
         * Laravel, que escapa las barras (`a/b` -> `a\/b`), y la de una URL, que las
         * porcentajea. Un token base64 tiene barras con alta probabilidad, y el alfabeto lo
         * elige el admin, no este repo.
         */
        $formas = [
            $token,
            str_replace('/', '\\/', $token),
            rawurlencode($token),
            urlencode($token),
        ];

        return str_replace(array_unique($formas), '***', $texto);
    }

    /**
     * Devuelve la fila de configuracion del canal, o null si esta instancia no es una demo.
     *
     * @return \App\Models\DemoTrackingConfig|null
     */
    public static function actual()
    {
        /**
         * 🔴 En consola NO se cachea, y ese es el punto del cache.
         *
         * El cache es "por request", y en PHP-FPM eso se cumple solo porque el proceso se
         * recicla. Un worker de cola no: `queue:work --stop-when-empty` con
         * withoutOverlapping(75) puede vivir hasta 75 minutos, y ahi el estatico se
         * convierte en el cache persistente que el docblock de arriba dice evitar. El caso
         * concreto: el worker resuelve "no hay canal" a las 10:00, el setup escribe la fila
         * a las 10:02 (en otro proceso, asi que su olvidar_cache() no llega hasta aca), y a
         * las 10:08 FinalizeArticleImport no emite importacion.completada — sin una sola
         * linea de log que lo explique. Era el unico drop 100% silencioso de la mision.
         *
         * El costo de no cachear es una query por llamada en consola, y en consola las
         * llamadas son una por importacion terminada y dos por corrida del barrido.
         */
        if (self::$cache === null || app()->runningInConsole()) {
            self::$cache = self::resolver();
        }

        return self::$cache === false ? null : self::$cache;
    }

    /**
     * Interruptor maestro: ¿esta instancia tiene canal de eventos configurado?
     *
     * @return bool
     */
    public static function hay_canal()
    {
        $config = self::actual();

        return !is_null($config) && trim((string) $config->eventos_token) !== '';
    }

    /**
     * Descarta el cache de este proceso. Lo usan el setup (que acaba de reescribir la
     * fila) y los tests, que cambian la configuracion entre casos dentro del mismo proceso.
     *
     * @return void
     */
    public static function olvidar_cache()
    {
        self::$cache = null;
    }

    /**
     * Lee la fila de la base una sola vez por request.
     *
     * El try/catch no es decorativo: una instancia que todavia no corrio las migraciones
     * de esta mision no tiene la tabla, y ahi la consulta tira. Sin canal configurado el
     * resultado correcto es "no hay canal", nunca una excepcion que suba al request del
     * usuario. Se loguea igual, porque un catch mudo alrededor de una lectura produce un
     * resultado plausible que nadie revisa nunca (APRENDER_NO_PARCHEAR.md).
     *
     * @return \App\Models\DemoTrackingConfig|false
     */
    private static function resolver()
    {
        try {
            $config = DemoTrackingConfig::query()->first();
        } catch (\Throwable $e) {
            Log::warning('DemoTrackingConfigHelper: no se pudo leer demo_tracking_config: ' . $e->getMessage());

            return false;
        }

        return is_null($config) ? false : $config;
    }
}
