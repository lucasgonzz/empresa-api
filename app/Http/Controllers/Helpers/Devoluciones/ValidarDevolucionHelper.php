<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ConceptoStockMovement;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Regla de una devolución sobre una venta: no se puede devolver más de lo que la venta todavía
 * tiene sin devolver.
 *
 * 🔴 Existe por la auditoría de stock del 5/9/2026. Se midieron notas de crédito DUPLICADAS en
 * SanBlas (dos NC con 1 segundo de diferencia sobre la venta 9040), Trama (dos con 35 segundos),
 * Masquito (dos con 10 segundos) e Innovate (tres en 30 segundos): el usuario apretaba "Guardar"
 * de nuevo porque la primera parecía no responder, y cada NC volvía a sumar al stock las mismas
 * unidades (y a acreditar la misma plata en la cuenta corriente). Ningún candado de tiempo
 * distingue un doble clic de una segunda devolución legítima; lo que sí lo distingue es que la
 * segunda intenta devolver unidades que la venta ya no tiene: eso es lo que se rechaza.
 *
 * Lo ya devuelto se lee del libro de movimientos de stock de la venta (concepto "Nota de credito"
 * atado a `sale_id`), no de `article_sale.returned_amount`: ese campo sólo se actualiza cuando la
 * devolución pide "actualizar unidades devueltas", y en producción hay muchas NC que devolvieron
 * stock sin tocarlo.
 */
class ValidarDevolucionHelper {

    /**
     * Devuelve el mensaje de error si algún renglón intenta devolver de más, o null si todo cierra.
     *
     * @param  int|null  $sale_id
     * @param  array     $items   Renglones con `is_article`, `id`, `unidades_devueltas` (o
     *                            `returned_amount`) y opcionalmente `article_variant_id`.
     * @return string|null
     */
    static function motivo_por_el_que_no_se_puede_devolver($sale_id, $items) {

        if (is_null($sale_id) || !is_array($items)) {
            return null;
        }

        $sale = Sale::withTrashed()->find($sale_id);

        if (is_null($sale)) {
            return null;
        }

        foreach ($items as $item) {

            if (!isset($item['is_article']) || !isset($item['id'])) {
                continue;
            }

            $unidades = Self::unidades_del_item($item);

            if ($unidades <= 0) {
                continue;
            }

            $variant_id = isset($item['article_variant_id']) && (int)$item['article_variant_id'] != 0 ? (int)$item['article_variant_id'] : null;

            $vendidas = Self::unidades_vendidas($sale, (int)$item['id'], $variant_id);

            // Sin renglón en la venta no hay nada que topar (una NC de monto libre, por ejemplo).
            if (is_null($vendidas)) {
                continue;
            }

            $ya_devueltas = Self::unidades_ya_devueltas($sale, (int)$item['id'], $variant_id);

            if ($unidades > $vendidas - $ya_devueltas + 0.0001) {

                $nombre = isset($item['name']) ? $item['name'] : ('el artículo '.$item['id']);

                return 'No se pueden devolver '.Self::fmt($unidades).' unidades de '.$nombre.': la venta N° '.$sale->num.' tiene '.Self::fmt($vendidas).' vendidas y '.Self::fmt($ya_devueltas).' ya devueltas. Si la devolución ya se registró, no hace falta volver a guardarla.';
            }
        }

        return null;
    }

    /**
     * @param  array  $item
     * @return float
     */
    static function unidades_del_item($item) {

        if (isset($item['unidades_devueltas']) && $item['unidades_devueltas'] !== '' && !is_null($item['unidades_devueltas'])) {
            return (float)$item['unidades_devueltas'];
        }

        if (isset($item['returned_amount']) && $item['returned_amount'] !== '' && !is_null($item['returned_amount'])) {
            return (float)$item['returned_amount'];
        }

        return 0;
    }

    /**
     * Unidades vendidas del artículo (y variante) en la venta, sumando todos sus renglones
     * (varios precios deja más de uno). Null si el artículo no está en la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  int               $article_id
     * @param  int|null          $variant_id
     * @return float|null
     */
    static function unidades_vendidas($sale, $article_id, $variant_id) {

        $total = null;

        foreach ($sale->articles as $article) {

            if ($article->id != $article_id) {
                continue;
            }

            if (!ArticleHelper::misma_variante($article->pivot->article_variant_id, $variant_id)) {
                continue;
            }

            $total = (float)$total + (float)$article->pivot->amount;
        }

        return $total;
    }

    /**
     * Unidades que las notas de crédito de esta venta ya devolvieron al stock, según el libro de
     * movimientos (un reverso de NC cuenta en negativo).
     *
     * @param  \App\Models\Sale  $sale
     * @param  int               $article_id
     * @param  int|null          $variant_id
     * @return float
     */
    static function unidades_ya_devueltas($sale, $article_id, $variant_id) {

        $concepto = ConceptoStockMovement::where('name', 'Nota de credito')->first();

        if (is_null($concepto)) {
            return 0;
        }

        $query = StockMovement::where('sale_id', $sale->id)
                    ->where('article_id', $article_id)
                    ->where('concepto_stock_movement_id', $concepto->id);

        if (is_null($variant_id)) {
            $query->where(function ($q) {
                $q->whereNull('article_variant_id')->orWhere('article_variant_id', 0);
            });
        } else {
            $query->where('article_variant_id', $variant_id);
        }

        return (float)$query->sum('amount');
    }

    /**
     * @param  float  $n
     * @return string
     */
    static function fmt($n) {
        return rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
    }
}
