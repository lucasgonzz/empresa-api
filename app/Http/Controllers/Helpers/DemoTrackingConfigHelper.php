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
         * La tabla es de una sola fila. En el flujo real el migrate:fresh ya la dejo
         * vacia, pero se borra igual por si este metodo se llama fuera del setup.
         */
        DemoTrackingConfig::query()->delete();

        self::$cache = null;

        return DemoTrackingConfig::create([
            'eventos_token' => $token,
            'eventos_url' => $url,
            'plan' => is_array($plan) ? $plan : null,
            'media_urls' => is_array($media_urls) ? $media_urls : null,
        ]);
    }

    /**
     * Devuelve la fila de configuracion del canal, o null si esta instancia no es una demo.
     *
     * @return \App\Models\DemoTrackingConfig|null
     */
    public static function actual()
    {
        if (self::$cache === null) {
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
