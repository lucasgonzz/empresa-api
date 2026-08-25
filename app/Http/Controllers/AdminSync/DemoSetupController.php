<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint llamado por admin-api cuando desde el panel de Leads se pulsa
 * "Disparar setup demo" (sin autenticación por API key en esta ruta).
 *
 * Replica el mismo comportamiento del form legacy en /demo/setup pero
 * entregando el input como JSON y respondiendo status JSON para que el
 * admin-api pueda reflejar éxito/error en el Lead.
 */
class DemoSetupController extends Controller
{
    /**
     * Ejecuta DemoSetupHelper::run con el payload recibido.
     *
     * Requiere como mínimo `business_type`; el resto de campos son opcionales
     * y se interpretan como flags o datos complementarios.
     *
     * El payload se pasa entero con $request->all(), así que las claves nuevas de la
     * misión 50 (`demo_eventos_token`, `demo_eventos_url`, `demo_plan`,
     * `demo_media_urls`) llegan al helper sin necesitar ninguna lista acá. Se dejan
     * nombradas igual porque este docblock es lo único que documenta el contrato del
     * endpoint: las cuatro son opcionales y las persiste DemoSetupHelper::run() al final.
     *
     * El 409 es una respuesta NUEVA de este endpoint (25/8/2026) y es compatible hacia
     * atrás: un admin-api viejo lo lee como `!successful()` y marca el Lead como fallido
     * con el mensaje, que es exactamente lo que queremos — lo importante es que la base
     * NO se toca. 200 y 500 quedan igual que siempre, mismo body.
     *
     * @param Request $request
     */
    public function store(Request $request)
    {
        // business_type es el único valor obligatorio del flujo original
        // if (empty($request->input('business_type'))) {
        //     return response()->json(['error' => 'business_type is required'], 422);
        // }

        /**
         * El candado va ANTES de cualquier cosa que toque la base, porque lo primero que
         * hace run() es `migrate:fresh`: si dejamos entrar una segunda corrida, le vacía
         * la base a la primera a mitad de siembra. Ver DemoSetupLockHelper para el porqué
         * del archivo (y por qué no Cache::lock).
         */
        $candado = DemoSetupLockHelper::tomar();

        if ($candado === false) {
            return response()->json([
                'error' => 'Ya hay un demo setup corriendo en esta instancia. Esperá a que termine.',
                'en_curso' => true,
            ], 409);
        }

        try {
            $user = DemoSetupHelper::run($request->all());
        } catch (\Throwable $e) {
            Log::error('AdminSync demo-setup: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'internal error: ' . $e->getMessage()], 500);
        } finally {
            DemoSetupLockHelper::soltar($candado);
        }

        return response()->json([
            'ok' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'company_name' => $user->company_name,
            ],
        ], 200);
    }
}
