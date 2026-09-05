<?php

namespace App\Http\Controllers\Helpers\Order;

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

        foreach ($order->articles as $article) {
            $request->items[] = [
                'id'                => $article->id,
                'name'              => $article->name,
                'amount'            => $article->pivot->amount,
                'cost'              => $article->pivot->cost ?? $article->cost,
                'price_vender'      => $article->pivot->price,
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
                    'price_vender'      => $promo->pivot->price,
                    'is_promocion_vinoteca'        => true
                ];
            }
        }

        $request->discounts = [];
        $request->surchages = [];

        SaleHelper::attachProperies($sale, $request);
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

        if (
            !is_null($sale->client)
            && !is_null($sale->client->price_type_id)
        ) {

            $sale->price_type_id = $sale->client->price_type_id;
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
