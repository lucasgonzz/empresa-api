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
use App\Http\Controllers\Helpers\SaleModificationsHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\sale\SaleCajaHelper;
use App\Http\Controllers\Helpers\sale\UpdateHelper;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SellerCommissionController;
use App\Http\Controllers\StockMovementController;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\Cart;
use App\Models\Client;
use App\Models\Commissioner;
use App\Models\CurrentAcount;
use App\Models\Discount;
use App\Models\Sale;
use App\Models\SaleType;
use App\Models\SellerCommission;
use App\Models\Service;
use App\Models\StockMovement;
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

    static function get_terminada($to_check) {
        if (UserHelper::hasExtencion('check_sales') && $to_check) {
            return 0;
        }
        return 1;
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

    static function setPrinted($instance, $sale, $confirmed) {
        if (UserHelper::hasExtencion('check_sales') && $confirmed) {
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
            $ct = new AfipWsController($sale, false);
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

    static function attachProperies($model, $request, $from_store = true, $previus_articles = null, $sale_modification = null, $se_esta_confirmando_por_primera_vez = false) {

        Self::attachArticles($model, $request->items, $previus_articles, $se_esta_confirmando_por_primera_vez);
        

        Self::attachCombos($model, $request->items);
        Self::attachServices($model, $request->items);
        
        Self::attachDiscounts($model, $request->discounts);
        Self::attachSurchages($model, $request->surchages);

        Self::attachSelectedPaymentMethods($model, $request);
        
        if (!$from_store) {
            SaleModificationsHelper::attach_articulos_despues_de_actualizar($model, $sale_modification);
            
            UpdateHelper::check_articulos_eliminados($model, $request->items, $previus_articles, $se_esta_confirmando_por_primera_vez);
        }

        /*
            * Si se esta confirmando, el total que llega de vender esta mal
                porque no tiene en cuenta las unidades chequedas, las cuales ahora
                pasan a ser las unidades reales del articulo en la venta
            * Entonces se setea el total desde aca para tener eso en cuenta
        */
        if ($se_esta_confirmando_por_primera_vez) {
            Self::update_total_sale($model);
        }


        if ($from_store && !$model->to_check && !$model->checked) {
            
            Self::create_current_acount($model);
            
            Self::crear_comision($model);

            SaleCajaHelper::check_caja($model);

        } else {

            Self::checkNotaCredito($model, $request);
        }
    }

    static function set_total_a_facturar($sale, $request) {
        if (!is_null($sale->afip_information_id) && $sale->afip_information_id != 0) {


            $afip_helper = new AfipHelper($sale);
            $importes = $afip_helper->getImportes();
            Log::info('pidiendo total_a_facturar: '.$importes['total']);

            $sale->total_a_facturar = $importes['total'];
            $sale->save();
        }
    }

    static function attachSelectedPaymentMethods($sale, $request){

        if (is_null($sale->client_id) || $sale->omitir_en_cuenta_corriente) {
            $sale->current_acount_payment_methods()->detach();

            if (!$request->current_acount_payment_method_id) {
                foreach ($request->selected_payment_methods as $payment_method) {

                    if (!is_null($payment_method['amount'])) {

                        $amount = $payment_method['amount'];

                        if ($payment_method['id'] == 5 
                            && isset($request->monto_credito_real)
                            && !is_null($request->monto_credito_real)) {

                            $amount = $request->monto_credito_real;
                        }

                        $caja_id = null;
                        if (isset($payment_method['caja_id'])) {
                            $caja_id = $payment_method['caja_id'];
                        }
                        
                        $sale->current_acount_payment_methods()->attach($payment_method['id'],[
                            'amount'    => $amount,
                            'caja_id'   => $caja_id,
                        ]);
                    }
                }
            } else {

                $total = (float)$sale->total;

                if (!is_null($request->discount_amount)) {
                    $total += (float)$request->discount_amount;
                }

                $sale->current_acount_payment_methods()->attach($request->current_acount_payment_method_id, [
                    'amount'                => $total,
                    'discount_percentage'   => $request->discount_percentage,
                    'discount_amount'       => $request->discount_amount,
                    'caja_id'               => $request->caja_id,
                ]);
            }
        }

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

            $nota_credito = CurrentAcountHelper::notaCredito(
                $haber, 
                $request->nota_credito_description, 
                'client', 
                $request->client_id, 
                $sale->id, 
                $request->returned_items
            );

            CurrentAcountHelper::checkSaldos('client', $request->client_id);

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

    static function createNotaCreditoFromDestroy($sale) {
        $haber = $sale->total;

        $articles = [];
        foreach ($sale->articles as $article) {
            // $haber += $article->pivot->price * $article->pivot->amount;
            $article_to_add = [
                'is_article'        => true,
                'id'                => $article->id,
                'returned_amount'   => $article->pivot->amount,
                'price_vender'      => $article->pivot->price,
                'discount'          => $article->pivot->discount,
            ];
            $articles[] = $article_to_add;
        }
        // if (count($sale->discounts) >= 1) {
        //     foreach ($sale->discounts as $discount) {
        //         $haber -= (float)$discount->pivot->percentage * $haber / 100;
        //     }
        // }
        // if (count($sale->surchages) >= 1) {
        //     foreach ($sale->surchages as $surchage) {
        //         $haber += (float)$surchage->pivot->percentage * $haber / 100;
        //     }
        // }
        

        $description = 'Venta N°'.$sale->num.' eliminada';

        $model_name = 'client';

        if ($sale->omitir_en_cuenta_corriente) {
            // Si entra aca se pone null, para que la nota de credito no se la asigne a ningun cliente
            $model_name = null;
        }

        $nota_credito = CurrentAcountHelper::notaCredito($haber, $description, $model_name, $sale->client_id, $sale->id, $articles);

        if (!is_null($sale->client) && !$sale->omitir_en_cuenta_corriente) {
            CurrentAcountHelper::checkSaldos('client', $sale->client_id);
        }

        $afip_helper = new AfipNotaCreditoHelper($sale, $nota_credito);
        $afip_helper->init();
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

        return null;
    }

    static function create_current_acount($sale) {
        if (!is_null($sale->client_id) 
            && $sale->save_current_acount
            && !$sale->omitir_en_cuenta_corriente) {
            
            $helper = new CurrentAcountFromSaleHelper($sale);
            $helper->crear_current_acount();
        }
    }

    static function crear_comision($sale) {
        if (!is_null($sale->seller_id)) {
            
            $helper = new ComisionesHelper($sale);
            $helper->crear_comision();
        }
    }

    static function attachArticles($sale, $articles, $previus_articles, $se_esta_confirmando_por_primera_vez) {
        
        foreach ($articles as $article) {
            if (isset($article['is_article'])) {

                if (isset($article['varios_precios']) && is_array($article['varios_precios'])) {

                    foreach ($article['varios_precios'] as $otro_precio) {

                        $otro_precio['id'] = $article['id'];

                        if ($otro_precio['amount'] == '') {
                            $otro_precio['amount'] = 1;
                        }

                        Self::attachArticle($sale, $otro_precio);

                    }
                } else {

                    $amount = Self::getAmount($sale, $article);
                    
                    if (($sale->to_check || $sale->checked) 
                        || (!is_null($amount) && $amount > 0) ) {
                        Self::attachArticle($sale, $article);
                    }

                }


                if (!$sale->to_check && !$sale->checked && Self::usa_stock($article)) {

                    $amount = Self::getAmount($sale, $article);

                    if (isset($article['article_variant_id'])) {
                        $article_variant_id = $article['article_variant_id'];
                    } else {
                        $article_variant_id = null;
                    }
                    
                    if ($amount > 0) {
                        ArticleHelper::discountStock($article['id'], $amount, $sale, $previus_articles, $se_esta_confirmando_por_primera_vez, $article_variant_id);
                    }
                }

            }
        }
    }

    static function usa_stock($article) {
        $_article = Article::find($article['id']);
        if (!is_null($_article)) {
            return !is_null($_article->stock);
        }
        return false;
    }

    static function attachArticle($sale, $article) {
        $sale->articles()->attach($article['id'], [
            'amount'                => Self::getAmount($sale, $article),
            'cost'                  => Self::getCost($article),
            'price'                 => $article['price_vender'],
            'returned_amount'       => Self::getReturnedAmount($article),
            'delivered_amount'      => Self::getDeliveredAmount($article),
            'discount'              => Self::getDiscount($article),
            'checked_amount'        => Self::getCheckedAmount($sale, $article),
            'article_variant_id'    => Self::getArticleVariantId($article),
            'variant_description'    => Self::getVariantDescription($article),
            'price_type_personalizado_id'    => Self::get_price_type_personalizado($article),
            'created_at'            => Carbon::now(),
        ]);
    }

    static function updateItemsPrices($sale, $items) {
        foreach ($items as $item) {
            if (isset($item['is_article']) && $item['price_vender'] != '') {
                $sale->articles()->updateExistingPivot($item['id'], [
                                                        'price' => $item['price_vender'],
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

    static function attachCombos($sale, $combos) {
        foreach ($combos as $combo) {
            if (isset($combo['is_combo'])) {
                $sale->combos()->attach($combo['id'], [
                                                            'amount' => (float)$combo['amount'],
                                                            'price' => $combo['price'],
                                                            'created_at' => Carbon::now(),
                                                        ]);
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

        Self::deleteCurrentAcountFromSale($sale);
        Self::deleteSellerCommissionsFromSale($sale);

        Self::create_current_acount($sale);
        
        Self::crear_comision($sale);

        if (!$sale->omitir_en_cuenta_corriente) {

            $sale->client->pagos_checkeados = 0;
            $sale->client->save();

            CurrentAcountHelper::checkSaldos('client', $sale->client_id);
        }

    }

    static function deleteCurrentAcountFromSale($sale) {
        $current_acount = CurrentAcount::where('sale_id', $sale->id)
                                        ->whereNull('haber')
                                        ->first();
        if (!is_null($current_acount)) {
            $current_acount->pagado_por()->detach();
            $current_acount->delete();
        }
    }

    static function deleteSellerCommissionsFromSale($sale) {
        $seller_commissions = SellerCommission::where('sale_id', $sale->id)
                                            ->whereNull('haber')
                                            ->pluck('id');
        SellerCommission::destroy($seller_commissions);
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

    static function getCost($item) {
        if (isset($item['costo_real'])) {
            return $item['costo_real'];
        }
        if (isset($item['cost'])) {
            return $item['cost'];
        }
        return null;
    }

    static function getDolar($article, $dolar_blue) {
        if (isset($article['with_dolar']) && $article['with_dolar']) {
            return $dolar_blue;
        }
        return null;
    }

    static function attachArticlesFromOrder($sale, $articles) {
        foreach ($articles as $article) {
            $sale->articles()->attach($article->id, [
                                            'amount' => $article->pivot->amount,
                                            'cost' => isset($article->pivot->cost)
                                                        ? $article->pivot->cost
                                                        : null,
                                            'price' => $article->pivot->price,
                                        ]);
            
        }
    }

    static function detachItems($sale, $sale_modification) {

        SaleModificationsHelper::attach_articulos_antes_de_actualizar($sale, $sale_modification);

        // if (!$sale->to_check && !$sale->checked) {
        //     Self::restaurar_stock($sale);
        // }

        $sale->articles()->detach();
        $sale->combos()->detach();
        $sale->services()->detach();
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

    static function getTotalSale($sale, $with_discount = true, $with_surchages = true, $with_seller_commissions = false) {
        $total_articles = 0;
        $total_combos = 0;
        $total_services = 0;
        foreach ($sale->articles as $article) {
            $total_articles += Self::getTotalItem($article);
        }
        foreach ($sale->combos as $combo) {
            $total_combos += Self::getTotalItem($combo);
        }
        foreach ($sale->services as $service) {
            $total_services += Self::getTotalItem($service);
        }
        if ($with_discount) {
            foreach ($sale->discounts as $discount) {
                $total_articles -= $total_articles * $discount->pivot->percentage / 100;
                $total_combos -= $total_combos * $discount->pivot->percentage / 100;
                if ($sale->discounts_in_services) {
                    $total_services -= $total_services * $discount->pivot->percentage / 100;
                }
            }
        }
        if ($with_surchages) {
            foreach ($sale->surchages as $surchage) {
                $total_articles += $total_articles * $surchage->pivot->percentage / 100;
                $total_combos += $total_combos * $surchage->pivot->percentage / 100;
                if ($sale->surchages_in_services) {
                    $total_services += $total_services * $surchage->pivot->percentage / 100;
                }
            }
        }
        $total = $total_articles + $total_services + $total_combos;
        if (!is_null($sale->percentage_card)) {
            $total += ($total * Numbers::percentage($sale->percentage_card));
        }
        if ($with_seller_commissions) {
            foreach ($sale->seller_commissions as $seller_commission) {
                $total -= $seller_commission->debe;
            }
        }
        return $total;
    }

    static function getTotalItem($item) {
        $amount = $item->pivot->amount;
        // if (!is_null($item->pivot->returned_amount)) {
        //     $amount -= $item->pivot->returned_amount;
        // }
        $total = $item->pivot->price * $amount;
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

}

