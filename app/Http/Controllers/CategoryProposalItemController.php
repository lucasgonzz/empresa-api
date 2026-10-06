<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\category_proposal\CategoryProposalAccesoHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalAplicarHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalLecturaHelper;
use Illuminate\Http\Request;

/**
 * Aprobar o rechazar los ítems dudosos de la propuesta elegida (misión categorizacion-tres-modelos,
 * 5/10/2026). Contrato B del plan, §6.7. Solo para el dueño o el acceso maestro (403 `solo_el_dueno`).
 *
 * Solo traduce a HTTP: la lógica vive en CategoryProposalAplicarHelper. Todo acotado por el DUEÑO de la
 * sesión (`$this->userId()`): un ítem de otro comercio responde 404 igual que uno inexistente; en un
 * lote, el ajeno se omite y se cuenta junto con los que están fuera de estado, sin distinguirlos.
 *
 * Errores: `{"error": "<codigo>", "message": "<texto para mostrar>"}`. El interceptor de la SPA muestra
 * el `message` de un 4xx, así que acá no hay nada que repetir.
 */
class CategoryProposalItemController extends Controller
{
    /**
     * POST category-proposal-items/{id}/aprobar — el artículo recibe la categoría sugerida. Aprobar uno
     * ya aprobado responde 200 con `"ya_estaba": true` y no reaplica.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id  El ítem.
     * @return \Illuminate\Http\JsonResponse  {ok, item, conteos, ya_estaba?}
     */
    public function aprobar(Request $request, $id)
    {
        if (!CategoryProposalAccesoHelper::puede_gestionar()) {

            return $this->responder_error(CategoryProposalAplicarHelper::solo_el_dueno());
        }

        return $this->responder_item(CategoryProposalAplicarHelper::aprobar($this->userId(), (int) $id, $this->userId(false)));
    }

    /**
     * POST category-proposal-items/{id}/rechazar — el artículo sigue sin categoría y pasa a "Sin
     * categoría". Rechazar uno ya rechazado responde 200 con `"ya_estaba": true`.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id  El ítem.
     * @return \Illuminate\Http\JsonResponse  {ok, item, conteos, ya_estaba?}
     */
    public function rechazar(Request $request, $id)
    {
        if (!CategoryProposalAccesoHelper::puede_gestionar()) {

            return $this->responder_error(CategoryProposalAplicarHelper::solo_el_dueno());
        }

        return $this->responder_item(CategoryProposalAplicarHelper::rechazar($this->userId(), (int) $id, $this->userId(false)));
    }

    /**
     * POST category-proposal-items/aprobar-varios {ids} — hasta 500 por pedido.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  {ok, procesados, omitidos, conteos}
     */
    public function aprobar_varios(Request $request)
    {
        return $this->resolver_en_lote('aprobar', $request);
    }

    /**
     * POST category-proposal-items/rechazar-varios {ids} — hasta 500 por pedido.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  {ok, procesados, omitidos, conteos}
     */
    public function rechazar_varios(Request $request)
    {
        return $this->resolver_en_lote('rechazar', $request);
    }

    /**
     * El aprobar y el rechazar en lote: mismo recorrido, cambia solo la acción.
     *
     * @param  string $accion  'aprobar' | 'rechazar'
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    protected function resolver_en_lote($accion, Request $request)
    {
        if (!CategoryProposalAccesoHelper::puede_gestionar()) {

            return $this->responder_error(CategoryProposalAplicarHelper::solo_el_dueno());
        }

        $lote = CategoryProposalAplicarHelper::normalizar_ids_del_lote($request->input('ids'));

        if (!empty($lote['detalle'])) {

            return $this->responder_error(CategoryProposalAplicarHelper::validacion_fallida($lote['detalle']));
        }

        $resultado = CategoryProposalAplicarHelper::en_lote($accion, $this->userId(), $lote['ids'], $this->userId(false));

        return response()->json([
            'ok'         => true,
            'procesados' => $resultado['procesados'],
            'omitidos'   => $resultado['omitidos'],
            'conteos'    => $this->conteos_de($resultado['run']),
        ], 200);
    }

    /**
     * Traduce el resultado de aprobar o rechazar un ítem a la respuesta HTTP.
     *
     * @param  array $resultado  Lo que devuelve CategoryProposalAplicarHelper::aprobar() / rechazar().
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder_item(array $resultado)
    {
        if ($resultado['status'] !== 200) {

            return $this->responder_error($resultado);
        }

        $cuerpo = [
            'ok'      => true,
            'item'    => $resultado['item'],
            'conteos' => $this->conteos_de($resultado['run']),
        ];

        if (!empty($resultado['ya_estaba'])) {
            $cuerpo['ya_estaba'] = true;
        }

        return response()->json($cuerpo, 200);
    }

    /**
     * Los conteos de las tres solapas de la revisión. Si el pedido no tocó ninguna corrida propia (un lote
     * donde todo se omitió) se usan los de la corrida vigente del dueño; sin ninguna, ceros.
     *
     * @param  \App\Models\CategoryProposalRun|null $run
     * @return array  ['a_revisar' => n, 'asignados' => n, 'sin_categoria' => n]
     */
    protected function conteos_de($run)
    {
        if (is_null($run)) {
            $run = CategoryProposalAplicarHelper::corrida_vigente($this->userId());
        }

        if (is_null($run)) {
            return ['a_revisar' => 0, 'asignados' => 0, 'sin_categoria' => 0];
        }

        return CategoryProposalLecturaHelper::conteos($run);
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
