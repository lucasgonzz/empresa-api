<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalAccesoHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalAplicarHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalLecturaHelper;
use Illuminate\Http\Request;

/**
 * Elegir un sistema de categorías y volver atrás (misión categorizacion-tres-modelos, 5/10/2026).
 * Contrato B del plan, §6.4 y §6.5. Solo para el dueño o el acceso maestro (403 `solo_el_dueno`).
 *
 * Solo traduce a HTTP: la lógica vive en CategoryProposalAplicarHelper. Todo acotado por el DUEÑO de la
 * sesión (`$this->userId()`): un id de otro comercio responde 404 igual que uno inexistente. Los errores
 * salen como `{"error": "<codigo>", "message": "<texto para mostrar>"}`; el interceptor de la SPA muestra
 * el `message` de un 4xx, así que acá no hay nada que repetir.
 */
class CategoryProposalEleccionController extends Controller
{
    /**
     * POST category-proposal-runs/{id}/elegir {propuesta_id, eliminar_categorias_vacias?} — aplica el
     * sistema elegido (sincrónico, una transacción). Elegir dos veces la misma propuesta responde 200 con
     * `"ya_estaba": true` y no reaplica.
     *
     * Errores: 403 `solo_el_dueno`, 404 `no_encontrado`, 409 `no_esta_lista` / `ya_hay_una_elegida`,
     * 422 `bloqueado_por_margenes` / `bloqueado_por_tienda_nube` (con `motivos`) / `validacion`.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id  La corrida.
     * @return \Illuminate\Http\JsonResponse  {ok, run, resultado, ya_estaba?}
     */
    public function elegir(Request $request, $id)
    {
        if (!CategoryProposalAccesoHelper::puede_gestionar()) {

            return $this->responder_error(CategoryProposalAplicarHelper::solo_el_dueno());
        }

        $resultado = CategoryProposalAplicarHelper::elegir(
            $this->userId(),
            (int) $id,
            $request->input('propuesta_id'),
            $request->input('eliminar_categorias_vacias'),
            $this->userId(false),
            ImageAssignmentRunHelper::es_acceso_maestro()
        );

        if ($resultado['status'] !== 200) {

            return $this->responder_error($resultado);
        }

        $cuerpo = [
            'ok'        => true,
            'run'       => CategoryProposalLecturaHelper::run_payload($resultado['run']),
            'resultado' => $resultado['resultado'],
        ];

        if (!empty($resultado['ya_estaba'])) {
            $cuerpo['ya_estaba'] = true;
        }

        return response()->json($cuerpo, 200);
    }

    /**
     * POST category-proposal-runs/{id}/volver-atras — deshace la elección si nadie tocó nada (regla b).
     * Si la corrida ya está `lista` (el segundo clic) responde 200 con `"ya_estaba": true`.
     *
     * Errores: 403 `solo_el_dueno`, 404 `no_encontrado`, 409 `no_se_puede_volver_atras` con `motivo`
     * (`no_esta_elegida` | `hay_revisiones` | `articulos_editados` | `categorias_editadas`).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id  La corrida.
     * @return \Illuminate\Http\JsonResponse  {ok, run, ya_estaba?}
     */
    public function volver_atras(Request $request, $id)
    {
        if (!CategoryProposalAccesoHelper::puede_gestionar()) {

            return $this->responder_error(CategoryProposalAplicarHelper::solo_el_dueno());
        }

        $resultado = CategoryProposalAplicarHelper::volver_atras($this->userId(), (int) $id);

        if ($resultado['status'] !== 200) {

            return $this->responder_error($resultado);
        }

        $cuerpo = [
            'ok'  => true,
            'run' => CategoryProposalLecturaHelper::run_payload($resultado['run']),
        ];

        if (!empty($resultado['ya_estaba'])) {
            $cuerpo['ya_estaba'] = true;
        }

        return response()->json($cuerpo, 200);
    }

    /**
     * Traduce un error del helper (`status`, `error`, `message` y lo que se le sume) a la respuesta HTTP.
     *
     * @param  array $resultado
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder_error(array $resultado)
    {
        return response()->json(CategoryProposalAplicarHelper::cuerpo_del_error($resultado), $resultado['status']);
    }
}
