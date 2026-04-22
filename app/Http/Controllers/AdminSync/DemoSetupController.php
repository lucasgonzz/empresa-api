<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint llamado por admin-api (header X-Admin-Api-Key) cuando desde el
 * panel de Leads se pulsa "Disparar setup demo".
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
     * @param Request $request
     */
    public function store(Request $request)
    {
        // business_type es el único valor obligatorio del flujo original
        if (empty($request->input('business_type'))) {
            return response()->json(['error' => 'business_type is required'], 422);
        }

        try {
            $user = DemoSetupHelper::run($request->all());
        } catch (\Throwable $e) {
            Log::error('AdminSync demo-setup: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'internal error: ' . $e->getMessage()], 500);
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
