<?php

namespace App\Http\Controllers\Helpers;

use App\Models\OnlineConfiguration;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve, por cliente, que notificaciones por mail estan habilitadas (prompt 383).
 *
 * Reemplaza a la variable de entorno SEND_MAILS, que obligaba a entrar por SSH al servidor de cada
 * cliente para prender o apagar los mails. Ahora es una columna de online_configurations que el
 * comercio maneja desde la UI del ERP.
 *
 * Criterio de fallo: si no se puede resolver la configuracion (no existe la fila, explota la query),
 * se devuelve false. Es la opcion conservadora: preferimos no mandar un mail que mandarlo sin que el
 * cliente lo haya habilitado.
 */
class MailNotificationConfigHelper
{
    /**
     * Indica si el cliente tiene habilitado el aviso por mail de ingreso de stock.
     *
     * @param int|null $user_id Id del owner. Si es null se resuelve por UserHelper::userId().
     * @return bool
     */
    static function avisarIngresoStock($user_id = null) {
        try {
            // Si no vino un user_id explicito, se resuelve por el usuario autenticado.
            if (is_null($user_id)) {
                $user_id = UserHelper::userId();
            }

            // Configuracion online del cliente (una por owner).
            $configuration = OnlineConfiguration::where('user_id', $user_id)->first();

            if (is_null($configuration)) {
                Log::info('MailNotificationConfigHelper: sin online_configuration, no se envian avisos', [
                    'user_id' => $user_id,
                ]);
                return false;
            }

            return (bool) $configuration->avisar_ingreso_stock_por_mail;
        } catch (\Exception $e) {
            Log::error('MailNotificationConfigHelper: error resolviendo la config, no se envian avisos', [
                'user_id' => $user_id,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }
}
