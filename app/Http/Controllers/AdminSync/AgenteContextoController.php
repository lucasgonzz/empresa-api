<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\AdminSync\Concerns\ClaveEstrictaDeAdmin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El admin deja creada la memoria del agente cuando prende «Agentes de IA» (misión app-ia-y-memoria,
 * 29/10/2026, contrato C1.3 del plan):
 *
 *   PUT api/admin-sync/agente-contexto/inicializar   → 200 {creada: bool, archivos: int}
 *
 * Idempotente: con la memoria ya creada contesta `creada: false` y no escribe nada. La memoria se crea
 * sola también al armar el prompt (perezosa): esto es para que quede lista apenas se activa.
 *
 * 🔴 La clave `X-Admin-Api-Key` se exige SIEMPRE, adentro del controlador (trait ClaveEstrictaDeAdmin),
 * aunque ADMIN_SYNC_REQUIRE_API_KEY esté apagado: esta ruta escribe en la base del cliente.
 *
 * Errores, todos con `{code, message}` (el 401 lleva además `error: unauthorized`, como el precedente):
 *   401 `clave_invalida` · 409 `sin_dueno` (sin USER_ID en una base compartida) · 409 `sin_extension`.
 * Un cliente viejo sin esta ruta responde 404, que el admin ignora con log.
 */
class AgenteContextoController extends Controller
{
    use ClaveEstrictaDeAdmin;

    /**
     * PUT api/admin-sync/agente-contexto/inicializar.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function inicializar(Request $request): JsonResponse
    {
        if (!$this->clave_valida($request)) {

            return response()->json([
                'code'    => 'clave_invalida',
                'error'   => 'unauthorized',
                'message' => 'Falta la clave del admin o no es la de este cliente.',
            ], 401);
        }

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return response()->json([
                'code'    => 'sin_dueno',
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409);
        }

        if (!AgentesIaHelper::tiene($dueno)) {

            return response()->json([
                'code'    => 'sin_extension',
                'message' => 'Este comercio no tiene activa la extensión Agentes de IA.',
            ], 409);
        }

        $resultado = AgenteContextoHelper::asegurar($dueno);

        return response()->json([
            'creada'   => (bool) $resultado['creada'],
            'archivos' => (int) $resultado['archivos'],
        ], 200);
    }
}
