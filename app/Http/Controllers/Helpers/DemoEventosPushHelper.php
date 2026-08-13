<?php

namespace App\Http\Controllers\Helpers;

use App\Models\DemoEvento;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Empuje de los eventos pendientes de la demo hacia el canal de ingesta del admin
 * (mision 50, contrato definido por la mision 48).
 *
 * Se dispara desde dos lugares y ninguno de los dos esta en el camino del usuario:
 * despues de mandada la respuesta cuando ocurrio un evento grande, y desde el comando
 * demo:flush-eventos que corre cada minuto barriendo lo que quedo.
 */
class DemoEventosPushHelper
{
    /**
     * Tope de eventos por lote. El canal del admin acepta hasta 200; se manda la mitad
     * para dejar margen y porque un lote mas chico falla mas barato.
     */
    const TOPE_POR_LOTE = 100;

    /**
     * Intentos antes de dar un evento por varado. Un canal caido no puede convertirse
     * en un reintento infinito ni en un log de un millon de lineas.
     */
    const MAX_INTENTOS = 10;

    /** Timeout corto y sin retry(): el reintento es el proximo flush, no un bloqueo aca. */
    const TIMEOUT_SEGUNDOS = 5;

    /**
     * Manda al admin todos los eventos pendientes que todavia tienen intentos disponibles.
     *
     * @return array{enviados:int, sincronizados:int, fallados:int, varados:int} Resumen de la corrida.
     */
    public static function flush()
    {
        $resumen = ['enviados' => 0, 'sincronizados' => 0, 'fallados' => 0, 'varados' => 0];

        /**
         * Fuera del try a proposito: el catch de abajo lo necesita para poder cobrarle el
         * intento al lote que estaba en vuelo cuando algo tiro.
         *
         * @var \Illuminate\Support\Collection|null
         */
        $pendientes = null;

        /**
         * Idem: el catch lo usa para enmascarar sin volver a preguntarle a la base cual era
         * el token. Si lo que tiro FUE la base, resolverlo de nuevo devolveria null y el
         * mensaje saldria sin enmascarar, que es justo cuando mas importa que no.
         *
         * @var string
         */
        $token = '';

        /**
         * Solo se le cobra el intento a un lote que efectivamente se intento postear. Sin esto,
         * cualquier hipo de base ANTES del POST —el COUNT de avisar_varados(), el foreach que
         * arma el payload— le comia un intento a todos los eventos pendientes sin que el canal
         * hubiera tenido nada que ver: diez hipos en diez minutos y la demo entera quedaba
         * varada para siempre. Cambiar un reintento infinito por una perdida definitiva no es
         * un arreglo.
         *
         * @var bool
         */
        $posteado = false;

        /*
         * Mismo criterio que el emisor: esto corre en las instancias de los clientes
         * reales (el comando esta en el schedule de todas) y ahi no tiene que hacer nada
         * mas que salir. Y como todo el resto de la mision, no puede tirar hacia afuera.
         */
        try {
            if (!DemoTrackingConfigHelper::hay_canal()) {
                return $resumen;
            }

            $config = DemoTrackingConfigHelper::actual();
            $token = (string) $config->eventos_token;

            $pendientes = DemoEvento::whereNull('sincronizado_at')
                ->where('intentos', '<', self::MAX_INTENTOS)
                ->orderBy('ocurrido_at', 'ASC')
                ->limit(self::TOPE_POR_LOTE)
                ->get();

            $resumen['varados'] = self::avisar_varados();

            if ($pendientes->count() === 0) {
                return $resumen;
            }

            $resumen['enviados'] = $pendientes->count();

            $respuesta = self::postear($config, $pendientes);
            $posteado = true;

            if ($respuesta['ok']) {
                $resumen['sincronizados'] = self::marcar_sincronizados($pendientes);

                return $resumen;
            }

            $resumen['fallados'] = self::marcar_fallados($pendientes, $respuesta['error'], $respuesta['definitivo']);

            return $resumen;
        } catch (\Throwable $e) {
            /**
             * 🔴 Este catch le cobra el intento al lote, y no es un detalle.
             *
             * El caso que lo justifica: el POST sale bien y el admin guarda los eventos, pero
             * marcar_sincronizados() tira despues (un deadlock contra el insert del emisor de
             * otro request, una conexion que vencio, una columna que falta por una migracion a
             * medias). Sin cobrar el intento, esos eventos quedan pendientes con intentos = 0 y
             * el barrido del minuto los reenvia para siempre: el tope de MAX_INTENTOS no los ve
             * porque el contador no avanza, y avisar_varados() tampoco, porque filtra por ese
             * mismo contador. Un reintento infinito que ningun freno de esta clase alcanza.
             */
            $cuantos = is_null($pendientes) ? 0 : $pendientes->count();

            Log::warning('DemoEventosPushHelper: fallo el flush con ' . $cuantos . ' evento(s) en vuelo: ' . DemoTrackingConfigHelper::ocultar_token($e->getMessage(), $token));

            if ($posteado && !is_null($pendientes) && $cuantos > 0) {
                /** Si lo que tiro fue la base, cobrar el intento tambien tira. Se intenta y listo. */
                try {
                    $resumen['fallados'] = self::marcar_fallados(
                        $pendientes,
                        'excepcion durante el flush: ' . DemoTrackingConfigHelper::ocultar_token($e->getMessage(), $token),
                        false
                    );
                } catch (\Throwable $e2) {
                    Log::warning('DemoEventosPushHelper: tampoco se pudo cobrar el intento del lote: ' . $e2->getMessage());
                }
            }

            return $resumen;
        }
    }

    /**
     * Postea el lote al canal del admin.
     *
     * @param \App\Models\DemoTrackingConfig $config
     * @param \Illuminate\Support\Collection $pendientes
     * @return array{ok:bool, error:string, definitivo:bool}
     */
    private static function postear($config, $pendientes)
    {
        $eventos = [];

        foreach ($pendientes as $evento) {
            $eventos[] = [
                'uuid' => $evento->uuid,
                'nombre' => $evento->nombre,
                'clip_id' => $evento->clip_id,
                /** El canal valida ocurrido_at como fecha real contra una columna timestamp. */
                'ocurrido_at' => $evento->ocurrido_at instanceof Carbon
                    ? $evento->ocurrido_at->toIso8601String()
                    : (string) $evento->ocurrido_at,
                'datos' => is_array($evento->datos) ? $evento->datos : [],
            ];
        }

        try {
            $respuesta = Http::withHeaders([
                    'Accept' => 'application/json',
                    /** El token ES el identificador del lead del otro lado. No se loguea nunca. */
                    'X-Demo-Eventos-Key' => $config->eventos_token,
                ])
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->post($config->eventos_url, ['eventos' => $eventos]);
        } catch (\Throwable $e) {
            /**
             * Error de red o timeout: el canal puede volver, se reintenta en el proximo flush.
             * El mensaje pasa por el enmascarado porque el de cURL trae la URL completa del
             * request, y `eventos_url` viene verbatim del payload del admin: si algun dia
             * emitiera la key en la query string, este log la publicaria.
             */
            return [
                'ok' => false,
                'error' => 'excepcion: ' . DemoTrackingConfigHelper::ocultar_token($e->getMessage(), $config->eventos_token),
                'definitivo' => false,
            ];
        }

        if ($respuesta->successful()) {
            return ['ok' => true, 'error' => '', 'definitivo' => false];
        }

        $status = $respuesta->status();

        /**
         * 401 y 422 son definitivos por contrato del canal (INFORME de la mision 48):
         * el 401 significa que el token no existe o que el lead volvio a la dinamica
         * anterior, y el 422 que el evento esta mal formado. En los dos casos reintentar
         * es el loop infinito que esas validaciones vienen a cerrar, asi que estos
         * eventos se dan por varados de una y no se vuelven a mandar.
         */
        $definitivo = $status === 401 || $status === 422;

        /**
         * El cuerpo es texto AJENO y se persiste en `ultimo_error` ademas de loguearse, asi
         * que pasa por el enmascarado: la forma normal de un 401 es citar la credencial que
         * rechazo, y ahi el token volveria reflejado y quedaria escrito de este lado.
         */
        /**
         * 🔴 Se enmascara PRIMERO y se recorta DESPUES. Al reves —que era como estaba— un token
         * que cayera a caballo del byte 500 quedaba partido: la mitad que sobrevivia al substr
         * ya no coincidia con el token entero, el str_replace no la encontraba, y ese prefijo
         * en claro terminaba escrito en `ultimo_error` y en el log.
         */
        return [
            'ok' => false,
            'error' => 'status ' . $status . ': ' . substr(
                DemoTrackingConfigHelper::ocultar_token((string) $respuesta->body(), $config->eventos_token),
                0,
                500
            ),
            'definitivo' => $definitivo,
        ];
    }

    /**
     * Marca el lote como sincronizado.
     *
     * El canal responde con contadores, no con la lista de uuids aceptados, y cuenta los
     * repetidos como duplicados con 200. Asi que una respuesta exitosa significa que el
     * lote entero quedo del otro lado.
     *
     * @param \Illuminate\Support\Collection $pendientes
     * @return int
     */
    private static function marcar_sincronizados($pendientes)
    {
        $ahora = Carbon::now();
        $ids = $pendientes->pluck('id')->all();

        DemoEvento::whereIn('id', $ids)->update([
            'sincronizado_at' => $ahora,
            'ultimo_error' => null,
            'updated_at' => $ahora,
        ]);

        return count($ids);
    }

    /**
     * Suma un intento y deja el motivo escrito. Los eventos siguen pendientes.
     *
     * @param \Illuminate\Support\Collection $pendientes
     * @param string $error
     * @param bool $definitivo Si el canal rechazo de forma definitiva, se agotan los intentos de una.
     * @return int
     */
    private static function marcar_fallados($pendientes, $error, $definitivo)
    {
        $ahora = Carbon::now();
        $ids = $pendientes->pluck('id')->all();

        if ($definitivo) {
            Log::error('DemoEventosPushHelper: el canal rechazo el lote de forma definitiva (' . $error . '). ' . count($ids) . ' evento(s) no se vuelven a mandar.');

            DemoEvento::whereIn('id', $ids)->update([
                'intentos' => self::MAX_INTENTOS,
                'ultimo_error' => $error,
                'updated_at' => $ahora,
            ]);

            return count($ids);
        }

        Log::warning('DemoEventosPushHelper: fallo el push de ' . count($ids) . ' evento(s): ' . $error);

        DemoEvento::whereIn('id', $ids)->update([
            'ultimo_error' => $error,
            'updated_at' => $ahora,
        ]);

        DemoEvento::whereIn('id', $ids)->increment('intentos');

        return count($ids);
    }

    /**
     * Cuenta los eventos que agotaron sus intentos y avisa UNA sola vez por corrida.
     *
     * @return int Total de eventos varados.
     */
    private static function avisar_varados()
    {
        $varados = DemoEvento::whereNull('sincronizado_at')
            ->where('intentos', '>=', self::MAX_INTENTOS)
            ->count();

        if ($varados > 0) {
            Log::error('DemoEventosPushHelper: hay ' . $varados . ' evento(s) de demo varados tras ' . self::MAX_INTENTOS . ' intentos. El canal con el admin no esta respondiendo.');
        }

        return $varados;
    }
}
