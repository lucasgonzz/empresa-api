<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\AdminSyncHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublishVersionController extends Controller
{
    public function store(Request $request)
    {
        $version = $request->input('version');
        $notifications = $request->input('notifications', []);

        if (empty($version) || empty($version['uuid'])) {
            return response()->json(['error' => 'version.uuid is required'], 422);
        }

        try {
            $synced_version = AdminSyncHelper::apply_published_version(
                $version,
                is_array($notifications) ? $notifications : []
            );
        } catch (\Throwable $e) {
            Log::error('AdminSync publish-version: ' . $e->getMessage());
            return response()->json(['error' => 'internal error'], 500);
        }

        return response()->json([
            'ok' => true,
            'synced_version' => [
                'uuid' => $synced_version->uuid,
                'version' => $synced_version->version,
                'notifications_count' => $synced_version->notifications->count(),
            ],
        ], 200);
    }
}
