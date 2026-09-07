<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Actualiza default_version (URL del SPA) y api_url en los usuarios de la instancia,
 * tras un deployment que cambia la API/SPA destino (admin-api).
 *
 * 🔴 La escritura se acota al comercio de la instancia (config app.USER_ID). Hay bases que
 * comparten varios comercios: en `u767360347_empresa` conviven Fenix, Galvan, HiperMax,
 * El Kiosco Verde y Candyguay, cada uno servido por su propia carpeta con su propio USER_ID.
 * Sin ese filtro, el upgrade de un comercio reescribia los 113 usuarios de la base y mandaba
 * a los demas al frente del que se acababa de actualizar (medido el 7/9/2026 con el upgrade
 * de Fenix a 4.0.16).
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
        }

        // Normalizacion centralizada e idempotente en ApiUrlHelper (grupo 237, prompt 01): cubre
        // las dos ramas de arriba (derivada del spa_url o explicita por request) y ademas corrige
        // valores que ya vinieran con "/public" duplicado, en vez de solo evitar generar nuevos.
        $api_url = ApiUrlHelper::canonical_public_url($api_url);

        // Comercio dueño de esta instancia. Es el mismo valor que ya usan los comandos de consola
        // para saber sobre que comercio operan (config app.USER_ID, que sale del USER_ID del .env).
        $owner_id = (int) config('app.USER_ID');

        $query = User::query();

        if ($owner_id > 0) {
            // El dueño y sus empleados, nada mas. En una base de un solo comercio esto abarca a
            // todos igual; en una compartida es lo que impide pisar a los otros comercios.
            $query->where(function ($sub_query) use ($owner_id) {
                $sub_query->where('id', $owner_id)
                    ->orWhere('owner_id', $owner_id);
            });
        }

        try {
            $updated = $query->update([
                'default_version' => $spa_url,
                'api_url'         => $api_url,
                'session_id'      => null,
                'last_activity'   => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminSync update-default-version: ' . $e->getMessage());

            return response()->json(['error' => 'internal error'], 500);
        }

        // Se loguea el alcance para que el log del deploy muestre si la escritura quedo acotada al
        // comercio o si corrio sobre toda la base por falta de USER_ID en el .env.
        Log::info('AdminSync update-default-version OK', [
            'spa_url'  => $spa_url,
            'api_url'  => $api_url,
            'owner_id' => $owner_id > 0 ? $owner_id : null,
            'users'    => $updated,
        ]);

        return response()->json([
            'ok'              => true,
            'default_version' => $spa_url,
            'api_url'         => $api_url,
            'owner_id'        => $owner_id > 0 ? $owner_id : null,
            'users_updated'   => $updated,
        ], 200);
    }
}
