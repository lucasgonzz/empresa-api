<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ConceptoStockMovement;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * @param  array     $items         Renglones con `is_article`, `id`, `unidades_devueltas` (o
     *                                  `returned_amount`) y opcionalmente `article_variant_id`.
     * @param  bool      $con_candado   true adentro de una transacción: lee la venta, sus renglones
     *                                  y sus movimientos CON candado (`FOR UPDATE`), o sea lo último
     *                                  commiteado, y no la foto que la transacción fijó en su primera
     *                                  lectura. Es lo que cierra el doble clic simultáneo: el segundo
     *                                  request espera al primero y recién entonces cuenta lo devuelto.
     * @return string|null
     */
    static function motivo_por_el_que_no_se_puede_devolver($sale_id, $items, $con_candado = false) {

        if (is_null($sale_id) || !is_array($items)) {
            return null;
        }

        $query = Sale::withTrashed()->where('id', $sale_id);

        if ($con_candado) {
            $query->lockForUpdate();
        }

        $sale = $query->first();

        if (is_null($sale)) {
            return null;
        }

        if ($con_candado) {
            $sale->setRelation('articles', $sale->articles()->lockForUpdate()->get());
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

            $ya_devueltas = Self::unidades_ya_devueltas($sale, (int)$item['id'], $variant_id, $con_candado);

            if ($unidades > $vendidas - $ya_devueltas + 0.0001) {

                $nombre = isset($item['name']) && $item['name'] !== '' ? $item['name'] : ('del artículo '.$item['id']);

                if (isset($item['name']) && $item['name'] !== '') {
                    $nombre = 'de '.$nombre;
                }

                $num = !is_null($sale->num) && $sale->num !== '' ? 'N° '.$sale->num : 'id '.$sale->id;

                return 'No se pueden devolver '.Self::fmt($unidades).' unidades '.$nombre.': la venta '.$num.' tiene '.Self::fmt($vendidas).' vendidas y '.Self::fmt($ya_devueltas).' ya devueltas. Si la devolución ya se registró, no hace falta volver a guardarla.';
            }
        }

        return null;
    }

    /**
     * Lanza DevolucionExcedidaException con el motivo si la devolución no cierra. Para usar adentro
     * de una transacción, después del candado sobre la venta.
     *
     * @param  int|null  $sale_id
     * @param  array     $items
     * @return void
     * @throws DevolucionExcedidaException
     */
    static function exigir($sale_id, $items) {

        $motivo = Self::motivo_por_el_que_no_se_puede_devolver($sale_id, $items, true);

        if (!is_null($motivo)) {
            throw new DevolucionExcedidaException($motivo);
        }
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
    static function unidades_ya_devueltas($sale, $article_id, $variant_id, $con_candado = false) {

        $concepto = ConceptoStockMovement::where('name', 'Nota de credito')->first();

        if (is_null($concepto)) {
            return 0;
        }

        $query = StockMovement::where('sale_id', $sale->id)
                    ->where('article_id', $article_id)
                    ->where('concepto_stock_movement_id', $concepto->id);

        if ($con_candado) {
            $query->lockForUpdate();
        }

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
     * Cuántas unidades de una devolución vuelven al stock: sólo lo que la venta DESCONTÓ y todavía
     * no devolvió, según su libro de movimientos.
     *
     * 🔴 Existe por la auditoría de stock (5/9/2026): una nota de crédito sobre una venta que nunca
     * descontó stock (marcada para no descontar, o con el artículo sin stock cuando se vendió)
     * igual sumaba las unidades, y el stock quedaba inflado por algo que nunca había salido. La
     * plata de la devolución no se toca: lo que se limita es el movimiento de stock.
     *
     * Una venta anterior al libro (octubre de 2023), sin ningún movimiento, no tiene contra qué
     * medirse: ahí se repone lo que el usuario cargó, como siempre.
     *
     * @param  \App\Models\Sale  $sale
     * @param  int               $article_id
     * @param  int|null          $variant_id
     * @param  float             $unidades     Lo que la devolución quiere reponer.
     * @return float                           Lo que efectivamente se repone (0 si nada).
     */
    static function unidades_a_reponer($sale, $article_id, $variant_id, $unidades) {

        $unidades = (float)$unidades;

        if ($unidades <= 0) {
            return 0;
        }

        if (Self::venta_anterior_al_libro($sale)) {
            return $unidades;
        }

        $variant_id = is_null($variant_id) || (int)$variant_id == 0 ? null : (int)$variant_id;

        $pendientes = Self::unidades_descontadas($sale, $article_id, $variant_id) - Self::unidades_ya_devueltas($sale, $article_id, $variant_id);

        $a_reponer = min($unidades, max(0, $pendientes));

        if ($a_reponer < $unidades) {
            Log::info('Devolución sobre la venta '.$sale->id.': del artículo '.$article_id.' se piden '.$unidades.' unidades pero el libro sólo tiene '.max(0, $pendientes).' descontadas sin devolver; se reponen '.$a_reponer.'.');
        }

        return $a_reponer;
    }

    /**
     * Unidades que la venta descontó del stock para el artículo (y variante), según su libro:
     * todos sus movimientos salvo las notas de crédito, con el signo dado vuelta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  int               $article_id
     * @param  int|null          $variant_id
     * @return float
     */
    static function unidades_descontadas($sale, $article_id, $variant_id) {

        $query = StockMovement::where('sale_id', $sale->id)
                    ->where('article_id', $article_id);

        $concepto = ConceptoStockMovement::where('name', 'Nota de credito')->first();

        if (!is_null($concepto)) {
            $query->where('concepto_stock_movement_id', '<>', $concepto->id);
        }

        if (is_null($variant_id)) {
            $query->where(function ($q) {
                $q->whereNull('article_variant_id')->orWhere('article_variant_id', 0);
            });
        } else {
            $query->where('article_variant_id', $variant_id);
        }

        return max(0, -(float)$query->sum('amount'));
    }

    /**
     * Si la venta es de antes de que existiera el libro de movimientos (26/10/2023) y no tiene
     * ninguno: no hay contra qué medir lo que descontó.
     *
     * @param  \App\Models\Sale  $sale
     * @return bool
     */
    static function venta_anterior_al_libro($sale) {

        if (is_null($sale->created_at) || $sale->created_at >= '2023-10-27') {
            return false;
        }

        return !StockMovement::where('sale_id', $sale->id)->exists();
    }

    /**
     * @param  float  $n
     * @return string
     */
    static function fmt($n) {
        return rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
    }
}
