<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Events\ImportStatusUpdated;
use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\User;
use App\Notifications\ImportStatusNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Único punto que marca una importación de artículos como fallida.
 *
 * Lo usan: el catch de ProcessArticleChunk::handle(), el failed() de ProcessArticleChunk y de
 * FinalizeArticleImport (prompt 502), y el watchdog imports:detectar-colgadas (prompt 503).
 *
 * Garantías:
 *  - Idempotente: si el ImportHistory ya está en 'fallo' o 'terminado', no hace nada.
 *  - No tira nunca: todo va envuelto en try/catch que solo loguea. Un handler de fallos que falla
 *    dejaría el import colgado, que es exactamente el bug que estamos matando.
 *  - Persiste el estado ANTES de notificar/limpiar cache: el estado fallido es lo que desbloquea al
 *    usuario; las notificaciones son secundarias.
 */
class ImportFailureHandler
{
    /**
     * Tope de caracteres del log técnico. LONGTEXT soporta 4 GB, pero un trace gigante no aporta:
     * conservamos la cola (lo último es lo más cercano al fallo real).
     */
    const MAX_TRACE_CHARS = 2000000;

    /**
     * Tope del motivo humano (columna error_message es TEXT, 65.535 bytes).
     */
    const MAX_MESSAGE_CHARS = 60000;

    /**
     * Marca una importación como fallida a partir de una excepción: arma el motivo humano y el log
     * técnico completo y delega en registrar().
     *
     * @param  int|null    $import_history_id
     * @param  int|null    $import_status_id
     * @param  int|null    $user_id
     * @param  \Throwable  $e
     * @param  int|null    $failed_chunk_number  Lote donde murió (null si no aplica).
     * @param  array       $rango_filas          ['start_row' => int, 'finish_row' => int] opcional.
     * @return void
     */
    public static function desde_excepcion($import_history_id, $import_status_id, $user_id, $e, $failed_chunk_number = null, $rango_filas = array())
    {
        $mensaje_humano = self::armar_mensaje_humano($e, $failed_chunk_number, $rango_filas);
        $trace_completo = self::armar_trace_completo($e, $failed_chunk_number, $rango_filas);

        self::registrar($import_history_id, $import_status_id, $user_id, $mensaje_humano, $trace_completo, $failed_chunk_number);
    }

    /**
     * Aplica el fallo (idempotente, no tira nunca). Punto de entrada de bajo nivel: el watchdog lo
     * llama directo con su propio mensaje humano y sin trace (un proceso caído no dejó stack).
     *
     * @param  int|null     $import_history_id
     * @param  int|null     $import_status_id
     * @param  int|null     $user_id
     * @param  string       $mensaje_humano
     * @param  string|null  $trace_completo
     * @param  int|null     $failed_chunk_number
     * @return void
     */
    public static function registrar($import_history_id, $import_status_id, $user_id, $mensaje_humano, $trace_completo = null, $failed_chunk_number = null)
    {
        try {

            $mensaje_humano = mb_substr((string) $mensaje_humano, 0, self::MAX_MESSAGE_CHARS);

            if (!is_null($trace_completo) && strlen($trace_completo) > self::MAX_TRACE_CHARS) {
                $trace_completo = "[...log recortado: superó " . self::MAX_TRACE_CHARS . " caracteres...]\n"
                    . substr($trace_completo, -self::MAX_TRACE_CHARS);
            }

            /* 1) ESTADO PRIMERO: es lo que desbloquea al usuario. Se recarga desde la BD para no arrastrar
                  ningún atributo sucio en memoria que pudiera ser la causa misma del fallo. */
            if (!is_null($import_history_id)) {

                $import_history = ImportHistory::find($import_history_id);

                if (!is_null($import_history)) {

                    /* Idempotencia: si ya está resuelto, no tocamos nada. */
                    if ($import_history->status === 'fallo' || $import_history->status === 'terminado') {
                        return;
                    }

                    $import_history->status              = 'fallo';
                    $import_history->error_message       = $mensaje_humano;
                    $import_history->error_trace         = $trace_completo;
                    $import_history->failed_chunk_number = $failed_chunk_number;
                    $import_history->failed_at           = Carbon::now();
                    $import_history->save();
                }
            }

            if (!is_null($import_status_id)) {

                $import_status = ImportStatus::find($import_status_id);

                if (!is_null($import_status) && $import_status->status !== 'fallo') {
                    $import_status->status        = 'fallo';
                    $import_status->error_message = $mensaje_humano;
                    $import_status->save();
                }
            }

            /* 2) Notificaciones (cada una aislada: si una falla, no frena a las demás ni al cache). */
            self::notificar($import_status_id, $user_id, $mensaje_humano, $failed_chunk_number);

            /* 3) Limpieza de cache del índice: un índice a medio construir no debe contaminar el reintento. */
            if (!is_null($user_id)) {
                try {
                    ArticleIndexCache::limpiar_cache($user_id);
                } catch (\Throwable $cache_error) {
                    Log::error('ImportFailureHandler: no se pudo limpiar ArticleIndexCache.', array(
                        'user_id' => $user_id,
                        'error'   => $cache_error->getMessage(),
                    ));
                }
            }

        } catch (\Throwable $handler_error) {
            /* El handler jamás propaga: si falla, al menos dejamos rastro en el log del sistema. */
            Log::error('ImportFailureHandler::registrar falló (el import puede haber quedado sin marcar).', array(
                'import_history_id' => $import_history_id,
                'import_status_id'  => $import_status_id,
                'error'             => $handler_error->getMessage(),
            ));
        }
    }

    /**
     * Notifica al usuario por los tres canales que ya usa el flujo actual.
     *
     * @param  int|null  $import_status_id
     * @param  int|null  $user_id
     * @param  string    $mensaje_humano
     * @param  int|null  $failed_chunk_number
     * @return void
     */
    private static function notificar($import_status_id, $user_id, $mensaje_humano, $failed_chunk_number)
    {
        $user = is_null($user_id) ? null : User::find($user_id);
        $import_status = is_null($import_status_id) ? null : ImportStatus::find($import_status_id);

        if (!is_null($user) && !is_null($import_status)) {
            try {
                $user->notify(new ImportStatusNotification($import_status, $user->id));
            } catch (\Throwable $e) {
                Log::error('ImportFailureHandler: falló notify ImportStatusNotification.', array('error' => $e->getMessage()));
            }
        }

        if (!is_null($import_status)) {
            try {
                broadcast(new ImportStatusUpdated($import_status->id, $user_id));
            } catch (\Throwable $e) {
                Log::error('ImportFailureHandler: falló broadcast ImportStatusUpdated.', array('error' => $e->getMessage()));
            }
        }

        if (!is_null($user)) {
            try {
                ArticleImportHelper::error_notification($user, $failed_chunk_number, $mensaje_humano);
            } catch (\Throwable $e) {
                Log::error('ImportFailureHandler: falló ArticleImportHelper::error_notification.', array('error' => $e->getMessage()));
            }
        }
    }

    /**
     * Motivo corto y legible: dónde falló y por qué.
     *
     * @param  \Throwable  $e
     * @param  int|null    $failed_chunk_number
     * @param  array       $rango_filas
     * @return string
     */
    private static function armar_mensaje_humano($e, $failed_chunk_number, $rango_filas)
    {
        $donde = '';

        if (!is_null($failed_chunk_number)) {
            $donde = 'Falló en el lote ' . $failed_chunk_number;

            if (isset($rango_filas['start_row']) && isset($rango_filas['finish_row'])) {
                $donde .= ' (filas ' . $rango_filas['start_row'] . '–' . $rango_filas['finish_row'] . ')';
            }

            $donde .= '. ';
        } else {
            $donde = 'La importación falló. ';
        }

        $archivo = basename($e->getFile()) . ':' . $e->getLine();

        return $donde . 'Motivo: ' . $e->getMessage() . ' [' . $archivo . ']';
    }

    /**
     * Log técnico completo para investigar: clase, mensaje, ubicación, cadena de causas, stack y un
     * snapshot de memoria (útil para diagnosticar OOM).
     *
     * @param  \Throwable  $e
     * @param  int|null    $failed_chunk_number
     * @param  array       $rango_filas
     * @return string
     */
    private static function armar_trace_completo($e, $failed_chunk_number, $rango_filas)
    {
        $out = '';
        $out .= '=== FALLO DE IMPORTACIÓN — ' . Carbon::now()->format('Y-m-d H:i:s') . " ===\n";

        if (!is_null($failed_chunk_number)) {
            $out .= 'Lote: ' . $failed_chunk_number;
            if (isset($rango_filas['start_row']) && isset($rango_filas['finish_row'])) {
                $out .= ' (filas ' . $rango_filas['start_row'] . '–' . $rango_filas['finish_row'] . ')';
            }
            $out .= "\n";
        }

        $out .= 'Memoria en uso: ' . self::mb(memory_get_usage(true))
              . ' MB — pico: ' . self::mb(memory_get_peak_usage(true))
              . ' MB — límite: ' . ini_get('memory_limit') . "\n\n";

        $actual = $e;
        $nivel = 0;

        while (!is_null($actual)) {
            $prefijo = $nivel === 0 ? 'Excepción' : 'Causada por';
            $out .= $prefijo . ': ' . get_class($actual) . "\n";
            $out .= 'Mensaje: ' . $actual->getMessage() . "\n";
            $out .= 'Ubicación: ' . $actual->getFile() . ':' . $actual->getLine() . "\n";
            $out .= "Stack:\n" . $actual->getTraceAsString() . "\n\n";

            $actual = $actual->getPrevious();
            $nivel++;
        }

        return $out;
    }

    /**
     * Bytes a MB con 2 decimales.
     *
     * @param  int  $bytes
     * @return string
     */
    private static function mb($bytes)
    {
        return number_format($bytes / 1048576, 2, '.', '');
    }
}
