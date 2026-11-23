<?php

namespace App\Http\Controllers\Helpers;

use App\Models\SyncedVersion;
use App\Models\SyncedVersionNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminSyncHelper
{
    /**
     * Aplica una versión publicada por el admin-api al almacenamiento local.
     * Idempotente por uuid: se puede re-publicar la misma versión sin duplicar
     * ni resetear lecturas (synced_version_notification_reads queda intacto).
     * Además propaga el texto de versión a todos los usuarios (users.version_name).
     *
     * @param array $versionPayload ['uuid','version','title','description','published_at']
     * @param array $notificationsPayload [['uuid','title','body','sort_order','is_active'], ...]
     * @return SyncedVersion
     */
    public static function apply_published_version(array $versionPayload, array $notificationsPayload): SyncedVersion
    {
        // Se guarda en una variable para registrar la versión instalada recién después del commit.
        $result = DB::transaction(function () use ($versionPayload, $notificationsPayload) {
            $synced_version = SyncedVersion::updateOrCreate(
                ['uuid' => $versionPayload['uuid']],
                [
                    'version' => $versionPayload['version'] ?? '',
                    'title' => $versionPayload['title'] ?? null,
                    'description' => $versionPayload['description'] ?? null,
                    'published_at' => !empty($versionPayload['published_at'])
                        ? Carbon::parse($versionPayload['published_at'])
                        : null,
                    'is_current' => true,
                ]
            );

            // solo esta versión es current
            SyncedVersion::where('id', '!=', $synced_version->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            foreach ($notificationsPayload as $item) {
                if (empty($item['uuid'])) {
                    continue;
                }
                SyncedVersionNotification::updateOrCreate(
                    ['admin_uuid' => $item['uuid']],
                    [
                        'synced_version_id' => $synced_version->id,
                        'title' => $item['title'] ?? '',
                        'body' => $item['body'] ?? '',
                        'sort_order' => (int) ($item['sort_order'] ?? 0),
                        'is_active' => (bool) ($item['is_active'] ?? true),
                    ]
                );
            }

            $versionLabel = $versionPayload['version'] ?? null;
            if ($versionLabel !== null && $versionLabel !== '') {
                User::query()->update(['version_name' => (string) $versionLabel]);
            }

            return $synced_version->fresh('notifications');
        });

        // Registro en installed_versions (en try/catch adentro del helper: no cambia la respuesta).
        InstalledVersionHelper::register_from_publication($result);

        return $result;
    }
}

