<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class NewProviderOrderHelper {

    public $provider_order;
    public $new_articles;
    public $ultimos_articulos_recividos;

	function __construct($provider_order, $new_articles, $ya_se_actualizo_stock = false) {

        $this->provider_order           = $provider_order;
        $this->new_articles             = $new_articles;
        $this->ya_se_actualizo_stock    = $ya_se_actualizo_stock;
        $this->user                     = UserHelper::user();

        $this->set_credit_account();

        $this->set_ultimos_articulos_recividos();

        $this->set_ivas();
    }

    function procesar_pedido() {

        // $this->attach_articles();

        $this->set_totales();

        $this->set_current_acount();
    }

    function set_credit_account() {
        $this->credit_account = CreditAccount::where('model_name', 'provider')
                                                ->where('model_id', $this->provider_order->provider_id)
                                                ->where('moneda_id', $this->provider_order->moneda_id)
                                                ->first();
    }


    /*
        * Si total_from_provider_order_afip_tickets = TRUE
            1. Se calcula $total en base las provider_order_afip_ticket->total
            2. 


        * Si total_from_provider_order_afip_tickets = FALSE
            1. Se calculo $total_articulos en base a los articulos sin tener en cuenta el IVA de cada articulo.
            2. 

    */
    function set_totales() {

        $total_articulos = 0;
        $descuentos_individuales = 0;
        $descuentos_compra = 0;
        $total_descuento = 0;
        $total_costos_extra = 0;
        $total_iva = 0;
        $total = 0;

        $this->provider_order->load([
            'articles',
            'provider_order_afip_tickets',
            'provider_order_discounts',
            'provider_order_extra_costs',
            'provider', // porque en get_total_article lo usás para dólar
        ]);


        $des = [];


        $des[] = 'TOTAL ARTICULOS';
        foreach ($this->provider_order->articles as $article) {

            $res                = $this->get_total_article($article);
            $sub_total_article  = $res['sub_total_article'];
            // $total_article      = $res['total_article'];
            $article_descuento  = $res['total_descuento'];
            // $article_iva        = $res['article_iva']['importe_iva'];

            $total_articulos += $sub_total_article;
            $descuentos_individuales += $article_descuento;

            Log::info('Sumando '.$sub_total_article.' de '.$article->name);
            Log::info('Descuentos de articulo '.$article_descuento);

            $des[] = Numbers::price($sub_total_article, true).' x '.$article->pivot->amount.' u. de '.$article->name;
        }

        if ($total_articulos > 0) {
            if ($descuentos_individuales > 0) {

                $des[] = 'Total articulos (sin descuentos) = '.Numbers::price($total_articulos, true);
            } else {
                $des[] = 'Total articulos = '.Numbers::price($total_articulos, true);
            }
        }

        if ($descuentos_individuales > 0) {

            $des[] = Numbers::price($descuentos_individuales, true).' de descuentos individuales en articulos';  
        }


        if ($this->provider_order->total_from_provider_order_afip_tickets) {

            Log::info('Sumando total de las facturas');

            $des[] = 'CALCULANDO TOTAL EN BASE A FACTURAS';

            foreach ($this->provider_order->provider_order_afip_tickets as $afip_ticket) {
                $total      += $afip_ticket->total;
                $des[] = Numbers::price($afip_ticket->total, true).' de factura N° '.$afip_ticket->code;
            }

            $des[] = 'Total pedido = '.Numbers::price($total, true);
        } else {
            $total += $total_articulos;
        }



        /*
            Sumando IVA que va a venir siempre de los provider_order_afip_tickets
            Estos van a ser creados de forma manual o automatica en base a "modo_facturacion"
            De eso se encarga ModoFacturacionHelper
        */

        if (count($this->provider_order->provider_order_afip_tickets) >= 1) {
            $des[] = 'CALCULANDO IVA EN BASE A FACTURAS';
        }
        foreach ($this->provider_order->provider_order_afip_tickets as $afip_ticket) {

            $total_iva  += $afip_ticket->total_iva;
            $des[] = Numbers::price($afip_ticket->total_iva, true).' de IVA de factura N° '.$afip_ticket->code;
        }
        if ($total_iva > 0) {
            $des[] =  'Total IVA = '.Numbers::price($total_iva, true);
        }


        $this->provider_order->total_iva            = $total_iva;
        $this->provider_order->sub_total            = $total_articulos;


        // Descuentos del pedido

        $total_solo_con_descuentos_individuales = $total_articulos - $descuentos_individuales;

        if (count($this->provider_order->provider_order_discounts) >= 1) {
            $des[] = 'CALCULANDO DESCUENTOS DE COMPRA';
            $des[] = 'Total articulos = '.Numbers::price($total_articulos, true);

            if ($descuentos_individuales > 0) {
                $des[] = 'Descuentos individuales = '.Numbers::price($descuentos_individuales, true);
                $des[] = 'Total articulos con desc aplicado = '.Numbers::price($total_solo_con_descuentos_individuales, true);
            }
        }

        foreach ($this->provider_order->provider_order_discounts as $discount) {

            if (
                !is_null($discount->percentage)
                && $discount->percentage != ''
            ) {

                $monto_descuento = $total_solo_con_descuentos_individuales * (float)$discount->percentage / 100;
                $des[] = 'Menos el '.$discount->percentage.'% de '.Numbers::price($total_solo_con_descuentos_individuales, true).' = '.Numbers::price($monto_descuento, true);
            } else if (
                !is_null($discount->monto)
                && $discount->monto != ''
            ) {

                $monto_descuento = $discount->monto;
                $des[] = 'Menos '.Numbers::price($discount->monto, true).' = '.Numbers::price($monto_descuento, true);
                Log::info('menos $'.$discount->monto);
            }


            $descuentos_compra += $monto_descuento;
            $total_solo_con_descuentos_individuales -= $monto_descuento;

            $des[] = 'Descuentos Compra = '.Numbers::price($descuentos_compra, true);


            // Log::info('monto_descuento = '.$monto_descuento);
            // Log::info('total_descuento = '.$total_descuento);
        }

        // if ($total_descuento > 0) {
        //     $des[] = 'Total articulos con descuentos aplicados = '.Numbers::price($total_)
        // }

        $total_descuento = $descuentos_individuales + $descuentos_compra;
        
        $this->provider_order->descuentos_individuales    = $descuentos_individuales;
        $this->provider_order->descuentos_compra          = $descuentos_compra;
        $this->provider_order->total_descuento            = $total_descuento;

        if (!$this->provider_order->total_from_provider_order_afip_tickets) {

            $total_sin_descuento = $total;
            $total -= $total_descuento;

            $des[] = 'APLICANDO DESCUENTOS DE COMPRA';
            $des[] = 'Total articulos sin descuentos = '.Numbers::price($total_sin_descuento, true);
            $des[] = 'Descuento individuales = '.Numbers::price($descuentos_individuales, true);
            $des[] = 'Descuentos de compra = '.Numbers::price($descuentos_compra, true);
            $des[] = 'Total Descuentos = '.Numbers::price($total_descuento, true);
            $des[] = 'Total con descuentos aplicado = '.Numbers::price($total, true);
        }


        if (count($this->provider_order->provider_order_extra_costs) >= 1) {
            $des[] = 'CALCULANDO COSTOS EXTRAS';
        }
        foreach ($this->provider_order->provider_order_extra_costs as $extra_cost) {
            $total_costos_extra += (float)$extra_cost->value;
            $des[] = 'Sumando '.Numbers::price($extra_cost->value, true).' de '.$extra_cost->description;
            $des[] = 'Costos extras en '.Numbers::price($total_costos_extra, true);
        }

        if ($total_costos_extra > 0) {
            $total_sin_costo_extra = $total;
            $total += $total_costos_extra;

            $des[] = 'APLICANDO COSTOS EXTRAS';
            $des[] = 'Total en '.Numbers::price($total_sin_costo_extra, true).' mas '.Numbers::price($total_costos_extra, true).' de costos extra = '.Numbers::price($total, true);
        }

        $this->provider_order->total_costos_extra            = $total_costos_extra;


        if ($this->provider_order->total_with_iva) {

            $total_sin_iva = $total;

            $total += $total_iva;
            
            $des[] = 'APLICANDO IVA';
            $des[] = 'Total en '.Numbers::price($total_sin_iva, true).' mas '.Numbers::price($total_iva, true).' de IVA = '.Numbers::price($total, true);
        }

        $des[] = 'TOTAL FINAL';
        $des[] = 'Total final = '.Numbers::price($total, true);


        $this->provider_order->total                = $total;
        $this->provider_order->price_description    = json_encode($des);

        $this->provider_order->save();



    }

    function get_total_article($article) {

        $cost = (float)($article->pivot->cost);
        $sub_total_article = 0;
        $total_article = 0;
        $total_descuento = 0;
        $article_iva = [
            'iva_id'        => 0,
            'importe_iva'   => 0,
        ];
        
        if (
            $article->pivot->cost_in_dollars
            && $this->provider_order->moneda_id == 1
        ) {

            $valor_dolar = $this->user->dollar;

            if (
                !is_null($this->provider_order->provider) 
                && !is_null($this->provider_order->provider->dolar) 
                && (float)$this->provider_order->provider->dolar > 0) {

                $valor_dolar = $this->provider_order->provider->dolar;

            }

            $cost *= $valor_dolar;
        }

        $total_article = $cost * (float)($article->pivot->amount);

        if (
            (
                $total_article == 0
                || is_null($total_article)
            )
            && $article->pivot->price
        ) {
            $total_article = (float)$article->pivot->price * (float)($article->pivot->amount);
        }

        if (!is_null($article->presentacion)) {
            $total_article *= $article->presentacion;
        }


        $sub_total_article = $total_article;

        if (!is_null($article->pivot->discount)) {

            $descuento = $total_article * (float)$article->pivot->discount / 100;
            
            $total_descuento += $descuento;

            $total_article -= $descuento;
        }


        if (
            !$this->user->iva_included
            && !is_null($article->pivot->iva_id)
            && $article->pivot->iva_id != 0) {

            $iva = $this->get_iva($article->pivot->iva_id);

            if (!is_null($iva)) {
                
                $importe_iva = $total_article * (float)$iva->percentage / 100;

                $article_iva['iva_id']      = $iva->id;
                $article_iva['neto']        = $total_article;
                $article_iva['importe_iva'] = $importe_iva;

            } else {
                Log::info('No se encontro el iva_id: '.$article->pivot->iva_id);
            }

        }

        return [
            'total_article'     => $total_article,
            'sub_total_article' => $sub_total_article,
            'article_iva'       => $article_iva,
            'total_descuento'   => $total_descuento,
        ];
    }

    function get_iva($iva_id) {

        $iva = null;

        foreach ($this->ivas as $_iva) {
            
            if ($_iva->id == $iva_id) {

                $iva = $_iva;
            }
        }

        return $iva;
    }

    function set_current_acount() {

        if ($this->provider_order->generate_current_acount) {
            
            $current_acount = CurrentAcount::where('provider_order_id', $this->provider_order->id)
                                            ->first();

            if (is_null($current_acount)) {

                $current_acount = $this->crear_current_acount();
            } else {

                $cambio_moneda = $this->check_cambio_moneda();

                if ($cambio_moneda) {

                    $current_acount = $this->crear_current_acount();
                    
                } else {

                    $current_acount = $this->actualizar_current_acount($current_acount);
                }

            }

            CurrentAcountHelper::check_saldos_y_pagos($this->credit_account->id);
        }

    }

    function actualizar_current_acount($current_acount) {

        
        $current_acount->debe = $this->provider_order->total;

        $saldo = CurrentAcountHelper::getSaldo($this->credit_account->id, $current_acount) + $this->provider_order->total;

        $current_acount->saldo = $saldo;

        $current_acount->save();

        return $current_acount;
    }


    /*
        Si en este punto, filtrando ademas por credit_account_id, 
        no encuentra current_acount, es porque el current_acount que se encontro
        antes pertenece a otra credit_account.

        Entonces busco current_acount sin filtrar por credit_account y la elimino 
    */
    function check_cambio_moneda() {
            
        $current_acount = CurrentAcount::where('provider_order_id', $this->provider_order->id)
                                        ->where('credit_account_id', $this->credit_account->id)
                                        ->first();

        if (!$current_acount) {

            $current_acount = CurrentAcount::where('provider_order_id', $this->provider_order->id)
                                            ->first();

            $credit_account_id = $current_acount->credit_account_id;
            $current_acount->delete();

            CurrentAcountHelper::check_saldos_y_pagos($credit_account_id);

            return true;
        }

        return false;
    }

    function crear_current_acount() {

        $current_acount = CurrentAcount::create([
            'detalle'           => 'Pedido N°'.$this->provider_order->num,
            'debe'              => $this->provider_order->total,
            'status'            => 'sin_pagar',
            'user_id'           => UserHelper::userId(),
            'provider_id'       => $this->provider_order->provider_id,
            'provider_order_id' => $this->provider_order->id,
            'credit_account_id' => $this->credit_account->id,
        ]);

        $saldo = CurrentAcountHelper::getSaldo($this->credit_account->id, $current_acount) + $this->provider_order->total;

        $current_acount->saldo = $saldo;

        $current_acount->save();

        return $current_acount;
    }

    function set_ivas() {
        $this->ivas = Iva::all();
    }

    function attach_articles() {

        $this->provider_order->articles()->sync([]);

        foreach ($this->new_articles as $new_article) {
            
            $this->provider_order->articles()->attach($new_article['id'], [

                'cost'              => GeneralHelper::getPivotValue($new_article, 'cost'),
                'amount'            => GeneralHelper::getPivotValue($new_article, 'amount'),
                'price'             => GeneralHelper::getPivotValue($new_article, 'price'),
                'discount'          => GeneralHelper::getPivotValue($new_article, 'discount'),
                'notes'             => GeneralHelper::getPivotValue($new_article, 'notes'),
                'iva_id'            => GeneralHelper::getPivotValue($new_article, 'iva_id'),
                'cost_in_dollars'   => GeneralHelper::getPivotValue($new_article, 'cost_in_dollars'),
                'amount_pedida'   => GeneralHelper::getPivotValue($new_article, 'amount_pedida'),
                'update_provider'   => GeneralHelper::getPivotValue($new_article, 'update_provider'),
            ]);

            $this->update_article($new_article);
        }

    }

    function update_article($new_article) {

        $article = Article::find($new_article['id']);

        if (!is_null($article)) {

            $article = $this->update_iva($article, $new_article);
            
            if ($this->provider_order->update_prices) {

                Log::info('update_prices');

                $article = $this->update_cost($article, $new_article);

                $article = $this->update_price($article, $new_article);
            }

            // Si el articulo esta inacive, se actualiza la info de bar_code y demas
            $article = $this->update_article_data($article, $new_article);
            
            $article = $this->check_article_status($article, $new_article);

            if ($this->provider_order->update_stock) {

                $article = $this->update_stock($article, $new_article);
                
            }

            $this->update_article_provider($article, $new_article);

            $article->save();
        }
    }

    function update_article_provider($article, $new_article) {
        if ($new_article['pivot']['update_provider']) {
            
            $article->provider_id = $this->provider_order->provider_id;
            $article->timestamps = false;
            $article->save();
        }

    }

    function update_article_data($article, $new_article) {

        if ($article->status == 'inactive') {

            $article->bar_code          = $new_article['bar_code'];
            $article->provider_code     = $new_article['provider_code'];
            $article->save();

        }

        return $article;
    }

    function check_article_status($article, $new_article) {

        if (
            $article->status == 'inactive' 
            && $this->provider_order->update_stock
            && $new_article['pivot']['amount'] > 0
        ) {

            $article->status = 'active';
            $article->apply_provider_percentage_gain = 1;
            $article->created_at = Carbon::now();
        }

        return $article;
    }

    function update_stock($article, $new_article) {

        $article = Self::save_stock_movement($article, $new_article);

        return $article;
    }

    function save_stock_movement($article, $new_article) {

        $amount = $new_article['pivot']['amount'];

        if ($amount != '' 
            && !is_null($amount)
            && $amount > 0) {

            Log::info('*****************');
            Log::info('save_stock_movement para '.$article->name);
        
            if (is_null($article->stock)) {
                $article->stock = 0;
                $article->save();
            }

            $ct_stock_movement = new StockMovementController();

            Log::info('amount '.$amount);

            $se_esta_actualizando = false;

            if (isset($this->ultimos_articulos_recividos[$article->id])) {
                $se_esta_actualizando = true;
                Log::info('antes habia '.$this->ultimos_articulos_recividos[$article->id]);
                $amount -= $this->ultimos_articulos_recividos[$article->id];
                Log::info('amount quedo en: '.$amount);
            }

            if ($amount != 0) {

                $data = [];

                $data['model_id'] = $article->id;

                if (!is_null($this->provider_order->address_id)
                    && $this->provider_order->address_id != 0
                    && (
                        count($article->addresses) >= 1 
                        || $article->stock == 0
                        || is_null($article->stock)
                    )
                ) {

                    $data['to_address_id'] = $this->provider_order->address_id;
                } 

                if (!$new_article['pivot']['update_provider']) {

                    $data['not_save_provider'] = true;
                } 

                $data['amount'] = $amount;

                $data['provider_id'] = $this->provider_order->provider_id;

                $data['provider_order_id'] = $this->provider_order->id;

                if ($se_esta_actualizando) {

                    $data['concepto_stock_movement_name'] = 'Act Compra a proveedor';
                } else {

                    $data['concepto_stock_movement_name'] = 'Compra a proveedor';
                }
                

                $ct_stock_movement->crear($data);
            }

        }

        return $article;
    }

    function update_iva($article, $new_article) {

        if (
            isset($new_article['pivot']['iva_id'])
            && !is_null($new_article['pivot']['iva_id']) 
            && $new_article['pivot']['iva_id'] != 0 
            && $article->iva_id != $new_article['pivot']['iva_id']
        ) {

            $article->iva_id = $new_article['pivot']['iva_id'];

            Log::info('update iva con: '.$article->iva_id);
        } else {
            Log::info('No se actualizo iva');

        }

        return $article;
    }

    function update_price($article, $new_article) {

        $price = null;

        if (isset($new_article['pivot']['price']) 
            && !is_null($new_article['pivot']['price'])) {

            $price = (float)$new_article['pivot']['price'];
        }

        if (!is_null($price)
            && $article->price != $price) {

            $article->price = $price;
            $article->save();

            Log::info('update_price');

            ArticleHelper::setFinalPrice($article);
        }

        return $article;
    }

    function update_cost($article, $new_article) {

        $cost = null;

        if (isset($new_article['pivot']['cost']) 
            && $new_article['pivot']['cost'] != '') {

            $cost = $new_article['pivot']['cost'];
        }

        if (!is_null($cost) 

            && $article->cost != $cost) {


            $article->cost = $cost;
            
            if (
                isset($new_article['pivot'])
                && isset($new_article['pivot']['cost_in_dollars'])
            ) {

                $article->cost_in_dollars = $new_article['pivot']['cost_in_dollars'];
            }


            $article->save();

            Log::info('update_cost con '. $article->cost);

            ArticleHelper::setFinalPrice($article);
        }

        return $article;
    }
	
    function set_ultimos_articulos_recividos() {

        $this->ultimos_articulos_recividos = [];

        if ($this->ya_se_actualizo_stock) {

            foreach ($this->provider_order->articles as $article) {
                
                $this->ultimos_articulos_recividos[$article->id] = $article->pivot->amount;
            }
        }
        
    }
}