<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UnmetDemandHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Endpoints de la demanda no satisfecha de Vender (misión demanda-sin-stock-vender).
 *
 * Mismo esquema que el resto de los controladores del proyecto (extiende Controller, devuelve
 * con fullModel); toda la lógica vive en UnmetDemandHelper.
 *
 * Contrato (la SPA ya está construida contra él):
 *  - POST unmet-demand: 201 fila nueva, 200 si se absorbió en un duplicado de los últimos
 *    minutos (deduplicado = true), 422 { message } si el artículo o el motivo son inválidos.
 *  - PUT unmet-demand/{id}/cancel: 200 con la fila cancelada, 404 { message } si no existe o es
 *    de otro dueño.
 *
 * No usa $request->validate(): la validación (pertenencia del artículo al dueño y motivo) es de
 * seguridad multi-tenant y está en el Helper, con el mensaje exacto que espera la SPA.
 */
class UnmetDemandController extends Controller
{
    /**
     * Registra un freno de Vender por falta de stock.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $auth_user = $this->authenticated_user($request);

            [$model, $deduplicado] = UnmetDemandHelper::registrar(
                $request->all(),
                $this->owner_id_of($auth_user),
                $auth_user->id
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'model'       => $this->fullModel('UnmetDemand', $model->id),
            'deduplicado' => $deduplicado,
        ], $deduplicado ? 200 : 201);
    }

    /**
     * Cancela un freno (el vendedor aclara que fue un error). No borra la fila.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(Request $request, $id)
    {
        $model = UnmetDemandHelper::cancelar((int) $id, $this->owner_id_of($this->authenticated_user($request)));

        if (is_null($model)) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json(['model' => $this->fullModel('UnmetDemand', $model->id)], 200);
    }

    /**
     * Usuario autenticado de este request.
     *
     * Se lee primero del guard `web` (el mismo que Sanctum consulta primero para la sesión de la
     * SPA) y recién después de `$request->user()` (tokens). En producción da el mismo usuario que
     * Auth::user(); la diferencia es que el guard `sanctum` cachea el primer usuario que resolvió
     * dentro del proceso, y en un proceso que atiende varios requests (los tests de feature, que
     * cambian de usuario con actingAs) devolvería siempre al primero.
     *
     * @param Request $request
     * @return \App\Models\User|null
     */
    protected function authenticated_user(Request $request)
    {
        $web_user = Auth::guard('web')->user();

        return $web_user ?: $request->user();
    }

    /**
     * Id del dueño del comercio de un usuario: su `owner_id` si es empleado, o su propio id.
     *
     * @param \App\Models\User $user
     * @return int
     */
    protected function owner_id_of($user): int
    {
        return (int) ($user->owner_id ?: $user->id);
    }
}
