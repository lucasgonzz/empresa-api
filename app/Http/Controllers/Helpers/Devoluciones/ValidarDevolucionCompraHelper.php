<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Models\ConceptoStockMovement;
use App\Models\ProviderOrder;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Log;

/**
 * Regla de una devolución sobre una COMPRA (nota de crédito a proveedor): no se le puede devolver al
 * proveedor más de lo que la compra ingresó y todavía no se devolvió.
 *
 * Es el gemelo de ValidarDevolucionHelper (el de las devoluciones de venta), nacido con la misión
 * devoluciones-compras-y-rediseno (1/10/2026), y existe por la misma razón: el doble clic. La
 * auditoría de stock del 5/9/2026 midió notas de crédito de venta DUPLICADAS en cuatro comercios
 * (dos NC con segundos de diferencia sobre la misma venta, porque el usuario volvía a apretar
 * "Guardar"); del lado de la compra el efecto sería el espejo: cada NC duplicada saca del stock las
 * mismas unidades y vuelve a bajar lo que se le debe al proveedor. Ningún candado de tiempo distingue
 * un doble clic de una segunda devolución legítima; lo que sí lo distingue es que la segunda intenta
 * devolver unidades que la compra ya no tiene sin devolver. Eso es lo que se rechaza.
 *
 * Lo ya devuelto se lee del LIBRO de movimientos de stock (concepto "Nota de credito proveedor"
 * atado a `provider_order_id`), no de un contador en el pivot de la compra: no hay tal contador, y
 * el libro es lo único que registra lo que efectivamente salió.
 *
 * 🔴 UNIDADES. La compra se carga en la unidad de la compra (bultos, si el artículo tiene
 * `unidades_individuales`), y el libro guarda unidades de stock (los conceptos de compra y el de la
 * NC a proveedor se multiplican por `unidades_individuales` en
 * StockMovementController::check_unidades_individuales). Todo lo que este helper compara y devuelve
 * está en la unidad de la COMPRA: lo que viene del libro se divide por `unidades_individuales` antes
 * de compararlo. Si alguien "simplifica" eso, una compra de 2 cajas de 12 aparece con 24 cajas
 * ingresadas.
 */
class ValidarDevolucionCompraHelper {

    /**
     * Nombre del concepto de stock con el que la NC a proveedor saca del stock.
     */
    const CONCEPTO = 'Nota de credito proveedor';

    /**
     * Devuelve el mensaje de error si algún renglón intenta devolver de más, o null si todo cierra.
     *
     * @param  int|null  $provider_order_id
     * @param  array     $items         Renglones con `is_article`, `id` y `unidades_devueltas` (o
     *                                  `returned_amount`), en la unidad de la compra.
     * @param  bool      $con_candado   true adentro de una transacción: lee la compra, sus renglones
     *                                  y sus movimientos CON candado (`FOR UPDATE`), o sea lo último
     *                                  commiteado, y no la foto que la transacción fijó en su primera
     *                                  lectura. Es lo que cierra el doble clic simultáneo: el segundo
     *                                  request espera al primero y recién entonces cuenta lo devuelto.
     * @return string|null
     */
    static function motivo_por_el_que_no_se_puede_devolver($provider_order_id, $items, $con_candado = false) {

        if (is_null($provider_order_id) || !is_array($items)) {
            return null;
        }

        $query = ProviderOrder::where('id', $provider_order_id);

        if ($con_candado) {
            $query->lockForUpdate();
        }

        $provider_order = $query->first();

        if (is_null($provider_order)) {
            return null;
        }

        if ($con_candado) {
            $provider_order->setRelation('articles', $provider_order->articles()->lockForUpdate()->get());
        }

        /*
            Lo pedido se ACUMULA por artículo antes de comparar: si la pantalla manda el mismo
            artículo en dos renglones, cada uno por separado puede caber y la suma no. En la venta
            esto no pasa porque cada renglón tiene su propia línea de venta; en la compra el artículo
            es una sola línea (el pivot es por artículo).
        */
        $pedidas_por_articulo = [];
        $nombres = [];

        foreach ($items as $item) {

            if (!isset($item['is_article']) || !isset($item['id'])) {
                continue;
            }

            $unidades = ValidarDevolucionHelper::unidades_del_item($item);

            if ($unidades <= 0) {
                continue;
            }

            $article_id = (int)$item['id'];

            if (!isset($pedidas_por_articulo[$article_id])) {
                $pedidas_por_articulo[$article_id] = 0;
            }

            $pedidas_por_articulo[$article_id] += $unidades;

            if (isset($item['name']) && $item['name'] !== '') {
                $nombres[$article_id] = $item['name'];
            }
        }

        foreach ($pedidas_por_articulo as $article_id => $unidades) {

            $article = Self::articulo_de_la_compra($provider_order, $article_id);

            // Sin renglón en la compra no hay nada que topar (un artículo agregado a mano).
            if (is_null($article)) {
                continue;
            }

            $compradas = Self::cantidad_efectiva($article);

            $ya_devueltas = Self::unidades_ya_devueltas($provider_order, $article, $con_candado);

            if ($unidades > $compradas - $ya_devueltas + 0.0001) {

                $nombre = isset($nombres[$article_id]) ? 'de '.$nombres[$article_id] : ('del artículo '.$article_id);

                $num = !is_null($provider_order->num) && $provider_order->num !== '' ? 'N° '.$provider_order->num : 'id '.$provider_order->id;

                return 'No se pueden devolver '.ValidarDevolucionHelper::fmt($unidades).' unidades '.$nombre.': la compra '.$num.' tiene '.ValidarDevolucionHelper::fmt($compradas).' compradas y '.ValidarDevolucionHelper::fmt($ya_devueltas).' ya devueltas. Si la devolución ya se registró, no hace falta volver a guardarla.';
            }
        }

        return null;
    }

    /**
     * Lanza DevolucionExcedidaException con el motivo si la devolución no cierra. Para usar adentro
     * de una transacción, después del candado sobre la compra.
     *
     * @param  int|null  $provider_order_id
     * @param  array     $items
     * @return void
     * @throws DevolucionExcedidaException
     */
    static function exigir($provider_order_id, $items) {

        $motivo = Self::motivo_por_el_que_no_se_puede_devolver($provider_order_id, $items, true);

        if (!is_null($motivo)) {
            throw new DevolucionExcedidaException($motivo);
        }
    }

    /**
     * El artículo tal como está en la compra (con su pivot), o null si la compra no lo tiene.
     *
     * @param  \App\Models\ProviderOrder  $provider_order
     * @param  int                        $article_id
     * @return \App\Models\Article|null
     */
    static function articulo_de_la_compra($provider_order, $article_id) {

        foreach ($provider_order->articles as $article) {

            if ($article->id == $article_id) {
                return $article;
            }
        }

        return null;
    }

    /**
     * Lo que la compra ingresó del artículo, en la unidad de la compra: la cantidad RECIBIDA si se
     * completó (incluido 0, "no llegó nada"), si no la pedida. Es la misma semántica con la que la
     * compra sumó el stock y armó su total: NewProviderOrderHelper::interpretar_cantidad_real(),
     * copiada acá porque ese método es de instancia y su constructor lee el usuario y la cuenta
     * corriente; la regla es de dos líneas y está documentada allá (bug del 20/7/2026: un 0 cargado
     * a mano NO es "vacío").
     *
     * @param  \App\Models\Article  $article  Con el pivot de la compra.
     * @return float
     */
    static function cantidad_efectiva($article) {

        $received = isset($article->pivot->received) ? $article->pivot->received : null;

        if (is_null($received) || $received === '') {
            return (float)$article->pivot->amount;
        }

        return (float)$received;
    }

    /**
     * Unidades de stock que equivalen a UNA unidad de la compra: `unidades_individuales` si el
     * artículo se compra por bulto, 1 si no. Mismo criterio que
     * StockMovementController::check_unidades_individuales (que multiplica sólo si no es null); el
     * 0 se trata como 1 para no dividir por cero.
     *
     * @param  \App\Models\Article|null  $article
     * @return float
     */
    static function factor_unidades($article) {

        if (
            !is_null($article)
            && !is_null($article->unidades_individuales)
            && (float)$article->unidades_individuales > 0
        ) {
            return (float)$article->unidades_individuales;
        }

        return 1;
    }

    /**
     * Unidades (de la compra) que las notas de crédito a proveedor de esta compra ya sacaron del
     * stock, según el libro. Un reverso, si alguna vez lo hay, suma en positivo y descuenta.
     *
     * @param  \App\Models\ProviderOrder  $provider_order
     * @param  \App\Models\Article        $article
     * @param  bool                       $con_candado
     * @return float
     */
    static function unidades_ya_devueltas($provider_order, $article, $con_candado = false) {

        $concepto = Self::concepto();

        if (is_null($concepto)) {
            return 0;
        }

        $query = StockMovement::where('provider_order_id', $provider_order->id)
                    ->where('article_id', $article->id)
                    ->where('concepto_stock_movement_id', $concepto->id);

        if ($con_candado) {
            $query->lockForUpdate();
        }

        // Los movimientos de la NC son negativos (sacan stock): lo devuelto es el opuesto.
        $unidades_de_stock = -(float)$query->sum('amount');

        return max(0, $unidades_de_stock / Self::factor_unidades($article));
    }

    /**
     * Unidades (de la compra) que la compra metió en el stock, según su libro: todos sus movimientos
     * salvo los de las notas de crédito a proveedor ("Compra a proveedor", "Act Compra a
     * proveedor", y la "Eliminacion" si se borró). Una compra con `update_stock` apagado no tiene
     * movimientos: no ingresó nada.
     *
     * @param  \App\Models\ProviderOrder  $provider_order
     * @param  \App\Models\Article        $article
     * @return float
     */
    static function unidades_ingresadas($provider_order, $article) {

        $query = StockMovement::where('provider_order_id', $provider_order->id)
                    ->where('article_id', $article->id);

        $concepto = Self::concepto();

        if (!is_null($concepto)) {
            $query->where(function ($q) use ($concepto) {
                $q->whereNull('concepto_stock_movement_id')
                    ->orWhere('concepto_stock_movement_id', '<>', $concepto->id);
            });
        }

        return max(0, (float)$query->sum('amount') / Self::factor_unidades($article));
    }

    /**
     * Cuántas unidades (de la compra) de una devolución salen del stock: sólo lo que la compra
     * INGRESÓ y todavía no se devolvió, según su libro. Es el gemelo de
     * ValidarDevolucionHelper::unidades_a_reponer().
     *
     * 🔴 Por qué se topa además de validar: el tope de arriba compara contra la cantidad de la
     * compra (pivot), pero una compra puede no haber movido stock (`update_stock` apagado, o un
     * artículo que no lleva stock cuando se compró). Sacar del stock algo que nunca entró lo deja
     * por debajo de la realidad. La plata de la devolución no se toca: lo que se limita es el
     * movimiento de stock.
     *
     * Una compra anterior al libro (octubre de 2023), sin ningún movimiento, no tiene contra qué
     * medirse: ahí sale lo que el usuario cargó.
     *
     * @param  \App\Models\ProviderOrder  $provider_order
     * @param  \App\Models\Article        $article
     * @param  float                      $unidades  Lo que la devolución quiere sacar.
     * @return float                                 Lo que efectivamente sale (0 si nada).
     */
    static function unidades_a_sacar($provider_order, $article, $unidades) {

        $unidades = (float)$unidades;

        if ($unidades <= 0) {
            return 0;
        }

        if (Self::compra_anterior_al_libro($provider_order)) {
            return $unidades;
        }

        $pendientes = Self::unidades_ingresadas($provider_order, $article) - Self::unidades_ya_devueltas($provider_order, $article);

        $a_sacar = min($unidades, max(0, $pendientes));

        if ($a_sacar < $unidades) {
            Log::info('Devolución sobre la compra '.$provider_order->id.': del artículo '.$article->id.' se piden '.$unidades.' unidades pero el libro sólo tiene '.max(0, $pendientes).' ingresadas sin devolver; salen '.$a_sacar.'.');
        }

        return $a_sacar;
    }

    /**
     * Si la compra es de antes de que existiera el libro de movimientos (26/10/2023) y no tiene
     * ninguno: no hay contra qué medir lo que ingresó. Mismo corte que
     * ValidarDevolucionHelper::venta_anterior_al_libro().
     *
     * @param  \App\Models\ProviderOrder  $provider_order
     * @return bool
     */
    static function compra_anterior_al_libro($provider_order) {

        if (is_null($provider_order->created_at) || $provider_order->created_at >= '2023-10-27') {
            return false;
        }

        return !StockMovement::where('provider_order_id', $provider_order->id)->exists();
    }

    /**
     * @return \App\Models\ConceptoStockMovement|null
     */
    static function concepto() {
        return ConceptoStockMovement::where('name', Self::CONCEPTO)->first();
    }
}
