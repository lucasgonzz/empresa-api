<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\AfipWsController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountAndCommissionHelper;
use App\Http\Controllers\Helpers\CurrentAcountFromSaleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PaymentMethodHelper;
use App\Http\Controllers\Helpers\SaleModificationsHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Http\Controllers\Helpers\sale\ComboHelper;
use App\Http\Controllers\Helpers\sale\PromocionVinotecaHelper;
use App\Http\Controllers\Helpers\sale\SaleCajaHelper;
use App\Http\Controllers\Helpers\sale\SaleTotalesHelper;
use App\Http\Controllers\Helpers\sale\UpdateHelper;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SellerCommissionController;
/*
 * Import que faltaba: returnToStock() hace `new StockMovementController()` y sin esta línea
 * PHP lo resolvía a App\Http\Controllers\Helpers\StockMovementController (inexistente).
 * Nunca se notó porque el único camino que llega ahí (el panel NC de Vender) reventaba
 * antes por la firma vieja de notaCredito(), corregida el 24/8/2026.
 */
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\Caja;
use App\Models\Cart;
use App\Models\Client;
use App\Models\Commissioner;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethodDiscount;
use App\Models\Cuota;
use App\Models\Discount;
use App\Models\Iva;
use App\Models\Sale;
use App\Models\SaleType;
use App\Models\SellerCommission;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


class SaleHelper extends Controller {

    static function set_total_sales($user_id) {

        $sales = Sale::where('user_id', $user_id)
                        ->orderBy('created_at', 'ASC')
                        ->get();

        echo count($sales).' ventas <br> ';

        foreach ($sales as $sale) {
            
            $sale->total = Self::getTotalSale($sale);
            $sale->timestamps = false;

            $sale->save();
        }

        echo 'Termino <br> ';
    }

    static function update_total_sale($sale) {
        $sale->total = Self::getTotalSale($sale);
        $sale->save();
    }

    static function get_se_esta_confirmando($request, $sale) {
        if ($request->confirmed && $sale->checked) {
            return true;
        }
        return false;
    }

    static function get_terminada($to_check, $fecha_entrega) {
        if (UserHelper::hasExtencion('check_sales') && $to_check) {
            return 0;
        }

        if (UserHelper::hasExtencion('ventas_con_fecha_de_entrega') && $fecha_entrega) {
            return 0;
        }

        return 1;
    }

    static function get_terminada_at($to_check, $fecha_entrega) {
        if (Self::get_terminada($to_check, $fecha_entrega)) {
            return Carbon::now();
        }
        return null;
    }

    static function check_guardad_cuenta_corriente_despues_de_facturar($sale, $instance) {
        if (UserHelper::hasExtencion('guardad_cuenta_corriente_despues_de_facturar')
            && !Self::al_cliente_se_le_factura_en_el_acto($sale) ) {
            $sale->save_current_acount = 0;
            $sale->save();
        }
    }

    static function al_cliente_se_le_factura_en_el_acto($sale) {
        if (!is_null($sale->client) && $sale->client->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar) {
            return true;            
        }
        return false;
    }

    static function setPrinted($instance, $sale, $confirmed, $user) {
        if (UserHelper::hasExtencion('check_sales', $user) && $confirmed) {
            $sale->printed = 1;
            $sale->save();
            $instance->sendAddModelNotification('Sale', $sale->id, false);
        }
    }

    static function log_client($sale) {
        $client = $sale->client;
        if (!is_null($client)) {
            Log::info('La venta '.$sale->id.' tiene el cliente: '.$client->name.'. Id: '.$client->id);
        }
    }

    static function log_articles($sale, $articles) {
        Log::info('La venta '.$sale->id.' tiene estos articulos:');
        foreach ($articles as $article) {
            Log::info('Id: '.$article->id.'. '.$article->name.'. amount: '.$article->pivot->amount.'. checked_amount: '.$article->pivot->checked_amount);
        }
    }

    static function updatePreivusClient($sale, $previus_client_id) {
        if (!is_null($sale->client_id) && $sale->client_id != $previus_client_id && !is_null($previus_client_id)) {
            CurrentAcountHelper::checkSaldos('client', $previus_client_id);
        }
    }

    static function sendUpdateClient($instance, $sale) {
        if (!is_null($sale->client_id) && !$sale->to_check && !$sale->checked) {
            $instance->sendAddModelNotification('Client', $sale->client_id);
        }
    }

    static function deleteSaleFrom($model_name, $model_id, $instance) {
        $sale = Sale::where($model_name.'_id', $model_id)
                        ->first();
        if (!is_null($sale)) {
            Log::info('Se quiere eliminar sale N° '.$sale->num.'. id: '.$sale->id.'. Por el empleado: '.Auth()->user()->name.', doc: '.Auth()->user()->doc_number);
            $sale->delete();
            $instance->sendDeleteModelNotification('sale', $sale->id, false);
        }
    }

    static function get_confirmed($to_check) {
        if (UserHelper::hasExtencion('check_sales') && $to_check) {
            return 0;
        }
        return 1;
    }

    static function getEmployeeId($request = null) {
        if (!is_null($request) && $request->employee_id != 0) {
            return $request->employee_id;
        }

        $user = Auth()->user();
        if (!is_null($user->owner_id)) {
            return $user->id;
        }
        return null;
    }

    /* 
        Retorno siempre null porque se va a empezar a usar siempre el array
        de current_acount_payment_methods
    */
    static function getCurrentAcountPaymentMethodId($request) {
        return null;
        if (is_null($request->client_id)) {
            return $request->current_acount_payment_method_id;
        }
        return null;
    }

    static function saveAfipTicket($sale) {
        if (!is_null($sale->afip_information_id) && $sale->afip_information_id != 0) {
            $ct = new AfipWsController(['sale' => $sale]);
            $afip_ticket_result = $ct->init();
            return $afip_ticket_result;
        } 
    }

    static function getSelectedAddress($request) {
        return !is_null($request->selected_address) ? $request->selected_address['id'] : null;
    }

    static function getNumSaleFromSaleId($sale_id) {
        $sale = Sale::where('id', $sale_id)
                    ->select('num')
                    ->first();
        if ($sale) {
            return $sale->num;
        }
        return null;
    }

    static function attachProperies($model, $request, $from_store = true, $previus_articles = null, $previus_combos = null, $previus_promos = null, $sale_modification = null, $se_esta_confirmando_por_primera_vez = false, $se_activando_discount_stock = false) {

        Log::info('attachProperies');

        
        Self::attachDiscounts($model, $request->discounts);
        Self::attachSurchages($model, $request->surchages);

        $fecha_agregado_by_article_id = [];

        if (!is_null($previus_articles)) {
            $fecha_agregado_by_article_id = Self::get_fecha_agregado_map_for_normal_articles($request->items, $previus_articles);
        }

        Self::attachArticles($model, $request->items, $previus_articles, $se_esta_confirmando_por_primera_vez, $fecha_agregado_by_article_id, $se_activando_discount_stock);
        
        Log::info('1');

        Self::attachPromocionVinotecas($model, $request->items, $previus_promos);
        Self::attachCombos($model, $request->items, $previus_combos);
        Self::attachServices($model, $request->items);

        Self::attachSelectedPaymentMethods($model, $request);
        
        Log::info('2');
        if (!$from_store) {
            SaleModificationsHelper::attach_articulos_despues_de_actualizar($model, $sale_modification);
            
            /*
                * Si la venta ya esta confirmada
                y no es que se esta confirmando por primera vez
                (osea ya estaba confirmada antes de actualizarce)
                Recien ahi veo si se elimino algun articulo para regresar al stock.
                Tampoco se revisa si se esta activando discount_stock por primera vez,
                ya que en ese caso los articulos previos nunca tuvieron stock descontado.
            */
            if (!$model->to_check && !$model->checked && !$se_esta_confirmando_por_primera_vez && !$se_activando_discount_stock) {

                UpdateHelper::check_articulos_eliminados($model, $request->items, $previus_articles, $se_esta_confirmando_por_primera_vez);
            }
        }

        Log::info('3');
        /*
            * Si se esta confirmando, el total que llega de vender esta mal
                porque no tiene en cuenta las unidades chequedas, las cuales ahora
                pasan a ser las unidades reales del articulo en la venta
            * Entonces se setea el total desde aca para tener eso en cuenta
        */
        if ($se_esta_confirmando_por_primera_vez) {
            Self::update_total_sale($model);
        }


        Log::info('4');
        if ($from_store && !$model->to_check && !$model->checked) {
            
            Self::create_current_acount($model);

            Self::crear_comision($model);

            SaleCajaHelper::check_caja($model);

            /*
             * Puntos para clientes. Lo que se cubre acá es la VENTA DE MOSTRADOR, que nunca
             * pasa por la cuenta corriente y por lo tanto nunca dispara checkPagos(): sin esta
             * línea, un comercio que no usa cuenta corriente no acumularía un solo punto.
             *
             * Para la venta de cuenta corriente esto es un no-op barato: create_current_acount()
             * ya llamó a CurrentAcountFromSaleHelper, que llama a checkPagos(), que ya
             * reconcilió esta misma venta. Correrlo dos veces no duplica nada — el
             * reconciliador compara contra lo que ya escribió y no toca la base si no cambió.
             *
             * Va DESPUÉS de check_caja() y no antes, para que si algo del cobro en el acto
             * revienta, la venta no quede con puntos otorgados por una venta que no se guardó.
             */
            PuntosAcumulacionHelper::reconciliar_venta($model);

        } else {

            Self::checkNotaCredito($model, $request);
        }

        Log::info('5');


        $h = new ArticlePurchaseHelper();
        $h->set_article_purcase($model);

        SaleTotalesHelper::set_total_cost($model);

        /**
         * Se recalcula y persiste la ganancia final de la venta
         * luego de actualizar el costo total.
         */
        Self::set_sale_ganancia($model);
    }

    /**
     * Calcula y persiste la ganancia total de la venta.
     *
     * @param \App\Models\Sale $sale
     * @return \App\Models\Sale
     */
    static function set_sale_ganancia($sale) {
        /** Total final de la venta usado para el calculo. */
        $total_sale = is_null($sale->total) ? null : (float) $sale->total;

        /** Costo total de la venta usado para el calculo. */
        $total_cost = is_null($sale->total_cost) ? null : (float) $sale->total_cost;

        /**
         * Ganancia persistida.
         * Si falta alguno de los dos valores base, se mantiene en null.
         */
        $sale_ganancia = is_null($total_sale) || is_null($total_cost) ? null : $total_sale - $total_cost;

        /** Se guarda sin timestamps para mantener el comportamiento actual del helper. */
        $sale->ganancia = $sale_ganancia;
        $sale->timestamps = false;
        $sale->save();

        return $sale;
    }

    // Chequeo que no falten articulos como le suele pasar a Pack
    static function check_que_este_el_articulos($sale, $article) {

        $sale->load('articles');
        $article_sale = $sale->articles()->find($article['id']);
            
        if (!$article_sale) {
            Self::attachArticle($sale, $article);
          
            // Log::info('No se estaba agregando el articulo '.$article['name'].'. N° '.$article['num'].' a la venta N° '.$sale->num);
        } else if (isset($article['name'])) {
            Log::info('La venta N° '.$sale->num.' SI tiene el articulo '.$article['name']);
        }
    }
    // static function check_que_esten_todos_los_articulos($sale) {

    //     foreach($sale->stock_movements as $stock_movement) {
    //         $article = $sale->articles()->find($stock_movement->article_id);
            
    //         if (!$article) {
                
    //             $article_faltante = $stock_movement->article;

    //             $sale->articles()->attach($stock_movement->article_id, [
    //                 'amount'    => abs($stock_movement->amount),
    //                 'price'     => $article_faltante->final_price,
    //             ]);
    //             Log::info('No se estaba agregando el articulo '.$article_faltante->name.'. N° '.$article_faltante->num.' a la venta N° '.$sale->num);
    //         } else {
    //             Log::info('La venta N° '.$sale->num.' SI tiene el articulo '.$article->name);
    //         }
    //     }
    // }

    static function set_total_a_facturar($sale, $request) {

        if (
            !is_null($request->afip_information_id) 
            && $request->afip_information_id != 0
        ) {

            $afip_ticket = new AfipTicket();
            $afip_ticket->afip_information_id = $request->afip_information_id;
            $afip_ticket->afip_tipo_comprobante_id = $request->afip_tipo_comprobante_id;
            $afip_ticket->facturar_importe_personalizado = null;
            $afip_ticket->sale = $sale;


            $afip_helper = new AfipHelper($afip_ticket);
            $importes = $afip_helper->getImportes();
            Log::info('pidiendo total_a_facturar: '.$importes['total']);

            $sale->total_a_facturar = $importes['total'];
            $sale->save();
        }
    }

    static function attachSelectedPaymentMethods($sale, $request){

        if (is_null($sale->client_id) || $sale->omitir_en_cuenta_corriente) {
            $sale->current_acount_payment_methods()->detach();

            if (
                is_array($request->selected_payment_methods)
                && count($request->selected_payment_methods) >= 1
            ) {
                PaymentMethodHelper::attach_payment_methods($sale, $request->selected_payment_methods);
                // foreach ($request->selected_payment_methods as $payment_method) {

                //     if (!is_null($payment_method['amount'])) {

                //         $amount = $payment_method['amount'];
                //         $amount_cotizado = $payment_method['amount_cotizado'];
                //         $cotizacion = $payment_method['cotizacion'];
                //         $moneda_id = $payment_method['moneda_id'];

                //         if ($payment_method['current_acount_payment_method_id'] == 5 
                //             && isset($request->monto_credito_real)
                //             && !is_null($request->monto_credito_real)) {

                //             $amount = $request->monto_credito_real;
                //         }

                //         $caja_id = null;

                //         if (isset($payment_method['caja_id'])
                //             && $payment_method['caja_id'] != 0) {
                //             $caja_id = $payment_method['caja_id'];
                //         }
                        
                //         $sale->current_acount_payment_methods()->attach($payment_method['current_acount_payment_method_id'],[
                //             'amount'            => $amount,
                //             'caja_id'           => $caja_id,
                //             'amount_cotizado'   => $amount_cotizado,
                //             'cotizacion'        => $cotizacion,
                //             'moneda_id'         => $moneda_id,
                //         ]);

                //         Log::info('adjuntando current_acount_payment_method_id: '.$payment_method['current_acount_payment_method_id'].' y caja_id: '.$caja_id);
                //     }
                // }
            } else {

                $total = (float)$sale->total;

                if (!is_null($request->discount_amount)) {
                    $total += (float)$request->discount_amount;
                }

                // Descuento/recargo por metodo de pago: si el frontend ya lo mando calculado (comportamiento
                // actual, se preserva tal cual), se usa ese valor. Si no vino, se resuelve en base a las
                // reglas configuradas (metodo + cuotas) segun la precedencia de Capa 3 (Prompt 263).
                $discount_percentage = $request->discount_percentage;
                if (is_null($discount_percentage) && !is_null($request->current_acount_payment_method_id)) {
                    $discount_percentage = Self::resolver_descuento_recargo_metodo_pago(
                        $sale->user_id,
                        $request->current_acount_payment_method_id,
                        $request->cuotas
                    );
                }

                $sale->current_acount_payment_methods()->attach($request->current_acount_payment_method_id, [
                    'amount'                => $total,
                    'discount_percentage'   => $discount_percentage,
                    'discount_amount'       => $request->discount_amount,
                    'caja_id'               => $request->caja_id,
                ]);
            }
        }

    }
    /**
     * Resuelve el porcentaje de descuento/recargo aplicable al vender con un metodo de pago y una
     * cantidad de cuotas determinados (Capa 3 del motor de precios, Prompt 263).
     *
     * Precedencia (gana la primera regla que matchee; las reglas NO se acumulan), de mas especifica
     * a mas generica:
     *   1. Regla de `cuotas` con `payment_method_id` = metodo elegido Y `cantidad_cuotas` = cuotas elegidas.
     *   2. Regla de `cuotas` generica (`payment_method_id` NULL) con esa `cantidad_cuotas`.
     *   3. Regla de `current_acount_payment_method_discounts` del metodo con `cuotas` = cuotas elegidas.
     *   4. Regla de `current_acount_payment_method_discounts` del metodo con `cuotas` NULL (comportamiento
     *      actual, previo al Prompt 260/263).
     * Si ninguna regla matchea, devuelve null (sin descuento/recargo, igual que hoy).
     *
     * @param int $user_id Id del owner (dueño de cuenta) al que pertenecen las reglas configuradas.
     * @param int|null $current_acount_payment_method_id Metodo de pago elegido en la venta.
     * @param int|null $cuotas Cantidad de cuotas elegida (null si la venta no es en cuotas).
     * @return float|null Porcentaje a aplicar sobre el monto (positivo = descuento, negativo = recargo).
     */
    static function resolver_descuento_recargo_metodo_pago($user_id, $current_acount_payment_method_id, $cuotas = null) {

        if (is_null($current_acount_payment_method_id)) {
            return null;
        }

        // Pasos 1 y 2 (reglas de `cuotas`) solo aplican si se eligio una cantidad de cuotas.
        if (!is_null($cuotas)) {

            // 1. Regla especifica de este metodo de pago para esta cantidad de cuotas.
            $cuota_especifica = Cuota::where('user_id', $user_id)
                                ->where('payment_method_id', $current_acount_payment_method_id)
                                ->where('cantidad_cuotas', $cuotas)
                                ->first();
            if ($cuota_especifica) {
                return (float)$cuota_especifica->descuento - (float)$cuota_especifica->recargo;
            }

            // 2. Regla generica (sin metodo de pago) para esa cantidad de cuotas.
            $cuota_generica = Cuota::where('user_id', $user_id)
                                ->whereNull('payment_method_id')
                                ->where('cantidad_cuotas', $cuotas)
                                ->first();
            if ($cuota_generica) {
                return (float)$cuota_generica->descuento - (float)$cuota_generica->recargo;
            }

            // 3. Regla del metodo de pago limitada a esa cantidad de cuotas.
            $method_discount_con_cuotas = CurrentAcountPaymentMethodDiscount::where('user_id', $user_id)
                                ->where('current_acount_payment_method_id', $current_acount_payment_method_id)
                                ->where('cuotas', $cuotas)
                                ->first();
            if ($method_discount_con_cuotas) {
                return (float)$method_discount_con_cuotas->discount_percentage;
            }
        }

        // 4. Regla generica del metodo de pago (sin cuotas): comportamiento actual, previo al Prompt 260.
        $method_discount_generico = CurrentAcountPaymentMethodDiscount::where('user_id', $user_id)
                            ->where('current_acount_payment_method_id', $current_acount_payment_method_id)
                            ->whereNull('cuotas')
                            ->first();
        if ($method_discount_generico) {
            return (float)$method_discount_generico->discount_percentage;
        }

        return null;
    }

    /*
        Devuelve el motivo por el que una venta NO se puede editar, o null si se puede.

        La regla, definida por Lucas el 3/8/2026: los unicos dos casos editables son
        (a) una venta a la cuenta corriente de un cliente, sin facturar, y
        (b) una venta de mostrador en un comercio SIN cajas configuradas, con un unico metodo
            de pago y sin facturar.
        El caso (a) no necesita condicion propia: attachSelectedPaymentMethods() solo adjunta
        metodos de pago cuando la venta NO va a cuenta corriente, asi que una venta de cuenta
        corriente tiene cero current_acount_payment_methods y no la alcanza el chequeo de cajas.

        POR QUE ESTA EN UN SOLO LUGAR (no duplicar la condicion en cada controlador):
        hasta el 3/8/2026 esta decision vivia UNICAMENTE en el frontend
        (se_puede_actualizar.js), escondiendo el boton. El endpoint quedaba alcanzable con la
        sesion abierta, y el modal de la venta se pinta con los datos que tenia al abrirse, asi
        que el boton podia estar visible sobre una venta que ya habia cambiado. Cada lugar que
        modifique una venta ya guardada tiene que preguntar aca.

        Recibe el modelo ya cargado para no reconsultar, y usa loadMissing para no depender de
        que quien llama se haya acordado de traer las relaciones.
    */
    static function motivo_por_el_que_no_se_puede_editar($sale, $criterio_conservador_de_tickets = false) {

        $sale->loadMissing(['afip_tickets', 'current_acount_payment_methods']);

        /*
            Que congela segun el FLUJO que pregunta (tanda correctivos 2408, items 11 y su
            acotacion). Los dos criterios conviven a proposito:

            - EDICION de la venta ($criterio_conservador_de_tickets = false, el default:
              SaleController::update() y updatePrices()): solo congelan los comprobantes CON
              CAE. Un ticket RECHAZADO no existe ante ARCA y no debe trabar el mostrador
              (decision de Lucas, 24/8/2026). Es el mismo criterio que ya usa la SPA para
              decidir si una venta "tiene factura" (!!afip_ticket.cae en sale-print-buttons).

            - ANULACION del presupuesto ($criterio_conservador_de_tickets = true, lo pasa
              BudgetController::anular()): CUALQUIER AfipTicket congela, tambien uno sin CAE.
              Anular BORRA la venta, y con un ticket pendiente-sin-respuesta el borrado
              podria dejar un CAE huerfano si ARCA aprueba despues: primero se resuelve la
              situacion con ARCA. Es la politica conservadora previa, fijada por
              tests/Feature/Presupuestos/1 (anular_con_la_venta_facturada_se_rechaza_y_no_toca_nada)
              y vigente hasta que Lucas diga lo contrario.
        */
        $tickets_que_congelan = 0;

        foreach ($sale->afip_tickets as $afip_ticket) {

            if ($criterio_conservador_de_tickets) {
                $tickets_que_congelan++;
            } else if (!is_null($afip_ticket->cae) && $afip_ticket->cae !== '') {
                $tickets_que_congelan++;
            }
        }

        if ($tickets_que_congelan >= 1) {

            return 'La venta ya fue facturada. Una venta con comprobante AFIP emitido no se puede modificar.';
        }

        if ($sale->is_cerrada) {

            return 'La venta esta cerrada y no se puede modificar.';
        }

        if ($sale->caja_id && $sale->caja_id != 0) {

            return 'La venta ya movio una caja y no se puede modificar.';
        }

        /*
            Mas de un metodo de pago: actualizar la venta rehace el reparto desde cero
            (attachSelectedPaymentMethods hace detach y vuelve a adjuntar), asi que el reparto
            original se pierde y los importes por metodo dejan de cuadrar con el total.
            Va ANTES del chequeo de cajas para que el mensaje sea el mas especifico de los dos
            cuando aplican los dos.
        */
        if (count($sale->current_acount_payment_methods) > 1) {

            return 'La venta se cobro con mas de un metodo de pago. Para modificarla hay que eliminarla y volver a cargarla.';
        }

        /*
            Con cajas configuradas, cualquier venta ya cobrada movio plata en una caja y
            editarla descuadra el arqueo. La venta a cuenta corriente no entra aca porque no
            tiene metodos de pago adjuntos.
            Se cuenta contra el user_id de la venta y no contra el usuario autenticado: el
            helper es estatico y no tiene sesion, y ademas la venta es la que define el tenant.
        */
        if (count($sale->current_acount_payment_methods) >= 1) {

            $cantidad_de_cajas = Caja::where('user_id', $sale->user_id)->count();

            if ($cantidad_de_cajas >= 1) {

                return 'El comercio tiene cajas configuradas, asi que una venta ya cobrada no se puede modificar. Para corregirla hay que eliminarla y volver a cargarla.';
            }
        }

        return null;
    }
    static function checkNotaCredito($sale, $request) {
        if ($request->save_nota_credito) {
            sleep(1);
            $haber = 0;

            foreach ($request->returned_items as $item) {

                // Log::info('item:');
                // Log::info($item);

                $total_item = (float)$item['price_vender'] * (float)$item['returned_amount'];

                if (!is_null($item['discount']) && $item['discount'] != 0) {
                    $total_item -= $total_item * $item['discount'] / 100;
                }

                /*
                    * Aplica los descuentos de la venta si:
                    La venta tiene descuentos 
                    Y
                    Si es un articulo (a los cuales SIEMPRE se le aplican los descuentos de la venta)
                    O (en caso que no sea un articulo, osea que es un SERVICIO) la venta tiene en TRUE discounts_in_services
                */
                if (
                    count($sale->discounts) >= 1
                    && (
                        (isset($item['is_article']) && $item['is_article'])
                        || $sale->discounts_in_services
                    )
                ) {


                    foreach ($sale->discounts as $discount) {

                        $total_item -= (float)$discount->pivot->percentage * $total_item / 100;
                    }

                }

                /*
                    * Aplica los recargos de la venta si:
                    La venta tiene recargos 
                    Y
                    Si es un articulo (a los cuales SIEMPRE se le aplican los recargos de la venta)
                    O (en caso que no sea un articulo, osea que es un SERVICIO) la venta tiene en TRUE surchages_in_services
                */
                if (
                    count($sale->surchages) >= 1
                    && (
                        (isset($item['is_article']) && $item['is_article'])
                        || $sale->surchages_in_services
                    )
                ) {


                    foreach ($sale->surchages as $surchage) {

                        $total_item += (float)$surchage->pivot->percentage * $total_item / 100;
                    }

                }


                $haber += $total_item;

            }

            /*
             * Cuenta corriente del cliente en la moneda de la venta, igual que en
             * DevolucionesController::store(). Hasta el 24/8/2026 este call site llamaba a
             * notaCredito() con la firma VIEJA (sin $credit_account_id como primer argumento):
             * el total entraba donde va el id de cuenta, la descripcion donde va el monto, y
             * todo lo demas corrido un lugar. El unico camino que quedo desactualizado era
             * este (el panel "Nota de credito" de Vender); Devoluciones ya usaba la firma nueva.
             */
            $credit_account_id = null;

            if (!is_null($request->client_id)) {

                /** Moneda de la venta; sin moneda cargada se asume pesos (id 1), mismo criterio que DevolucionesController::get_moneda_id(). */
                $moneda_id = !is_null($sale->moneda_id) && $sale->moneda_id != 0 ? $sale->moneda_id : 1;

                $credit_account = CreditAccount::where('model_name', 'client')
                                                ->where('model_id', $request->client_id)
                                                ->where('moneda_id', $moneda_id)
                                                ->first();

                if (!is_null($credit_account)) {
                    $credit_account_id = $credit_account->id;
                }
            }

            $nota_credito = CurrentAcountHelper::notaCredito(
                $credit_account_id,
                $haber,
                $request->nota_credito_description,
                'client',
                $request->client_id,
                $sale->id,
                $request->returned_items
            );

            /*
             * checkSaldos() tambien cambio de firma con el refactor de credit_account (ahora
             * recibe el id de la cuenta, no el par model_name/model_id): se recalculan los
             * saldos de la cuenta de la NC recien creada. Solo si hay cuenta: con null adentro
             * hace CreditAccount::find(null)->id y revienta.
             */
            if (!is_null($credit_account_id)) {
                CurrentAcountHelper::checkSaldos($credit_account_id);
            }

            $ct = new Controller();
            $ct->sendAddModelNotification('client', $request->client_id, false);

            Self::returnToStock($sale, $nota_credito, $request->returned_items);

            if (!is_null($sale->afip_ticket)) {
                $afip_helper = new AfipNotaCreditoHelper($sale, $nota_credito);
                $afip_helper->init();
            }
        }
    }

    static function returnToStock($sale, $nota_credito, $items) {
        // Log::info('returnToStock para nota_credito:');
        // Log::info((array)$nota_credito);
        foreach ($items as $item) {
            if (
                isset($item['returned_amount']) 
                && !is_null($item['returned_amount']) 
                && (float)$item['returned_amount'] > 0
            ) {
                $ct = new StockMovementController();
                $request = new \Illuminate\Http\Request();
                
                $request->model_id = $item['id'];
                $request->to_address_id = $sale->address_id;
                $request->amount = $item['returned_amount'];
                $request->nota_credito_id = $nota_credito->id;
                $request->concepto = 'Nota credito Venta N° '.$sale->num;
                $ct->store($request);
            }
        }

    }

    static function attachDiscounts($sale, $discounts) {
        $sale->discounts()->detach();
        // $discounts = GeneralHelper::getModelsFromId('Discount', $discounts_id);
        foreach ($discounts as $discount) {
            $sale->discounts()->attach($discount['id'], [
                'percentage' => $discount['percentage'],
            ]);
        }
    }

    static function attachSurchages($sale, $surchages) {
        $sale->surchages()->detach();
        // $surchages = GeneralHelper::getModelsFromId('Surchage', $surchages_id);
        foreach ($surchages as $surchage) {
            $sale->surchages()->attach($surchage['id'], [
                'percentage' => $surchage['percentage']
            ]);
        }
    }

    static function check_deleted_articles_from_check($sale, $previus_articles) {
        $sale->load('articles');

        if ($sale->checked && !is_null($previus_articles)) {
            Log::info('previus_articles:');
            foreach ($previus_articles as $article) {
                Log::info($article->name);
            }
            foreach ($previus_articles as $previus_article) {
                $is_deleted = true;
                foreach ($sale->articles as $sale_article) {
                    if ($previus_article->id == $sale_article->id) {
                        $is_deleted = false;
                        Log::info('Se encontro en previus_articles el articulo id: '.$previus_article->id);
                    }
                }
                if ($is_deleted) {
                    Log::info('No se encontro el articulo en previus_articles id: '.$previus_article->id);
                    $article = [
                        'id'                    => $previus_article->id,
                        'amount'                => (float)$previus_article->pivot->amount,
                        'cost'                  => $previus_article->pivot->cost,
                        'price_vender'          => $previus_article->pivot->price,
                        'returned_amount'       => $previus_article->pivot->returned_amount,
                        'delivered_amount'      => $previus_article->pivot->delivered_amount,
                        'discount'              => $previus_article->pivot->discount,
                        'checked_amount'        => $previus_article->pivot->amount,
                        'created_at'            => Carbon::now(),
                    ];
                    Self::attachArticle($sale, $article);
                }
            }
        }
    }

    static function get_seller_id($request) {
        if (isset($request->seller_id)
            && !is_null($request->seller_id)
            && $request->seller_id != 0) {

            return $request->seller_id;
        }

        if (!is_null($request->client_id)) {

            $client = Client::find($request->client_id);

            if (!is_null($client->seller_id)) {

                return $client->seller_id;
            }
        }

        $employee_id = Self::getEmployeeId($request);
        if (Self::getEmployeeId($request)) {

            $employee = User::find($employee_id);
            if ($employee->seller_id) {
                Log::info('retornando seller_id en base al empleado '.$employee->name);
                return $employee->seller_id;
            }
        }

        return 0;
    }

    static function create_current_acount($sale) {
        if (!is_null($sale->client_id)
            && Self::va_a_volver_a_la_cuenta_corriente($sale)) {

            $helper = new CurrentAcountFromSaleHelper($sale);
            $helper->crear_current_acount();
        }
    }

    /**
     * Si la venta corresponde a la cuenta corriente del cliente.
     *
     * Son las dos condiciones que create_current_acount() ya usaba, extraidas para que
     * updateCurrentAcountsAndCommissions() pueda preguntarlo ANTES de borrar el movimiento y
     * dejar el rastro. Que la decision viva en un solo lugar es justamente lo que evita que el
     * borrado y la recreacion dejen de estar de acuerdo, que es lo que pasaba en el bug.
     *
     * @param  \App\Models\Sale  $sale
     * @return bool
     */
    static function va_a_volver_a_la_cuenta_corriente($sale) {
        return (bool) ($sale->save_current_acount && !$sale->omitir_en_cuenta_corriente);
    }

    static function crear_comision($sale) {
        if (!is_null($sale->seller_id)) {
            
            $helper = new ComisionesHelper($sale);
            $helper->crear_comision();
        }
    }

    static function attachArticles($sale, $articles, $previus_articles, $se_esta_confirmando_por_primera_vez, $fecha_agregado_by_article_id = [], $se_activando_discount_stock = false) {
        
        foreach ($articles as $article) {
            if (isset($article['is_article'])) {

                if (isset($article['varios_precios']) && is_array($article['varios_precios'])) {

                    foreach ($article['varios_precios'] as $otro_precio) {

                        $otro_precio['id'] = $article['id'];
                        $otro_precio['name'] = $article['name'] ?? null;
                        $otro_precio['name_vender_personalizado'] = $article['name_vender_personalizado'] ?? null;

                        if ($otro_precio['amount'] == '') {
                            $otro_precio['amount'] = 1;
                        }
                        
                        // $fecha_agregado = Self::get_fecha_agregado_for_item($otro_precio, $fecha_agregado_map);
                        Self::attachArticle($sale, $otro_precio, null);

                    }
                } else {

                    $amount = Self::getAmount($sale, $article);
                    
                    if (($sale->to_check || $sale->checked) 
                        || (!is_null($amount) && $amount > 0) ) {

                        // Log::info('Agregando el articulos: '.$article['name']);
                        $article_id = (int)$article['id'];
                        $fecha_agregado = $fecha_agregado_by_article_id[$article_id] ?? null;

                        Self::attachArticle($sale, $article, $fecha_agregado);
                    } else {
                        // Log::info('No se agrego articulo '.$article['name'].' a la venta N° '.$sale->num.'. Amount: '.$amount);
                    }

                }


                /*
                 * Se descuenta stock solo si:
                 * - la venta no está en modo depósito (to_check / checked)
                 * - la venta tiene activado discount_stock
                 * - el artículo maneja stock
                 *
                 * Cuando se activa discount_stock por primera vez en una actualización,
                 * se combina con se_esta_confirmando_por_primera_vez para que ArticleHelper
                 * descuente la cantidad total actual (no la diferencia con artículos previos).
                 */

                Log::info('to_check: '.(bool)$sale->to_check);
                Log::info('checked: '.(bool)$sale->checked);
                Log::info('discount_stock: '.(bool)$sale->discount_stock);
                if (!(bool)$sale->to_check && !(bool)$sale->checked && (bool)$sale->discount_stock && Self::usa_stock($article)) {

                    $amount = Self::getAmount($sale, $article);

                    if (isset($article['article_variant_id'])) {
                        $article_variant_id = $article['article_variant_id'];
                    } else {
                        $article_variant_id = null;
                    }

                    // Si se activa discount_stock por primera vez, tratar como primera confirmación
                    // para que se use la cantidad total y no la diferencia con artículos previos
                    $es_primer_descuento = $se_esta_confirmando_por_primera_vez || $se_activando_discount_stock;
                    
                    // if ($amount > 0) {
                        ArticleHelper::discountStock($article['id'], $amount, $sale, $previus_articles, $es_primer_descuento, $article_variant_id);
                    // }
                } else {
                    Log::info('No se desconto stock para article_id '.$article['id']);
                }

                Self::check_que_este_el_articulos($sale, $article);

            }
        }
    }

    static function usa_stock($article) {
        $_article = Article::find($article['id']);
        if (!is_null($_article)) {
            // Log::info('article stock: '.$_article->stock);
            return !is_null($_article->stock);
        } else {
            // Log::info('No se encontro article id '.$article['id']);
        }
        return false;
    }

    static function attachArticle($sale, $article, $fecha_agregado = null) {
        
        $delivered_amount = Self::getDeliveredAmount($article);

        $amount = Self::getAmount($sale, $article);
        $cost = Self::getCost($sale, $article);
        $price = $article['price_vender'];
        /**
         * Precio unitario sin IVA persistido para uso posterior en PDF sin recálculo.
         */
        $price_sin_iva = Self::get_price_sin_iva($article, $price);

        $ganancia = (float)$price - (float)$cost;

        $sale->articles()->attach($article['id'], [
            'amount'                => $amount,
            'ganancia'              => $ganancia * $amount,
            'cost'                  => $cost,
            'price'                 => $price,
            'price_sin_iva'         => $price_sin_iva,
            'returned_amount'       => Self::getReturnedAmount($article),
            'delivered_amount'      => $delivered_amount,
            'discount'              => Self::getDiscount($article),
            /**
             * Alícuota de IVA al momento de la venta.
             * Se persiste para que notas de crédito y devoluciones usen el mismo IVA
             * sin verse afectadas por cambios futuros en el artículo.
             */
            'iva_percentage'        => Self::get_iva_percentage_for_pivot($article),
            'checked_amount'        => Self::getCheckedAmount($sale, $article),
            'article_variant_id'    => Self::getArticleVariantId($article),
            'variant_description'    => Self::getVariantDescription($article),
            /**
             * Nombre personalizado de la línea; null si no difiere del artículo.
             */
            'name'                  => Self::get_custom_name_for_pivot($article),
            'price_type_personalizado_id'    => Self::get_price_type_personalizado($article),

            'fecha_agregado'        => $fecha_agregado,

            'created_at'            => Carbon::now(),
        ]);

        if (!is_null($delivered_amount) && !$sale->en_acopio) {
            $sale->en_acopio = 1;
            $sale->save();
        }
    }

    static function updateItemsPrices($sale, $items) {
        foreach ($items as $item) {
            if (isset($item['is_article']) && $item['price_vender'] != '') {
                /**
                 * Recalcula precio sin IVA cada vez que se actualiza precio de artículo.
                 */
                $price_sin_iva = Self::get_price_sin_iva($item, $item['price_vender']);
                $sale->articles()->updateExistingPivot($item['id'], [
                                                        'price' => $item['price_vender'],
                                                        'price_sin_iva' => $price_sin_iva,
                                                    ]);
            } else if (isset($item['is_service']) && $item['price_vender'] != '') {
                $service = Service::find($item['id']);
                $service->price = $item['price_vender'];
                $service->save();
                $sale->services()->updateExistingPivot($item['id'], [
                                                        'price' => $item['price_vender'],
                                                    ]);
            }
        }
    }

    /**
     * Obtiene la alícuota de IVA del artículo al momento de la venta para persistirla en el pivot.
     * Usa la misma jerarquía de resolución que get_price_sin_iva para mantener coherencia.
     *
     * @param array $article_data Datos del artículo recibidos desde request/flujo interno.
     * @return float|string|int Porcentaje de IVA; 21 como valor por defecto si no se resuelve.
     */
    static function get_iva_percentage_for_pivot($article_data)
    {
        /** @var float|string|int $iva_percentage Alícuota por defecto cuando no hay IVA explícito. */
        $iva_percentage = 21;

        if (isset($article_data['iva']) && isset($article_data['iva']['percentage'])) {
            $iva_percentage = $article_data['iva']['percentage'];
        } elseif (isset($article_data['iva_id']) && !is_null($article_data['iva_id'])) {
            $iva_model = Iva::find($article_data['iva_id']);
            if (!is_null($iva_model)) {
                $iva_percentage = $iva_model->percentage;
            }
        } elseif (isset($article_data['id'])) {
            $article_model = Article::find($article_data['id']);
            if (!is_null($article_model) && !is_null($article_model->iva_id)) {
                $iva_model = Iva::find($article_model->iva_id);
                if (!is_null($iva_model)) {
                    $iva_percentage = $iva_model->percentage;
                }
            }
        }

        return Self::normalize_iva_percentage_for_pivot($iva_percentage);
    }

    /**
     * Normaliza el valor de IVA que se va a persistir en las columnas iva_percentage de los pivots
     * (article_sale y article_current_acount).
     *
     * POR QUE ESAS COLUMNAS SON DE TEXTO Y NO DECIMALES (grupo 275, 30/7/2026):
     * ivas.percentage es una columna string que guarda tanto alicuotas numericas ('21', '10.5')
     * como etiquetas fiscales ('Exento', 'No Gravado'). Los pivots nacieron decimal(8,2) y cualquier
     * venta con un articulo exento se caia entera con un error de MySQL. No se puede colapsar
     * 'Exento' y 'No Gravado' a 0: ante ARCA son alicuotas distintas de 0%, y el desglose de IVA del
     * comprobante deja de cerrar. Por eso la columna espeja el tipo de su fuente. Si alguna vez se
     * quiere volver a un tipo numerico, primero hay que separar la etiqueta fiscal del porcentaje en
     * la tabla ivas, no antes.
     *
     * @param mixed $value Valor resuelto desde ivas.percentage o el default.
     * @return string|null Texto a persistir, o null si no hay valor utilizable.
     */
    static function normalize_iva_percentage_for_pivot($value)
    {
        if (is_null($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Obtiene precio unitario sin IVA para persistir en article_sale.
     *
     * @param array $article_data Datos de artículo recibidos desde request/flujo interno.
     * @param float|int|string $price_with_iva Precio unitario con IVA.
     * @return float
     */
    static function get_price_sin_iva($article_data, $price_with_iva) {
        /**
         * Se parsea como float para normalizar entradas string provenientes de formularios.
         */
        $price_with_iva = (float) $price_with_iva;
        /**
         * Valor por defecto cuando no se logra resolver IVA del artículo.
         */
        $iva_percentage = 21;

        if (isset($article_data['iva']) && isset($article_data['iva']['percentage'])) {
            $iva_percentage = $article_data['iva']['percentage'];
        } else if (isset($article_data['iva_id']) && !is_null($article_data['iva_id'])) {
            $iva_model = Iva::find($article_data['iva_id']);
            if (!is_null($iva_model)) {
                $iva_percentage = $iva_model->percentage;
            }
        } else if (isset($article_data['id'])) {
            $article_model = Article::find($article_data['id']);
            if (!is_null($article_model) && !is_null($article_model->iva_id)) {
                $iva_model = Iva::find($article_model->iva_id);
                if (!is_null($iva_model)) {
                    $iva_percentage = $iva_model->percentage;
                }
            }
        }

        /**
         * Si la condición IVA no es numérica gravada, se conserva precio original.
         */
        if (
            $iva_percentage === 'No Gravado'
            || $iva_percentage === 'Exento'
            || (float) $iva_percentage == 0
        ) {
            return round($price_with_iva, 2);
        }

        return round($price_with_iva / (((float) $iva_percentage / 100) + 1), 2);
    }

    static function attachPromocionVinotecas($sale, $promocion_vinotecas, $previus_promos) {
        foreach ($promocion_vinotecas as $promo) {
            if (isset($promo['is_promocion_vinoteca'])) {
                $sale->promocion_vinotecas()->attach($promo['id'], [
                                                            'amount' => (float)$promo['amount'],
                                                            'price' => $promo['price_vender'],
                                                            'created_at' => Carbon::now(),
                                                        ]);
                PromocionVinotecaHelper::discount_stock_promocion_vinoteca($sale, $promo, $previus_promos);
            }
        }
    }

    static function attachCombos($sale, $combos, $previus_combos) {
        foreach ($combos as $combo) {
            if (isset($combo['is_combo'])) {
                $sale->combos()->attach($combo['id'], [
                                                            'amount' => (float)$combo['amount'],
                                                            'price' => $combo['price_vender'],
                                                            'created_at' => Carbon::now(),
                                                        ]);

                ComboHelper::discount_articles_stock($sale, $combo, $previus_combos);
            }
        }
    }

    static function attachServices($sale, $services) {
        foreach ($services as $service) {
            if (isset($service['is_service'])) {
                $sale->services()->attach($service['id'], [
                    'price' => $service['price_vender'],
                    'amount' => $service['amount'],
                    'returned_amount'   => Self::getReturnedAmount($service),
                    'discount' => Self::getDiscount($service),
                ]);
            }
        }
    }

    static function updateCurrentAcountsAndCommissions($sale) {

        /*
            Se evalua ANTES de borrar si la venta va a volver a la cuenta corriente, con las
            mismas dos condiciones que decide create_current_acount(). El comportamiento no
            cambia -una venta que el usuario paso a omitida tiene que salir de la cuenta
            corriente-, lo que se gana es el rastro: el bug de San Cayetano (mision 56) saco
            ventas de la cuenta corriente de sus clientes y no dejo una sola linea en ningun lado,
            porque el borrado y la no-recreacion son dos pasos que por separado parecen correctos.
        */
        if (!is_null($sale->client_id) && !Self::va_a_volver_a_la_cuenta_corriente($sale)) {

            $current_acount_previa = CurrentAcount::where('sale_id', $sale->id)
                                            ->whereNull('haber')
                                            ->first();

            if (!is_null($current_acount_previa)) {

                /*
                    Los dos motivos, no uno: pueden darse a la vez, y el log de una venta que se
                    fue de la cuenta corriente se lee justamente para entender por que.
                */
                $motivos = [];

                if ($sale->omitir_en_cuenta_corriente) {
                    $motivos[] = 'omitir_en_cuenta_corriente vino en '.var_export($sale->omitir_en_cuenta_corriente, true);
                }

                if (!$sale->save_current_acount) {
                    $motivos[] = 'save_current_acount vino en '.var_export($sale->save_current_acount, true);
                }

                Log::info(
                    'SaleHelper: la venta '.$sale->id.' SALE de la cuenta corriente del cliente '
                    .$sale->client_id.'. Motivo: '.implode(' y ', $motivos)
                    .'. Se borra el movimiento '.$current_acount_previa->id.' y no se recrea.'
                );
            }
        }

        Self::deleteCurrentAcountFromSale($sale);
        Self::deleteSellerCommissionsFromSale($sale);

        Self::create_current_acount($sale);
        
        Self::crear_comision($sale);

        if (!$sale->omitir_en_cuenta_corriente) {

            // $sale->client->pagos_checkeados = 0;
            // $sale->client->save();

            $credit_account = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $sale->client_id)
                                        ->where('moneda_id', $sale->moneda_id)
                                        ->first();

            if (!is_null($credit_account)) {
                $sale_current_acount = CurrentAcount::where('sale_id', $sale->id)
                                                    ->where('credit_account_id', $credit_account->id)
                                                    ->whereNull('haber')
                                                    ->first();

                $has_movimientos_posteriores = !is_null($sale_current_acount)
                    && CurrentAcount::where('credit_account_id', $credit_account->id)
                                    ->where('id', '!=', $sale_current_acount->id)
                                    ->where(function ($q) use ($sale_current_acount) {
                                        $q->where('created_at', '>', $sale_current_acount->created_at)
                                          ->orWhere(function ($q2) use ($sale_current_acount) {
                                              $q2->where('created_at', '=', $sale_current_acount->created_at)
                                                 ->where('id', '>', $sale_current_acount->id);
                                          });
                                    })
                                    ->exists();

                if ($has_movimientos_posteriores) {
                    Log::info('Recalculando saldos');
                    CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);
                } else {
                    Log::info('No se van a recalcular saldos');
                }
            }
        }

    }

    static function deleteCurrentAcountFromSale($sale) {
        $current_acount = CurrentAcount::where('sale_id', $sale->id)
                                        ->whereNull('haber')
                                        ->first();
        if (!is_null($current_acount)) {



            /*
                Chequeo si habia algun pago especifico (con to_pay_id) para esta venta.
                Si lo habia, lo libero para que aporte a otras ventas.

                Van TODOS, no el primero: desde el grupo 327 la nota de credito de una
                devolucion tambien apunta con to_pay_id a la venta que la origino, asi que
                una misma venta puede tener a la vez esa NC y un pago imputado a mano. Si se
                libera solo uno, el otro queda apuntando a una fila borrada y
                CurrentAcountPagoHelper::setSinPagar() lo resuelve en null: ese movimiento
                deja de imputarse por completo, sin error visible.
            */
            $pagos_dirigidos = CurrentAcount::where('to_pay_id', $current_acount->id)
                                            ->get();

            foreach ($pagos_dirigidos as $pago_dirigido) {
                $pago_dirigido->to_pay_id = null;
                $pago_dirigido->save();
            }


            // Elimino current_acount de la venta
            $current_acount->pagado_por()->detach();
            $current_acount->delete();
        }
    }

    static function deleteSellerCommissionsFromSale($sale) {
        $seller_commissions = SellerCommission::where('sale_id', $sale->id)
                                            ->whereNull('haber')
                                            ->get(['id', 'seller_id', 'moneda_id']);

        // Grupo 268 · Prompt 02, bug E: antes se borraba sin recalcular los saldos posteriores.
        // Se guardan los pares seller_id + moneda_id afectados ANTES de destruir las filas.
        $pares = [];
        foreach ($seller_commissions as $seller_commission) {
            $moneda_id = !is_null($seller_commission->moneda_id) ? $seller_commission->moneda_id : 1;
            $pares[$seller_commission->seller_id.'-'.$moneda_id] = [
                'seller_id' => $seller_commission->seller_id,
                'moneda_id' => $moneda_id,
            ];
        }

        SellerCommission::destroy($seller_commissions->pluck('id'));

        foreach ($pares as $par) {
            ComisionesHelper::recalcular_saldos($par['seller_id'], $par['moneda_id']);
        }
    }

    static function getDiscount($item) {
        if (isset($item['discount'])) {
            return $item['discount'];
        }
        return null;
    }

    static function getAmount($sale, $article) {
        if ($sale->confirmed && isset($article['checked_amount']) && !is_null($article['checked_amount'])) {
            return (float)$article['checked_amount'];
        }
        return (float)$article['amount'];
    }

    static function getCheckedAmount($sale, $article) {
        if (isset($article['checked_amount']) && !is_null($article['checked_amount'])) {
            if ($sale->confirmed && isset($article['checked_amount']) && !is_null($article['checked_amount']) && (float)$article['checked_amount'] > 0) {
                return null;
            }
            return $article['checked_amount'];
        }
        return null;
    }

    static function getArticleVariantId($article) {
        if (isset($article['article_variant_id']) && $article['article_variant_id'] != 0) {
            return $article['article_variant_id'];
        }
        return null;
    }

    static function get_price_type_personalizado($article) {
        if (isset($article['price_type_personalizado_id']) && $article['price_type_personalizado_id'] != 0) {
            return $article['price_type_personalizado_id'];
        }
        return null;
    }

    static function getVariantDescription($article) {
        if (isset($article['article_variant_id']) && $article['article_variant_id'] != 0) {
            $article_variant = ArticleVariant::find($article['article_variant_id']);
            
            if (!is_null($article_variant)) {
                return $article_variant->variant_description;
            }
        }
        return null;
    }

    /**
     * Resuelve el nombre personalizado para persistir en article_sale.
     * Solo guarda valor si el operador modificó el nombre respecto al artículo.
     *
     * @param  array  $article  Datos del ítem enviados desde vender.
     * @return string|null
     */
    static function get_custom_name_for_pivot($article)
    {
        if (!isset($article['name_vender_personalizado'])) {
            return null;
        }

        $custom_name = trim((string) $article['name_vender_personalizado']);
        if ($custom_name === '') {
            return null;
        }

        $article_base_name = '';
        if (isset($article['name'])) {
            $article_base_name = trim((string) $article['name']);
        } else {
            $article_model = Article::find($article['id']);
            if (!is_null($article_model)) {
                $article_base_name = trim((string) $article_model->name);
            }
        }

        if ($custom_name === $article_base_name) {
            return null;
        }

        return $custom_name;
    }

    static function getReturnedAmount($item) {
        if (isset($item['returned_amount'])) {
            return $item['returned_amount'];
        }
        return null;
    }

    static function getDeliveredAmount($item) {
        if (isset($item['delivered_amount'])) {
            return $item['delivered_amount'];
        }
        return null;
    }

    static function getCost($sale, $item) {
        $user = $sale->user;
        Log::info('getCost');

        if (is_object($item)) {
            $item = json_decode(json_encode($item), true);
        }

        $cost = null;

        // Si se esta actualizando, se retorna el valor que estaba guardado (ya cotizado)
        if (
            isset($item['pivot'])
            && isset($item['pivot']['cost'])
        ) {
            Log::info('retornando del pivot: '.$item['pivot']['cost']);
            $cost = (float) $item['pivot']['cost'];
            return $cost;
        }


        // Solo truvari
        if (
            !$cost
            && isset($item['presentacion'])
        ) {

            $item_cost = (float)$item['cost'];
            if (isset($item['costo_real'])) {
                $item_cost = (float)$item['costo_real'];
            }
            
            $cost =  $item_cost * (float)$item['presentacion'];
        }


        if (!$cost) {
            if (isset($item['costo_real'])) {
                $cost = (float)$item['costo_real'];
            } else if (isset($item['cost'])) {
                $cost = (float)$item['cost'];
            }
        }

        Log::info('cost: '.$cost);

        if ($cost > 0) {

            
            if ($sale->moneda_id == 1) {
                // Pesos
                if (
                    isset($item['cost_in_dollars']) 
                    && $item['cost_in_dollars'] == 1
                    // && (
                    //     $user
                    //     && $user->cotizar_precios_en_dolares == 0
                    // )
                ) {
                    $cost *= (float)$sale->valor_dolar;
                }

            } else if ($sale->moneda_id == 2) {

                if (
                    $item['cost_in_dollars'] == 0
                    || $item['cost_in_dollars'] == '0'
                    || is_null($item['cost_in_dollars'])
                ) {
                    $cost /= (float)$sale->valor_dolar;
                }
            } 
        }

        if (!is_null($user) && $user->aplicar_descuentos_de_venta_a_costos) {
            
            foreach ($sale->discounts as $discount) {
                $cost -= $cost * $discount->pivot->percentage / 100;
            }
            foreach ($sale->surchages as $surchage) {
                $cost += $cost * $surchage->pivot->percentage / 100;
            }
        }

        if (
            isset($item['unidades_individuales'])
            && $item['unidades_individuales']
            && (float)$item['unidades_individuales'] > 0
        ) {
            $cost /= (float)$item['unidades_individuales'];
        }

        return $cost;
    }

    /**
     * Calcula el factor multiplicativo que en `getCost` se aplica al costo unitario
     * cuando el usuario tiene `aplicar_descuentos_de_venta_a_costos`: por cada descuento
     * de la venta se multiplica por (1 − porcentaje/100) y por cada recargo por (1 + porcentaje/100).
     *
     * Equivale al producto de los pasos de los bucles sobre `discounts` y `surchages` en `getCost`.
     *
     * @param \App\Models\Sale $sale Venta con relaciones `discounts` y `surchages` cargadas (pivote con `percentage`).
     * @return float|null Factor (> 0). `null` si el factor sería 0 (p. ej. descuento 100 %), no se puede revertir dividiendo.
     */
    static function sale_cost_factor_from_sale_discounts_and_surchages($sale) {
        /**
         * Acumulador del factor aplicado al costo antes de persistir en `article_sale.cost`.
         */
        $factor = 1.0;

        foreach ($sale->discounts as $discount) {
            /**
             * Porcentaje de descuento de venta asociado por pivote.
             */
            $percentage = (float) $discount->pivot->percentage;
            $factor *= (1 - $percentage / 100);
        }

        foreach ($sale->surchages as $surchage) {
            /**
             * Porcentaje de recargo de venta asociado por pivote.
             */
            $percentage = (float) $surchage->pivot->percentage;
            $factor *= (1 + $percentage / 100);
        }

        if ($factor <= 0) {
            return null;
        }

        return $factor;
    }

    /**
     * Revierte en la tabla pivote `article_sale` el efecto de descuentos y recargos a nivel venta sobre
     * el costo unitario guardado (inverso de la rama de `getCost` que aplica porcentajes de la venta).
     * Recalcula `ganancia` en pivote como (precio unitario − costo unitario) × cantidad, coherente con `attachArticle`.
     *
     * No es idempotente: ejecutar dos veces sobre la misma venta volvería a dividir costos ya corregidos.
     *
     * @param \App\Models\Sale $sale Venta con `articles`, `discounts`, `surchages` y `user` cargados.
     * @return array{articles_updated: int, reason_skipped: string|null} Conteo de filas pivote actualizadas o motivo de omisión.
     */
    static function restore_article_pivot_costs_without_sale_discounts($sale) {
        /**
         * Usuario dueño de la venta; misma referencia que usa `getCost` para el flag de costos.
         */
        $user = $sale->user;

        // if (is_null($user) || !(bool) $user->aplicar_descuentos_de_venta_a_costos) {
        //     return [
        //         'articles_updated' => 0,
        //         'reason_skipped' => 'user_missing_or_flag_off',
        //     ];
        // }

        /**
         * Sin descuentos ni recargos de venta, el factor es 1 y no hay nada que revertir a nivel venta.
         */
        if ($sale->discounts->isEmpty() && $sale->surchages->isEmpty()) {
            return [
                'articles_updated' => 0,
                'reason_skipped' => 'no_sale_discounts_or_surchages',
            ];
        }

        /**
         * Factor por el que hubo que dividir el costo persistido para obtener el costo “sin” dto/rec de venta.
         */
        $factor = Self::sale_cost_factor_from_sale_discounts_and_surchages($sale);

        if (is_null($factor)) {
            return [
                'articles_updated' => 0,
                'reason_skipped' => 'invalid_zero_factor',
            ];
        }

        /**
         * Contador de filas en pivote actualizadas.
         */
        $articles_updated = 0;

        foreach ($sale->articles as $article) {
            /**
             * Costo unitario actualmente guardado (con dto/rec de venta ya aplicados al crearse, si correspondía).
             */
            $stored_unit_cost = (float) $article->pivot->cost;

            /**
             * Costo unitario restaurado (antes de aplicar porcentajes de la venta al costo).
             */
            $restored_unit_cost = $stored_unit_cost / $factor;

            /**
             * Precio unitario en pivote y cantidad para recalcular ganancia como en `attachArticle`.
             */
            $unit_price = (float) $article->pivot->price;
            $amount = (float) $article->pivot->amount;

            /**
             * Ganancia total en línea: (precio − costo unitario restaurado) × cantidad.
             */
            $ganancia_line = ($unit_price - $restored_unit_cost) * $amount;

            $sale->articles()->updateExistingPivot($article->id, [
                'cost' => $restored_unit_cost,
                'ganancia' => $ganancia_line,
            ]);

            $articles_updated++;
        }

        return [
            'articles_updated' => $articles_updated,
            'reason_skipped' => null,
        ];
    }

    static function getDolar($article, $dolar_blue) {
        if (isset($article['with_dolar']) && $article['with_dolar']) {
            return $dolar_blue;
        }
        return null;
    }

    static function detachItems($sale, $sale_modification) {

        SaleModificationsHelper::attach_articulos_antes_de_actualizar($sale, $sale_modification);

        $sale->articles()->detach();
        $sale->combos()->detach();
        $sale->services()->detach();
        $sale->promocion_vinotecas()->detach();
    }

    static function restaurar_stock($sale) {
        foreach ($sale->articles as $article) {
            if (count($article->addresses) >= 1 && !is_null($sale->address_id)) {
                foreach ($article->addresses as $article_address) {
                    if ($article_address->pivot->address_id == $sale->address_id) {
                        $new_amount = $article_address->pivot->amount + $article->pivot->amount;
                        $article->addresses()->updateExistingPivot($article_address->id, [
                            'amount'    => $new_amount,
                        ]);
                    }
                }
            } else if (!is_null($article->stock)) {
                $stock = 0;
                $stock = (int)$article->pivot->amount;
                $article->stock += $stock;
                $article->save();
            }
            // Self::deleteStockMovement($sale, $article);
        }
    }

    static function deleteStockMovement($sale, $article) {
        $stock_movement = StockMovement::where('sale_id', $sale->id)
                                        ->where('article_id', $article->id)
                                        ->first();
        if (!is_null($stock_movement)) {
            $stock_movement->delete();
        }
    }

    static function get_sub_total($sale) {
        $total_articles = 0;
        $total_combos = 0;
        $total_services = 0;
        $total_promocion_vinotecas = 0;

        $sale->load('articles');
        $sale->load('combos');
        $sale->load('promocion_vinotecas');
        $sale->load('services');
        

        foreach ($sale->articles as $article) {
            $total_articles += Self::getTotalItem($article);
        }
        foreach ($sale->combos as $combo) {
            $total_combos += Self::getTotalItem($combo);
        }
        foreach ($sale->promocion_vinotecas as $promocion_vinoteca) {
            $total_promocion_vinotecas += Self::getTotalItem($promocion_vinoteca);
        }
        foreach ($sale->services as $service) {
            $total_services += Self::getTotalItem($service);
        }

        $sub_total = $total_articles + $total_combos + $total_promocion_vinotecas + $total_services;

        
        return $sub_total;
    }

    static function getTotalSale($sale, $with_discount = true, $with_surchages = true, $with_seller_commissions = false, $load_info = false) {
        $total_articles = 0;
        $total_combos = 0;
        $total_services = 0;
        $total_promocion_vinotecas = 0;

        if ($load_info) {
            $sale->load('articles');
            $sale->load('combos');
            $sale->load('promocion_vinotecas');
            $sale->load('services');
            $sale->load('discounts');
            $sale->load('surchages');
        }
        

        foreach ($sale->articles as $article) {
            $total_articles += Self::getTotalItem($article);
        }
        foreach ($sale->combos as $combo) {
            $total_combos += Self::getTotalItem($combo);
        }
        foreach ($sale->promocion_vinotecas as $promocion_vinoteca) {
            $total_promocion_vinotecas += Self::getTotalItem($promocion_vinoteca);
        }
        foreach ($sale->services as $service) {
            $total_services += Self::getTotalItem($service);
        }

        $sub_total = $total_articles + $total_combos + $total_promocion_vinotecas + $total_services;

        /*
            `sales.descuento` ES PORCENTAJE (confirmado por Lucas, 24/8/2026) y se aplica SOLO
            al total de los articulos, igual que la SPA (`vender_set_total.js::aplicar_descuento()`:
            "Aplicando descuento del X% solo al total de los articulos"). Hasta esta tanda aca se
            restaba como MONTO fijo, y toda venta con descuento que pasara por este metodo (la
            confirmacion de una venta chequeada, set_total_sales) quedaba con un total distinto
            del que calculo el front y del que factura AfipItemCalculator (que siempre lo aplico
            como porcentaje, renglon por renglon).

            La guarda truthy (y no `> 0`) es a proposito: un descuento NEGATIVO es como el
            sistema representa un recargo global, y la SPA lo aplica igual (`if (this.descuento)`).
            Mismo criterio que PuntosBaseHelper::factor_descuentos_de_venta().
        */
        if ($sale->descuento) {
            $total_articles -= $total_articles * $sale->descuento / 100;
        }

        if ($with_discount) {
            foreach ($sale->discounts as $discount) {
                $total_articles -= $total_articles * $discount->pivot->percentage / 100;
                $total_combos -= $total_combos * $discount->pivot->percentage / 100;
                $total_promocion_vinotecas -= $total_promocion_vinotecas * $discount->pivot->percentage / 100;

                if ($sale->discounts_in_services) {
                    $total_services -= $total_services * $discount->pivot->percentage / 100;
                }
            }
        }

        if (
            $with_surchages
            && !$sale->aplicar_recargos_directo_a_items
        ) {
            foreach ($sale->surchages as $surchage) {
                $total_articles += $total_articles * $surchage->pivot->percentage / 100;
                $total_combos += $total_combos * $surchage->pivot->percentage / 100;
                $total_promocion_vinotecas += $total_promocion_vinotecas * $surchage->pivot->percentage / 100;

                if ($sale->surchages_in_services) {
                    $total_services += $total_services * $surchage->pivot->percentage / 100;
                }
            }
        }

        $total = $total_articles + $total_services + $total_combos + $total_promocion_vinotecas;
        

        if ($sale->cuota_id) {
            
        }

        foreach ($sale->current_acount_payment_methods as $payment_method) {
            // if ($payment_method->pivot->cuota_id)
        }

       

        if ($with_seller_commissions) {
            foreach ($sale->seller_commissions as $seller_commission) {
                $total -= $seller_commission->debe;
            }
        }
        return $total;
    }

    static function total_menos_comisiones($sale) {
        $total = $sale->total;
        foreach ($sale->seller_commissions as $seller_commission) {
            $total -= $seller_commission->debe;
        }
        return $total;
    }

    static function getTotalItem($item) {
        $amount = $item->pivot->amount;
        // if (!is_null($item->pivot->returned_amount)) {
        //     $amount -= $item->pivot->returned_amount;
        // }
        // Log::info('getTotalItem:');
        // Log::info('amount: '.$amount);
        // Log::info('price: '.$item->pivot->price);
        $total = $item->pivot->price * $amount;
        // Log::info('total: '.$total);
        if (!is_null($item->pivot->discount)) {
            $total -= $total * ($item->pivot->discount / 100);
        }
        return $total;
    }

    static function getTotalSaleFromArticles($sale, $articles) {
        $total = 0;
        foreach ($articles as $article) {
            if (!is_null($sale->percentage_card)) {
                $total += ($article->pivot->price * Numbers::percentage($sale->percentage_card)) * $article->pivot->amount;
            } else {
                $total += $article->pivot->price * $article->pivot->amount;
            }
        }
        return $total;
    }

    static function getTotalCostSale($sale) {
        $total = 0;
        foreach ($sale->articles as $article) {
            if (!is_null($article->pivot->cost)) {
                $total += $article->pivot->cost * $article->pivot->amount;
            }
        }
        return $total;
    }

    static function isSaleType($sale_type_name, $sale) {
        $sale_type = SaleType::where('user_id', UserHelper::userId())
                                    ->where('name', $sale_type_name)
                                    ->first();
        if (!is_null($sale_type) && $sale->sale_type_id == $sale_type->id) {
            return true;
        } 
        return false;
    }

    static function getPrecioConDescuento($sale) {
        // $discount = DiscountHelper::getTotalDiscountsPercentage($sale->discounts, true);
        $total = Self::getTotalSale($sale);
        foreach ($sale->discounts as $discount) {
            $total -= $total * Numbers::percentage($discount->pivot->percentage); 
        }
        return $total;
        // return Self::getTotalSale($sale) - (Self::getTotalSale($sale) * Numbers::percentage($discount));
    }

    static function getPrecioConDescuentoFromArticles($sale, $articles) {
        $discount = DiscountHelper::getTotalDiscountsPercentage($sale->discounts, true);
        $total = 0;
        foreach ($articles as $article) {
            if (!is_null($sale->percentage_card)) {
                $total += ($article->pivot->price * Numbers::percentage($sale->percentage_card)) * $article->pivot->amount;
            } else {
                $total += $article->pivot->price * $article->pivot->amount;
            }
        }
        return $total - ($total * Numbers::percentage($discount));
    }

    static function getTotalWithDiscountsAndSurchages($sale, $total_articles, $total_combos, $total_services) {
        foreach ($sale->discounts as $discount) {
            // Log::info('total_services: '.$total_services);
            if ($sale->discounts_in_services) {
                // Log::info('restando '.$total_services * Numbers::percentage($discount->pivot->percentage).' a los servicios');
                $total_services -= $total_services * Numbers::percentage($discount->pivot->percentage);
            } else {
                // Log::info('No se resto a los servicios');
            }
            // Log::info('total_services quedo en: '.$total_services);

            // Log::info('------------------------------------');
            // Log::info('total_articles: '.$total_articles);
            $total_articles -= $total_articles * Numbers::percentage($discount->pivot->percentage);
            // Log::info('total_articles quedo en: '.$total_articles);

            // Log::info('------------------------------------');
            // Log::info('total_combos: '.$total_combos);
            $total_combos -= $total_combos * Numbers::percentage($discount->pivot->percentage);
            // Log::info('total_combos quedo en: '.$total_combos);
        }
        foreach ($sale->surchages as $surchage) {
            if ($sale->surchages_in_services) {
                $total_services += $total_services * Numbers::percentage($surchage->pivot->percentage);
            }
            $total_articles += $total_articles * Numbers::percentage($surchage->pivot->percentage);
            $total_combos += $total_combos * Numbers::percentage($surchage->pivot->percentage);
        }
        if (!is_null($sale->order) && !is_null($sale->order->cupon)) {
            if (!is_null($sale->order->cupon->percentage)) {
                $total -= $total * $sale->order->cupon->percentage / 100;
            } else if (!is_null($sale->order->cupon->amount)) {
                $total -= $sale->order->cupon->amount;
            }
        }
        $total = $total_articles + $total_combos + $total_services;
        Log::info('------------------------------------');
        Log::info('retornando '.$total);

        return $total;
    }

    static function getTotalMenosDescuentos($sale, $total) {
        foreach ($sale->discounts as $discount) {
            $total -= $total * Numbers::percentage($discount->pivot->percentage);
        }
        return $total;
    }

    static function get_fecha_agregado_map_for_normal_articles($request_items, $previus_articles)
    {
        $now = Carbon::now();

        // Mapa: article_id => fecha_agregado previa (para preservarla si ya existía)
        $previus_fecha_agregado_by_id = [];
        $previus_ids = [];

        foreach ($previus_articles as $article) {
            $id = (int)$article->id;
            $previus_ids[$id] = true;

            // preserva si ya tenía fecha (por un update anterior); si no, queda null
            $previus_fecha_agregado_by_id[$id] = $article->pivot->fecha_agregado ?? null;
        }

        // ids "normales" que vienen en el request (sin varios_precios)
        $new_normal_ids = [];
        foreach ($request_items as $item) {
            if (!isset($item['is_article'])) {
                continue;
            }
            if (isset($item['varios_precios']) && is_array($item['varios_precios'])) {
                // NO nos interesa para fecha_agregado
                continue;
            }
            $new_normal_ids[(int)$item['id']] = true;
        }

        // Armar map final: article_id => fecha_agregado a guardar
        $result = [];

        foreach (array_keys($new_normal_ids) as $article_id) {

            $existed_before = isset($previus_ids[$article_id]);

            if (!$existed_before) {
                // NUEVO artículo normal agregado en este update
                $result[$article_id] = $now;
            } else {
                // Ya existía: preservar (probablemente null si era de creación)
                $result[$article_id] = $previus_fecha_agregado_by_id[$article_id] ?? null;
            }
        }

        return $result;
    }

    // static function build_article_sale_key_from_item($item)
    // {
    //     $article_id = (int) $item['id'];

    //     $article_variant_id = $item['article_variant_id'] ?? null;
    //     $price_type_personalizado_id = $item['price_type_personalizado_id'] ?? null;

    //     $price = $item['price_vender'] ?? null;

    //     return implode('|', [
    //         (string)$article_id,
    //         (string)($article_variant_id ?? ''),
    //         // (string)($price_type_personalizado_id ?? ''),
    //         // (string)($price ?? ''),
    //     ]);
    // }

    // static function get_fecha_agregado_for_item($item, $fecha_agregado_map)
    // {
    //     $key = Self::build_article_sale_key_from_item($item);
    //     return $fecha_agregado_map[$key] ?? null;
    // }
}
