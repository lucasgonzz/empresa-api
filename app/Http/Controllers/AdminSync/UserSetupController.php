<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint llamado por admin-api cuando desde el panel de Leads se ejecuta
 * user setup (sin autenticación por API key en esta ruta).
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
     * El 409 es una respuesta NUEVA de este endpoint (25/8/2026), compatible hacia atrás:
     * un admin-api viejo la lee como no exitosa y registra el error en el Lead, que es lo
     * correcto. Lo importante es que en ese caso la base NO se toca.
     *
     * @param Request $request
     */
    public function store(Request $request)
    {
        // Precondiciones mínimas requeridas por UserSetupHelper::create_user
        // if (empty($request->input('business_type'))) {
        //     return response()->json(['error' => 'business_type is required'], 422);
        // }
        if (empty($request->input('user_id'))) {
            return response()->json(['error' => 'user_id is required'], 422);
        }

        /**
         * Tercera puerta al mismo `migrate:fresh`, mismo candado que las dos de demo (ver
         * DemoSetupLockHelper, que las lista por nombre). El caso realista no es "dos
         * user-setup a la vez": es la conversión de Lead a Cliente disparada mientras la demo
         * de ese mismo lead todavía está sembrando. Ahí el `migrate:fresh` de acá le vacía la
         * base a la corrida de demo y sale el mismo SQLSTATE[42S02] que motivó todo esto.
         */
        $candado = DemoSetupLockHelper::tomar();

        if ($candado === false) {
            return response()->json([
                'error' => 'Ya hay un setup corriendo en esta instancia. Esperá a que termine.',
                'en_curso' => true,
            ], 409);
        }

        try {
            $user = UserSetupHelper::run($request->all());
        } catch (\Throwable $e) {
            Log::error('AdminSync user-setup: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'internal error: ' . $e->getMessage()], 500);
        } finally {
            DemoSetupLockHelper::soltar($candado);
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
