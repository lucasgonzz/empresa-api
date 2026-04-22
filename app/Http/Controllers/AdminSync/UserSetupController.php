<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserSetupHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint llamado por admin-api (header X-Admin-Api-Key) cuando desde el
 * panel de Leads se pulsa "Crear sistema real" sobre un Lead ya promovido.
 *
 * Equivale al POST del formulario legacy user/setup pero recibe JSON y
 * responde JSON para que admin-api registre el resultado en el Lead.
 */
class UserSetupController extends Controller
{
    /**
     * Ejecuta UserSetupHelper::run con el payload recibido.
     *
     * Requiere como mínimo `business_type` y `user_id`. El resto de campos
     * son opcionales y se interpretan como flags o datos complementarios.
     *
     * @param Request $request
     */
    public function store(Request $request)
    {
        // Precondiciones mínimas requeridas por UserSetupHelper::create_user
        if (empty($request->input('business_type'))) {
            return response()->json(['error' => 'business_type is required'], 422);
        }
        if (empty($request->input('user_id'))) {
            return response()->json(['error' => 'user_id is required'], 422);
        }

        try {
            $user = UserSetupHelper::run($request->all());
        } catch (\Throwable $e) {
            Log::error('AdminSync user-setup: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'internal error: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'ok' => true,
            'user' => [
                'id'           => $user->id,
                'name'         => $user->name,
                'company_name' => $user->company_name,
            ],
        ], 200);
    }
}
