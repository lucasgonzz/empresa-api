<?php

namespace App\Services\Mostrador;

use App\Models\Article;
use App\Services\PurchaseSuggestion\PurchaseSuggestionService;

/**
 * El motor de sugerencia de compra (PurchaseSuggestionService), tal cual, con una sola
 * diferencia: el stock de un artículo SIN depósitos cargados sale de articles.stock y
 * articles.stock_min en vez de sumar un pivot vacío.
 *
 * Por qué existe: el motor del módulo de compras suma el stock desde address_article,
 * y en un comercio que no usa depósitos (la mitad de los artículos de la base de
 * testing, y comercios enteros en producción) ese pivot no existe. Para el módulo eso
 * da "stock 0" en cada artículo con ventas, que es lo que ese módulo ya mostraba; para
 * un informe que el dueño lee a la mañana, decirle que tiene 0 de algo que tiene 18 es
 * inaceptable. El resto —disparadores, cantidad, elección de proveedor— es el del
 * motor, sin copiar una línea.
 *
 * Sin efectos secundarios: recibe una PurchaseSuggestion sin guardar (solo user_id y
 * los cuatro parámetros por default) y calcular_para_articulos() devuelve arrays.
 */
class CalculoDeComprasMostrador extends PurchaseSuggestionService
{
    /**
     * Stock global y mínimo global del artículo: los del motor cuando hay depósitos;
     * si no, la columna del artículo (stock_min > 0 cuenta como mínimo cargado).
     *
     * @param Article $article Con 'addresses' precargado
     * @return array [stock_global (float), stock_min_global (float|null)]
     */
    protected function calcular_stock_global(Article $article): array
    {
        if (count($article->addresses) >= 1) {
            return parent::calcular_stock_global($article);
        }

        $stock = $article->stock !== null ? (float) $article->stock : 0.0;
        $stock_min = $article->stock_min !== null && (float) $article->stock_min > 0
            ? (float) $article->stock_min
            : null;

        return [$stock, $stock_min];
    }
}
