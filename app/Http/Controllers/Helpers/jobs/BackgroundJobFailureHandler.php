<?php

namespace App\Http\Controllers\Helpers\jobs;

use App\Http\Controllers\Helpers\ExportHistoryHelper;
use App\Models\ExportHistory;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Log;

/**
 * Punto único e idempotente para marcar una exportación en segundo plano como fallida y avisar al usuario.
 *
 * Se llama desde dos lugares del ciclo de vida del job y NO debe duplicar efectos:
 *  - Desde el catch(\Throwable) del handle(): fallo "normal" atrapable.
 *  - Desde el failed() del job: red de seguridad para muertes que no pasan por el catch (OOM, kill del
 *    LVE, worker reiniciado → MaxAttemptsExceededException que lanza el Worker, corriendo en un proceso
 *    fresco). Al correr después del catch, ve el status ya en 'failed' y no re-notifica.
 */
class BackgroundJobFailureHandler
{
    /**
     * Marca la exportación como fallida (si no lo estaba ya) y notifica al owner una sola vez.
     *
     * Idempotente: si el ExportHistory ya está en 'failed' o 'completed', no hace nada (evita doble
     * notificación entre el catch y el failed()).
     *
     * @param  int         $export_history_id  Id del registro de historial de exportación a marcar.
     * @param  int         $owner_user_id       Id del usuario owner que recibe la notificación final.
     * @param  int         $auth_user_id        Usuario autenticado que pidió la exportación (para la notif dirigida).
     * @param  string      $mensaje_usuario     Texto legible para el usuario ('No se pudo generar el excel de ...').
     * @param  string|null $motivo_tecnico      Detalle técnico que se guarda en el historial (getMessage()).
     * @return void
     */
    public static function marcar_export_fallido($export_history_id, $owner_user_id, $auth_user_id, $mensaje_usuario, $motivo_tecnico = null)
    {
        // Busca el registro de historial: si no existe, no hay nada que marcar (solo se deja rastro en el log).
        $export_history = ExportHistory::find($export_history_id);

        if (is_null($export_history)) {
            Log::warning('BackgroundJobFailureHandler: export_history no encontrado', array(
                'export_history_id' => $export_history_id,
            ));
            return;
        }

        // Idempotencia: ya resuelto (fallido o completado) => no re-marcar ni re-notificar.
        // Esto evita que el catch() del handle() y el failed() posterior dupliquen el efecto.
        if ($export_history->status === 'failed' || $export_history->status === 'completed') {
            return;
        }

        // Si no vino un motivo técnico puntual (por ejemplo, cuando lo dispara failed() sin excepción
        // capturable), se deja un mensaje genérico que explica el escenario típico de muerte silenciosa.
        $motivo = is_null($motivo_tecnico) || $motivo_tecnico === ''
            ? 'El proceso de exportación se interrumpió sin dejar traza (probable falta de memoria, timeout del proceso o worker reiniciado).'
            : $motivo_tecnico;

        // Persiste el estado 'failed' junto con el motivo técnico en export_history.
        ExportHistoryHelper::mark_failed($export_history, $motivo);

        Log::warning('BackgroundJobFailureHandler: exportación marcada como fallida.', array(
            'export_history_id' => $export_history_id,
            'owner_user_id'     => $owner_user_id,
            'motivo'            => $motivo,
        ));

        // Notificación al owner (best effort): dirigida al usuario que la pidió.
        $owner_user = User::find($owner_user_id);
        if (is_null($owner_user)) {
            return;
        }

        // Botón único de confirmación para el modal de notificación en el SPA.
        $functions_to_execute = array(
            array(
                'btn_text'    => 'Entendido',
                'btn_variant' => 'primary',
            ),
        );

        $owner_user->notify(new GlobalNotification(array(
            'message_text'          => $mensaje_usuario,
            'color_variant'         => 'danger',
            'functions_to_execute'  => $functions_to_execute,
            'info_to_show'          => array(),
            'owner_id'              => $owner_user->id,
            'is_only_for_auth_user' => $auth_user_id,
        )));
    }
}
