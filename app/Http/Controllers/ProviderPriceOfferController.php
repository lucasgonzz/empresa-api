<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ProviderPriceOfferHelper;
use App\Http\Controllers\Helpers\UserHelper;

/**
 * Historial de precios por proveedor de un artículo (misión historial-precios-proveedor).
 * Sólo lectura: la lógica vive en ProviderPriceOfferHelper.
 */
class ProviderPriceOfferController extends Controller
{
    /**
     * GET provider-price-offer/article/{article_id}
     *
     * Devuelve las ofertas de los proveedores del artículo (de la más nueva a la más vieja) y si el
     * usuario ve costos. 404 si el artículo no es del dueño. Sin el permiso `article.cost` las filas
     * no traen `cost` ni `previous_article_cost`.
     *
     * @param int $article_id Artículo
     * @return \Illuminate\Http\JsonResponse {models: [...], can_see_cost: bool}
     */
    public function index_by_article($article_id)
    {
        $owner_id = UserHelper::userId();

        $can_see_cost = ProviderPriceOfferHelper::can_see_cost(UserHelper::user(false));

        $models = ProviderPriceOfferHelper::history_for_article($owner_id, $article_id, $can_see_cost);

        return response()->json(['models' => $models, 'can_see_cost' => $can_see_cost], 200);
    }
}
