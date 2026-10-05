<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\category_proposal\CategoryProposalAccesoHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalLecturaHelper;
use Illuminate\Http\Request;

/**
 * Las corridas de propuestas de categorías para Alertas → Catálogo → Categorías de la SPA (misión
 * categorizacion-tres-modelos, 5/10/2026). Contrato B del plan, §6: lectura (resumen del badge, la
 * corrida actual con sus tarjetas, marcar vista, ítems paginados de la revisión).
 *
 * Solo traduce a HTTP: la lógica vive en CategoryProposalLecturaHelper. Todo filtrado por el DUEÑO
 * (`$this->userId()`); un id de otro comercio responde 404 igual que uno inexistente.
 *
 * Quién puede qué (§4.7): ver las tarjetas y revisar es del dueño o del acceso maestro. Para cualquier
 * otro usuario (un empleado, aunque tenga `admin_access`) `resumen` y `actual` contestan 200 con la
 * forma vacía —así la SPA carga sin ruido— y `visto` e `items` contestan 403 `solo_el_dueno`.
 */
class CategoryProposalRunController extends Controller
{
    /**
     * GET category-proposal-runs/resumen — lo pide el login; alimenta el badge.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resumen(Request $request)
    {
        return response()->json(
            CategoryProposalLecturaHelper::resumen($this->userId(), CategoryProposalAccesoHelper::puede_gestionar()),
            200
        );
    }

    /**
     * GET category-proposal-runs/actual — la corrida vigente con tarjetas, árboles y conteos.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function actual(Request $request)
    {
        return response()->json(
            CategoryProposalLecturaHelper::actual($this->userId(), CategoryProposalAccesoHelper::puede_gestionar()),
            200
        );
    }

    /**
     * PUT category-proposal-runs/{id}/visto — el dueño abrió la solapa con la corrida lista.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function visto(Request $request, $id)
    {
        if (!CategoryProposalAccesoHelper::puede_gestionar()) {

            return $this->responder(CategoryProposalLecturaHelper::solo_el_dueno());
        }

        return $this->responder(CategoryProposalLecturaHelper::marcar_visto($this->userId(), $id));
    }

    /**
     * GET category-proposal-runs/{id}/items?solapa=&page=&per_page=&buscar= — los ítems de la
     * revisión (solapa, página, búsqueda) de la propuesta elegida.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function items(Request $request, $id)
    {
        if (!CategoryProposalAccesoHelper::puede_gestionar()) {

            return $this->responder(CategoryProposalLecturaHelper::solo_el_dueno());
        }

        return $this->responder(CategoryProposalLecturaHelper::items(
            $this->userId(),
            $id,
            // `solapa` y `buscar` van SIN `(string)`: un arreglo en la query string (`solapa[]=x`) daría un
            // 500 al convertirlo; el helper los valida y contesta 422 (B-14). `page` y `per_page` son
            // números: el `(int)` de un arreglo no tira.
            $request->query('solapa', CategoryProposalLecturaHelper::SOLAPA_POR_DEFECTO),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', CategoryProposalLecturaHelper::POR_PAGINA_DEFECTO),
            $request->query('buscar', '')
        ));
    }

    /**
     * Traduce lo que devuelve el helper (`status` + `body`) a la respuesta HTTP.
     *
     * @param  array $resultado  ['status' => int, 'body' => array]
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder(array $resultado)
    {
        return response()->json($resultado['body'], $resultado['status']);
    }
}
