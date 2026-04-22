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

        if (empty($admin_url) || empty($outbound_key) || empty($client_uuid)) {
            Log::warning('AdminReportHelper: admin_api config incompleta, omitiendo report.');
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
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $outbound_key,
                    'Accept' => 'application/json',
                ])
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
