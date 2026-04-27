<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\SupportSyncHelper;
use App\Models\SupportMessage;
use Illuminate\Console\Command;

class SupportRetryPendingSyncs extends Command
{
    /**
     * Firma del comando para reintentar sync pendiente hacia admin-api.
     *
     * @var string
     */
    protected $signature = 'support:retry-pending-syncs';

    /**
     * Descripción para listado de comandos artisan.
     *
     * @var string
     */
    protected $description = 'Reintenta sincronizar mensajes de soporte pendientes hacia admin-api';

    /**
     * Ejecuta reintentos sobre mensajes con synced_to_admin_at en null.
     */
    public function handle()
    {
        // Mensajes que aún no pudieron sincronizarse al admin-api central.
        $pending_messages = SupportMessage::whereNull('synced_to_admin_at')->orderBy('id')->get();
        // Contador de mensajes sincronizados exitosamente en esta corrida.
        $synced_count = 0;

        foreach ($pending_messages as $message) {
            // Ejecuta sync best-effort para cada mensaje pendiente.
            $ok = SupportSyncHelper::sync_message_to_admin($message);
            if ($ok) {
                $synced_count++;
            }
        }

        $this->info('Support pending syncs processed: ' . $pending_messages->count() . ' | synced: ' . $synced_count);
        return 0;
    }
}

