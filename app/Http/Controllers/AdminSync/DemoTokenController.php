<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DemoIngresoTokenHelper;
use App\Models\DemoIngresoToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint llamado por admin-api cuando desde el panel del lead se reemite o
 * revoca el token de ingreso directo a la demo (sesion ya iniciada, sin
 * credenciales). Puente entre admin-api (dueno del token en claro) y esta
 * instancia (dueno solo del hash, via DemoIngresoTokenHelper).
 *
 * Ruta protegida por 'admin.api.key' (ver routes/api.php, grupo admin-sync),
 * aunque el middleware hoy esta desactivado por
 * services.admin_api.require_api_key = false (decision de Lucas, 27/7/2026).
 */
class DemoTokenController extends Controller
{
    /**
     * Procesa la accion 'guardar' (reemision) o 'revocar' sobre el token de
     * ingreso a la demo del usuario de esta instancia.
     *
     * - 'guardar': requiere `token` (y opcionalmente `expira_at`). Delega en
     *   DemoIngresoTokenHelper::guardar(), que ya borra los tokens previos del
     *   mismo usuario antes de crear el nuevo, asi que reemitir invalida el
     *   token anterior automaticamente.
     * - 'revocar': revoca TODOS los tokens vigentes del usuario demo de esta
     *   instancia (no se revoca por token puntual, porque hay un solo usuario
     *   demo por instancia).
     *
     * @param Request $request Body esperado: { accion: 'guardar'|'revocar', token?, expira_at? }
     *
     * @return \Illuminate\Http\JsonResponse ['ok' => true] en 200, o ['error' => ...] en 422/500
     */
    public function store(Request $request)
    {
        // Accion solicitada por admin-api: 'guardar' (reemitir) o 'revocar'.
        $accion = $request->input('accion');

        try {
            if ($accion === 'guardar') {
                // Token en claro recien emitido por admin-api; obligatorio para esta accion.
                $token = $request->input('token');
                if (empty($token)) {
                    return response()->json(['error' => 'token is required for accion=guardar'], 422);
                }

                // Fecha de expiracion opcional (string parseable por Carbon); el helper
                // ya resuelve el fallback de 4 horas si viene vacia o invalida.
                $expira_at = $request->input('expira_at');

                // guardar() borra los tokens previos del usuario antes de crear el nuevo:
                // reemitir invalida el anterior en el mismo paso, sin dejar dos vigentes.
                DemoIngresoTokenHelper::guardar($token, config('app.USER_ID'), $expira_at);

                return response()->json(['ok' => true], 200);
            }

            if ($accion === 'revocar') {
                // Revocar por lead, no por token puntual: hay un solo usuario demo por
                // instancia, asi que se revocan todos los tokens vigentes de una vez.
                DemoIngresoToken::whereNull('revoked_at')->update(['revoked_at' => now()]);

                return response()->json(['ok' => true], 200);
            }

            return response()->json(['error' => 'accion must be "guardar" or "revocar"'], 422);
        } catch (\Throwable $e) {
            Log::error('AdminSync demo-token: ' . $e->getMessage(), [
                'accion' => $accion,
                'trace'  => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'internal error: ' . $e->getMessage()], 500);
        }
    }
}
