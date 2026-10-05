<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\AdminSync\Concerns\ClaveEstrictaDeAdmin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalCatalogoHelper;
use Illuminate\Http\Request;

/**
 * Lo que la skill /categorizar lee del catálogo de un cliente (misión categorizacion-tres-modelos,
 * 5/10/2026). Contrato A del plan, §5.1 y §5.2. Dueño por `AsistenteCanalHelper::dueno()`; la clave
 * `X-Admin-Api-Key` se exige SIEMPRE, adentro del controlador (trait ClaveEstrictaDeAdmin), aunque
 * `ADMIN_SYNC_REQUIRE_API_KEY` esté apagado.
 *
 * Solo lee. Solo traduce a HTTP: la lógica vive en CategoryProposalCatalogoHelper.
 */
class CatalogoController extends Controller
{
    use ClaveEstrictaDeAdmin;

    /**
     * GET admin-sync/catalogo/resumen — hechos del cliente para diseñar los sistemas: totales del
     * catálogo, categorías que ya tiene, si puede elegir un sistema nuevo y la propuesta vigente.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resumen(Request $request)
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        return response()->json(CategoryProposalCatalogoHelper::resumen(AsistenteCanalHelper::dueno()), 200);
    }

    /**
     * GET admin-sync/catalogo/articulos?despues_de=&limite=&solo_sin_categoria= — el inventario por
     * cursor de id: los artículos del dueño de a páginas, de menor a mayor id.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function articulos(Request $request)
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $dueno = AsistenteCanalHelper::dueno();

        return response()->json(CategoryProposalCatalogoHelper::articulos(
            $dueno->id,
            $request->query('despues_de', 0),
            $request->query('limite', CategoryProposalCatalogoHelper::LIMITE_POR_DEFECTO),
            filter_var($request->query('solo_sin_categoria', false), FILTER_VALIDATE_BOOLEAN)
        ), 200);
    }
}
