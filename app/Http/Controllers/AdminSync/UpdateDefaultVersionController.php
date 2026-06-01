<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Actualiza default_version (URL del SPA) y api_url en todos los usuarios de la instancia,
 * tras un deployment que cambia la API/SPA destino (admin-api).
 */
class UpdateDefaultVersionController extends Controller
{
    /**
     * Aplica la nueva URL del SPA y del API en users (libera sesiones para re-login).
     *
     * @param  Request  $request  spa_url|default_version, api_url (opcional)
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $spa_url = trim((string) ($request->input('spa_url') ?: $request->input('default_version')));
        $api_url = trim((string) $request->input('api_url'));

        if ($spa_url === '') {
            return response()->json(['error' => 'spa_url or default_version is required'], 422);
        }

        if ($api_url === '') {
            $api_url = str_replace('https://', 'https://api-', $spa_url);
            if (! config('app.VPS') && config('app.APP_ENV') === 'production') {
                $api_url .= '/public';
            }
        }

        try {
            $updated = User::query()->update([
                'default_version' => $spa_url,
                'api_url'         => $api_url,
                'session_id'      => null,
                'last_activity'   => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminSync update-default-version: ' . $e->getMessage());

            return response()->json(['error' => 'internal error'], 500);
        }

        Log::info('AdminSync update-default-version OK', [
            'spa_url' => $spa_url,
            'api_url' => $api_url,
            'users'   => $updated,
        ]);

        return response()->json([
            'ok'              => true,
            'default_version' => $spa_url,
            'api_url'         => $api_url,
            'users_updated'   => $updated,
        ], 200);
    }
}
