<?php

namespace App\Http\Controllers\Helpers\Order;

use App\Http\Controllers\Helpers\AjustesDeClienteEsquemaHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CreateSaleOrderHelper {

    static function save_sale($order, $instance, $from_tienda_nube = false, $from_meli = false, $user = null) {
        if (Self::va_a_crear_venta($order, $from_tienda_nube, $from_meli)) {

            Log::info('Entro a save sale');

            $to_check = Self::get_to_check($user);

            $sale = Self::createSale($order, $instance, $to_check, $from_tienda_nube, $from_meli, $user);

            Self::attach_sale_properties($order, $sale, $from_tienda_nube, $from_meli);

            Log::info('se guardo venta para el pedido online, sale_id: '.$sale->id);
        }
    }

    /**
     * ¿Esta confirmación va a crear una venta?
     *
     * Es la condición que hasta el prompt 610 estaba inline en save_sale(). Se extrajo porque
     * LimiteCreditoHelper::validar_pedido_confirmado() necesita hacerse exactamente la misma
     * pregunta ANTES de que se escriba nada: si el chequeo de límite corriera bajo un criterio
     * propio, tarde o temprano se desalinearía del que decide de verdad si hay venta.
     *
     * ⚠️ Lee el estado DEL MODELO, así que quien la llame tiene que hacerlo con el pedido ya
     * pasado al estado nuevo (después del `$model->load('order_status')` de
     * `OrderController::update()`). Llamarla antes vería todavía 'Sin confirmar' y devolvería
     * false siempre.
     *
     * @param  \App\Models\Order  $order
     * @param  bool  $from_tienda_nube
     * @param  bool  $from_meli
     * @return bool
     */
    static function va_a_crear_venta($order, $from_tienda_nube = false, $from_meli = false) {

        if ($from_tienda_nube || $from_meli) {
            return true;
        }

        $order_status_name = !is_null($order->order_status) ? $order->order_status->name : null;

        return $order_status_name != 'Sin confirmar' && Self::saveSaleAfterFinishOrder();
    }

    /**
     * La venta que nace de un pedido es `to_check` cuando el comercio tiene la extensión
     * `check_sales`. Importa para el límite de crédito: SaleHelper::attachProperies() no llama a
     * create_current_acount() en una venta to_check, así que esa venta no mueve el saldo y no hay
     * límite que se pueda exceder.
     *
     * @param  \App\Models\User|null  $user
     * @return bool
     */
    static function get_to_check($user = null) {
        return UserHelper::hasExtencion('check_sales', $user);
    }

    /**
     * Cliente de ComercioCity al que se le imputa la venta, o null.
     *
     * Los pedidos de Tienda Nube y de Mercado Libre nacen SIN cliente a propósito: su comprador
     * no es un `Client` del ERP. Sin cliente no hay cuenta corriente, así que esas ventas nunca
     * tocan un saldo ni pueden exceder un límite.
     *
     * @param  \App\Models\Order  $order
     * @param  bool  $from_tienda_nube
     * @param  bool  $from_meli
     * @return int|null
     */
    static function get_client_id($order, $from_tienda_nube, $from_meli) {

        if ($from_tienda_nube || $from_meli) {
            return null;
        }

        // Pedido de invitado: buyer_id es NOT NULL en la tabla, pero el comprador puede haber sido
        // borrado. Sin esta guarda, PHP 7.4 tira notice y devuelve null igual — que es el
        // resultado correcto, pero por accidente.
        if (is_null($order->buyer)) {
            return null;
        }

        if (is_null($order->buyer->comercio_city_client)) {
            return null;
        }

        return $order->buyer->comercio_city_client_id;
    }

    static function attach_sale_properties($order, $sale, $from_tienda_nube, $from_meli) {

        $request = new \Illuminate\Http\Request();

        $request->items = [];

        /*
            Los descuentos y recargos del cliente con los que la tienda priceó el pedido (mision
            descuentos-recargos-por-cliente, 23/9/2026). Solo existen en un pedido de la tienda
            propia: Tienda Nube y Mercado Libre traen otro modelo, sin esas relaciones.
        */
        $ajustes = Self::ajustes_del_pedido($order, $from_tienda_nube, $from_meli);

        $factor = Self::factor_de_ajustes($ajustes);

        foreach ($order->articles as $article) {
            $request->items[] = [
                'id'                => $article->id,
                'name'              => $article->name,
                'amount'            => $article->pivot->amount,
                'cost'              => $article->pivot->cost ?? $article->cost,
                'price_vender'      => Self::precio_sin_ajustes($article->pivot->price, $factor),
                /*
                    🔴 La variante del renglon del pedido (`article_order.variant_id`, un id de
                    `article_variants`) tiene que llegar a la venta (auditoria de stock, 5/9/2026).
                    Sin ella, el movimiento de stock nacia sin variante: en un articulo con
                    variantes CheckGlobalStock no aplica y setArticleStockFromAddresses recalcula
                    el stock sumando las variantes, asi que la venta del pedido no descontaba
                    NADA, y el renglon quedaba en la venta sin decir que talle o color se vendio.
                */
                'article_variant_id' => isset($article->pivot->variant_id) ? $article->pivot->variant_id : null,
                'is_article'        => true
            ];
        }


        if ($from_meli) {
            $request->employee_id = null;
        }


        if (
            !$from_tienda_nube
            && !$from_meli
        ) {

            foreach ($order->promocion_vinotecas as $promo) {
                $request->items[] = [
                    'id'                => $promo->id,
                    'name'              => $promo->name,
                    'cost'              => $promo->pivot->cost,
                    'amount'            => $promo->pivot->amount,
                    'price_vender'      => Self::precio_sin_ajustes($promo->pivot->price, $factor),
                    'is_promocion_vinoteca'        => true
                ];
            }

            /*
                🔴 EL COMBO DEL PEDIDO TAMBIEN VIAJA A LA VENTA (mision combos-y-rangos-de-precio,
                16/9/2026). Hasta esta mision este archivo no nombraba la palabra "combo" ni una vez:
                el comprador agregaba el combo al carrito, el servidor de la tienda se lo cobraba y
                `order_combo` guardaba la linea, pero al confirmar el pedido en el ERP la venta nacia
                SIN el combo. Dos consecuencias, las dos mudas: la venta quedaba corta por el importe
                del combo, y el stock de los articulos componentes NO se descontaba.

                Va en el mismo bloque que las promos —y no arriba con los articulos— porque
                `$from_tienda_nube` y `$from_meli` traen un `TiendaNubeOrder` / `MeLiOrder`, que no
                son `Order` y no tienen relacion `combos()`. Un combo solo existe en un pedido de la
                tienda propia.

                `is_combo` es la clave que `SaleHelper::attachCombos()` busca para quedarse con este
                renglon, igual que `is_promocion_vinoteca` para el de arriba; y `articles` es lo que
                `sale\ComboHelper::discount_articles_stock()` recorre para descontar. El combo no
                tiene stock propio: es una receta, y lo que se descuenta es cada componente por
                `pivot.amount` x cantidad de combos.
            */
            foreach (ComboEsquemaHelper::combos_del_pedido($order) as $combo) {
                $request->items[] = [
                    'id'                => $combo->id,
                    'name'              => $combo->name,
                    'cost'              => $combo->pivot->cost,
                    'amount'            => $combo->pivot->amount,
                    'price_vender'      => Self::precio_sin_ajustes($combo->pivot->price, $factor),
                    'articles'          => Self::componentes_del_combo($combo),
                    'is_combo'          => true
                ];
            }
        }

        /*
            Sin ajustes esto es `[]` y `[]`, exactamente lo de antes de la mision: un pedido sin
            pivots (tienda vieja, comprador sin cliente, cliente sin condiciones) da la misma venta
            de siempre.
        */
        $request->discounts = $ajustes['discounts'];
        $request->surchages = $ajustes['surchages'];

        SaleHelper::attachProperies($sale, $request);
    }

    /**
     * Los descuentos y recargos del pedido en la forma en que `SaleHelper::attachDiscounts()` /
     * `attachSurchages()` los esperan: `[['id' => .., 'percentage' => ..]]`.
     *
     * El porcentaje es el del PIVOT del pedido (la foto que sacó la tienda al crearlo), nunca el
     * del descuento de hoy: si el dueño lo cambió o lo borró después, los precios del pedido se
     * calcularon igual con el viejo, y es ése el que hay que deshacer y colgar de la venta.
     *
     * Solo se toman los porcentajes usables —descuento `0 < d <= 100`, recargo `r > 0`—, que es
     * lo mismo que la tienda considera al pricear: uno que no se usó para calcular no puede
     * usarse para deshacer.
     *
     * @param  \App\Models\Order|mixed  $order
     * @param  bool  $from_tienda_nube
     * @param  bool  $from_meli
     * @return array{discounts: array<int,array<string,mixed>>, surchages: array<int,array<string,mixed>>}
     */
    static function ajustes_del_pedido($order, $from_tienda_nube, $from_meli) {

        $ajustes = ['discounts' => [], 'surchages' => []];

        if ($from_tienda_nube || $from_meli) {
            return $ajustes;
        }

        // 🔴 La guarda va ANTES de tocar la relacion: sin la tabla, `$order->discounts` revienta
        // y el pedido no se podria confirmar.
        if (!AjustesDeClienteEsquemaHelper::hay_tablas_de_pedido()) {
            return $ajustes;
        }

        foreach ($order->discounts as $discount) {

            $percentage = (float) $discount->pivot->percentage;

            if ($percentage > 0 && $percentage <= 100) {
                $ajustes['discounts'][] = [
                    'id'         => $discount->id,
                    'percentage' => $percentage,
                ];
            }
        }

        foreach ($order->surchages as $surchage) {

            $percentage = (float) $surchage->pivot->percentage;

            if ($percentage > 0) {
                $ajustes['surchages'][] = [
                    'id'         => $surchage->id,
                    'percentage' => $percentage,
                ];
            }
        }

        return $ajustes;
    }

    /**
     * `Π(1 − d/100) × Π(1 + r/100)`: la misma composicion que `SaleHelper::getTotalSale()` y
     * `vender_set_total.js` (descuentos primero, recargos despues, todos compuestos) y la misma con
     * la que la tienda priceó los renglones. Sin ajustes da 1.
     *
     * @param  array  $ajustes  Lo que devuelve `ajustes_del_pedido()`.
     * @return float
     */
    static function factor_de_ajustes($ajustes) {

        $factor = 1;

        foreach ($ajustes['discounts'] as $discount) {
            $factor *= (1 - $discount['percentage'] / 100);
        }

        foreach ($ajustes['surchages'] as $surchage) {
            $factor *= (1 + $surchage['percentage'] / 100);
        }

        return $factor;
    }

    /**
     * El precio del renglon SIN los ajustes del cliente: `round(precio / factor, 2)`.
     *
     * 🔴 NO LO SIMPLIFIQUES A PASAR EL PRECIO DEL PEDIDO TAL CUAL. En el pedido el renglon ya viene
     * ajustado (la tienda cobro 945 por un articulo de 1000 con 10% de descuento y 5% de recargo),
     * pero la venta lleva ADEMAS los descuentos y recargos colgados en `discount_sale` /
     * `sale_surchage`. Con el renglon a 945 y los pivots encima, cada camino que recalcula el total
     * desde la venta —`SaleHelper::getTotalSale()` al confirmar una venta chequeada, los puntos
     * (`PuntosBaseHelper`) y sobre todo la factura (`AfipItemCalculator`, que aplica los
     * porcentajes renglon por renglon)— los aplicaria DOS VECES y el comprobante saldria por
     * 945 × 0,9 × 1,05 = 893,03. Llevando el renglon a 1000 la venta queda igual a una hecha en
     * Vender con esos ajustes, y todo recalculo da 945 (± centavos del redondeo).
     *
     * Y tampoco lo "simplifiques" sacando los pivots de la venta para dejar el 945: la venta
     * tiene que decir QUE descuentos y recargos se le hicieron al cliente, igual que una de Vender.
     *
     * Con factor 1 (sin ajustes) o no positivo (un descuento del 100%: el renglon ya es 0 y no hay
     * precio que reconstruir) el precio pasa sin tocar.
     *
     * @param  float|string|null  $precio
     * @param  float  $factor
     * @return float|string|null
     */
    static function precio_sin_ajustes($precio, $factor) {

        if ($factor == 1 || $factor <= 0 || !is_numeric($precio)) {
            return $precio;
        }

        return round($precio / $factor, 2);
    }

    /**
     * Los componentes de un combo, en la forma exacta en la que llegan desde VENDER.
     *
     * `sale\ComboHelper::discount_articles_stock()` NO recibe modelos de Eloquent: recibe el renglon
     * del combo tal como lo manda la SPA, o sea arrays con `['id']` y `['pivot']['amount']`. Por eso
     * se traduce acá, que es —junto con `BudgetHelper::attachSaleCombos()`— uno de los dos unicos
     * lugares donde el combo viene de la base en vez de venir del payload.
     *
     * Se reusa ese helper en vez de escribir un descuento propio para que confirmar un pedido,
     * confirmar un presupuesto y guardar una venta muevan el stock de la MISMA manera. Dos
     * implementaciones del mismo descuento es la receta para que la auditoria de stock cierre por un
     * lado y no por el otro.
     *
     * @param  \App\Models\Combo  $combo
     * @return array<int,array<string,mixed>>
     */
    static function componentes_del_combo($combo) {

        $articles = [];

        foreach ($combo->articles as $article) {

            $articles[] = [
                'id'    => $article->id,
                'pivot' => [
                    'amount' => $article->pivot->amount,
                ],
            ];
        }

        return $articles;
    }


    static function createSale($order, $instance, $to_check = false, $from_tienda_nube, $from_meli, $user) {

        if ($user) {
            $num = $instance->num('sales', $user->id, 'user_id', $user->id);
        } else {
            $num = $instance->num('sales');
        }

        $client_id = Self::get_client_id($order, $from_tienda_nube, $from_meli);

        $terminada = Self::is_terminada($order, $to_check);

        /**
         * discount_stock en el INSERT: si solo existiera default en BD, Eloquent no lo hidrataría
         * en el modelo y attachArticles no descontaría stock (quedaría null en memoria).
         */
        $sale = Sale::create([
            'user_id'               => $order->user_id,
            'buyer_id'              => $order->buyer_id,
            'client_id'             => $client_id,
            'to_check'              => $to_check,
            'discount_stock'        => 1,
            'terminada'             => $terminada,
            'terminada_at'          => $terminada ? Carbon::now() : null,
            'num'                   => $num,
            'save_current_acount'   => 1,
            'order_id'              => ($from_tienda_nube || $from_meli) ? null : $order->id,
            'tienda_nube_order_id'  => $from_tienda_nube ? $order->id : null,
            'meli_order_id'         => $from_meli ? $order->id : null,
            'total'                 => $order->total,
            'address_id'            => $order->address_id,
            'fecha_entrega'         => $order->fecha_entrega,
            'seller_id'             => $order->seller_id,
            'moneda_id'             => 1,
            'employee_id'           => $from_meli ? null : SaleHelper::getEmployeeId(),
            'created_at'            => $from_meli ? $order->created_at : Carbon::now(),
        ]);

        /*
         * La lista del cliente, con el mismo resolvedor que la venta y el presupuesto (tanda 2
         * de la mision vender-lista-obligatoria, 18/9/2026, item A2). Hasta hoy preguntaba
         * `!is_null($sale->client->price_type_id)`, y un cliente con `price_type_id = 0` —el 0
         * del form generico de clientes, que es el caso mas comun de "cliente sin lista"— pasaba
         * como si fuera una lista: la venta del pedido nacia con `price_type_id = 0`. Un 0 no es
         * una lista, es "ninguna" escrito de otra forma (docblock de PriceTypeHelper): con el
         * cliente en 0 o en null la venta queda con null, y con una lista real, con esa.
         *
         * No hay `price_type_id` de request aca: el pedido no elige lista, los precios de linea
         * ya los cobro la tienda (`article_order.price`).
         */
        $price_type_id = PriceTypeHelper::resolver_price_type_id_para_guardar(null, $sale->client);

        if (!is_null($price_type_id)) {

            $sale->price_type_id = $price_type_id;
            $sale->save();
        }

        // Self::attach_articles($sale, $order->articles);

        return $sale;
    }

    static function is_terminada($order, $to_check) {

        if ($to_check) {
            return 0;
        }

        if ($order->fecha_entrega) {
            return 0;
        }

        return 1;
    }


    static function saveSaleAfterFinishOrder() {
        $user = UserHelper::getFullModel();
        return $user->online_configuration->save_sale_after_finish_order;
    }

    // static function attach_articles($sale, $articles) {
    //     foreach ($articles as $article) {
    //         $sale->articles()->attach($article->id, [
    //                                         'amount' => $article->pivot->amount,
    //                                         'cost' => isset($article->pivot->cost)
    //                                                     ? $article->pivot->cost
    //                                                     : null,
    //                                         'price' => $article->pivot->price,
    //                                     ]);

    //     }
    // }

}
