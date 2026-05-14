<?php

namespace App\Http\Controllers\Helpers;

use App\Models\SyncedVersionNotificationRead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdminReportHelper
{
    /**
     * Reporta al admin-api central que un usuario del cliente leyó una notificación.
     * Si el reporte es exitoso, sella synced_to_admin_at; si falla, deja null para
     * reintento posterior (no rompe el flujo del SPA).
     */
    public static function report_read(SyncedVersionNotificationRead $read): bool
    {
        $admin_url = config('services.admin_api.url');
        $outbound_key = config('services.admin_api.inbound_key');
        $client_uuid = config('services.admin_api.client_uuid');
        $require_api_key = (bool) config('services.admin_api.require_api_key', false);

        if (empty($admin_url) || empty($client_uuid)) {
            Log::warning('AdminReportHelper: admin_api config incompleta, omitiendo report.');
            return false;
        }
        if ($require_api_key && empty($outbound_key)) {
            Log::warning('AdminReportHelper: falta inbound_key con ADMIN_SYNC_REQUIRE_API_KEY activo.');
            return false;
        }

        $read->loadMissing('synced_version_notification', 'user');

        if (is_null($read->synced_version_notification)) {
            Log::warning('AdminReportHelper: synced_version_notification no encontrada', ['read_id' => $read->id]);
            return false;
        }

        $payload = [
            'client_uuid' => $client_uuid,
            'client_user_id' => $read->user_id,
            'client_user_name' => optional($read->user)->name,
            'client_user_email' => optional($read->user)->email,
            'notification_admin_uuid' => $read->synced_version_notification->admin_uuid,
            'read_at' => optional($read->read_at)->toIso8601String(),
        ];

        try {
            /**
             * Headers inbound: clave sólo si está definida (compatibilidad al reactivar middleware en admin-api).
             */
            $headers = ['Accept' => 'application/json'];
            if (! empty($outbound_key)) {
                $headers['X-Admin-Api-Key'] = $outbound_key;
            }

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->post(rtrim($admin_url, '/') . '/api/inbound/notification-reads', $payload);

            if ($response->successful()) {
                $read->synced_to_admin_at = now();
                $read->save();
                return true;
            }

            Log::warning('AdminReportHelper: status ' . $response->status() . ' body ' . $response->body());
            return false;
        } catch (\Throwable $e) {
            Log::warning('AdminReportHelper exception: ' . $e->getMessage());
            return false;
        }
    }
}
