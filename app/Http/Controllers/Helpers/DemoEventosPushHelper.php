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

            if ($respuesta['ok']) {
                $resumen['sincronizados'] = self::marcar_sincronizados($pendientes);

                return $resumen;
            }

            $resumen['fallados'] = self::marcar_fallados($pendientes, $respuesta['error'], $respuesta['definitivo']);

            return $resumen;
        } catch (\Throwable $e) {
            Log::warning('DemoEventosPushHelper: fallo el flush: ' . $e->getMessage());

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
            /** Error de red o timeout: el canal puede volver, se reintenta en el proximo flush. */
            return ['ok' => false, 'error' => 'excepcion: ' . $e->getMessage(), 'definitivo' => false];
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

        return [
            'ok' => false,
            'error' => 'status ' . $status . ': ' . substr((string) $respuesta->body(), 0, 500),
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
