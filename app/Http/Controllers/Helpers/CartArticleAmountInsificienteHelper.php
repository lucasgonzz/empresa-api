<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\cart\CartStockAdjustmentHelper;

/**
 * Punto de entrada histórico del chequeo de carritos tras un movimiento de stock.
 *
 * Desde la misión carritos-ajuste-stock (25/11/2026) toda la lógica vive en
 * `Helpers\cart\CartStockAdjustmentHelper` (qué se ajusta, cuándo y a quién se avisa) y el criterio de "cuánto
 * deja comprar la tienda" en `Helpers\cart\StoreStockLimitHelper`, que absorbió el guard "la tienda no pone
 * tope" de la misión de Únicas. Esta clase queda como envoltorio para no tocar a sus llamadores
 * (`CheckCartAmount`, `StockMovementController::setArticleStock`, `HelperController`).
 */
class CartArticleAmountInsificienteHelper {

    /**
     * Ajusta los carritos activos que tienen el artículo. Nunca lanza: un fallo del ajuste no rompe el movimiento.
     *
     * @param \App\Models\Article $article Artículo cuyo stock se acaba de mover.
     * @return void
     */
    static function checkCartsAmounts($article) {
        CartStockAdjustmentHelper::adjust_carts_for_article($article);
    }

}
