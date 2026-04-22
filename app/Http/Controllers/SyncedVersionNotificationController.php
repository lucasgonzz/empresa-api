<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AdminReportHelper;
use App\Models\SyncedVersionNotification;
use App\Models\SyncedVersionNotificationRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncedVersionNotificationController extends Controller
{
    public function pending(Request $request)
    {
        $user_id = $this->userId(false);
        if (is_null($user_id)) {
            return response()->json(['models' => []], 200);
        }

        $models = SyncedVersionNotification::whereHas('synced_version', function ($query) {
                $query->where('is_current', true);
            })
            ->where('is_active', true)
            ->whereNotIn('id', function ($query) use ($user_id) {
                $query->select('synced_version_notification_id')
                    ->from('synced_version_notification_reads')
                    ->where('user_id', $user_id);
            })
            ->orderBy('sort_order')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    public function markRead($id)
    {
        $user_id = $this->userId(false);
        if (is_null($user_id)) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $notification = SyncedVersionNotification::findOrFail($id);

        $read = DB::transaction(function () use ($notification, $user_id) {
            $existing = SyncedVersionNotificationRead::where('synced_version_notification_id', $notification->id)
                ->where('user_id', $user_id)
                ->first();
            if (!is_null($existing)) {
                return $existing;
            }
            return SyncedVersionNotificationRead::create([
                'synced_version_notification_id' => $notification->id,
                'user_id' => $user_id,
                'read_at' => now(),
            ]);
        });

        // Reportar al admin-api. Si falla queda synced_to_admin_at=null para reintento.
        AdminReportHelper::report_read($read);

        return response()->json([
            'ok' => true,
            'synced_version_notification_read_id' => $read->id,
        ], 200);
    }
}
