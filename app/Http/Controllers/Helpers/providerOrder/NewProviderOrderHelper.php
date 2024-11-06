<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Http\Controllers\StockMovementController;
use App\Models\Article;
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

        $this->set_ultimos_articulos_recividos();

        $this->set_ivas();
    }

    function procesar_pedido() {

        $this->attach_articles();

        $this->set_totales();

        $this->set_current_acount();
    }

    function set_totales() {

        $sub_total = 0;
        $total_descuento = 0;
        $total_iva = 0;
        $total = 0;

        if ($this->provider_order->total_from_provider_order_afip_tickets) {

            Log::info('Sumando total de las facturas');

            foreach ($this->provider_order->provider_order_afip_tickets as $afip_ticket) {

                $total_iva  += $afip_ticket->total_iva;
                $total      += $afip_ticket->total;
            }

        } else {

            Log::info('Sumando total de los articulos');

            $this->provider_order->load('articles');

            foreach ($this->provider_order->articles as $article) {
                
                $total_article = (float)($article->pivot->cost) * (float)($article->pivot->amount);

                $sub_total += $total_article;

                if (!is_null($article->pivot->discount)) {

                    $descuento = $total_article * (float)$article->pivot->discount / 100;
                    
                    $total_descuento += $descuento;

                    $total_article -= $descuento;
                }

                $article_iva = 0;

                if (!is_null($article->pivot->iva_id)) {

                    $iva = $this->get_iva($article->pivot->iva_id);

                    $article_iva = $total_article * (float)$iva->percentage / 100;

                    $total_iva += $article_iva;
                }

                if ($this->provider_order->total_with_iva) {

                    $total_article += $article_iva;
                }

                // $total += $total_article;

            }
        }

        $this->provider_order->total_descuento      = $total_descuento;
        $this->provider_order->total_iva            = $total_iva;
        $this->provider_order->sub_total            = $sub_total;


        if (!$this->provider_order->total_from_provider_order_afip_tickets) {

            $total = $sub_total - $total_descuento;
        }

        foreach ($this->provider_order->provider_order_extra_costs as $extra_cost) {
            $total += (float)$extra_cost->value;
        }

        if ($this->provider_order->total_with_iva) {

            $total += $total_iva;
        }

        $this->provider_order->total                = $total;

        $this->provider_order->save();

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

                $current_acount = $this->actualizar_current_acount($current_acount);
            }

            CurrentAcountHelper::checkSaldos('provider', $this->provider_order->provider_id, $current_acount, true);

        }

    }

    function actualizar_current_acount($current_acount) {
        
        $current_acount->debe = $this->provider_order->total;

        $saldo = CurrentAcountHelper::getSaldo('provider', $this->provider_order->provider_id, $current_acount) + $this->provider_order->total;

        $current_acount->saldo = $saldo;

        $current_acount->save();

        return $current_acount;
    }

    function crear_current_acount() {

        $current_acount = CurrentAcount::create([
            'detalle'           => 'Pedido N°'.$this->provider_order->num,
            'debe'              => $this->provider_order->total,
            'status'            => 'sin_pagar',
            'user_id'           => UserHelper::userId(),
            'provider_id'       => $this->provider_order->provider_id,
            'provider_order_id' => $this->provider_order->id,
        ]);

        $saldo = CurrentAcountHelper::getSaldo('provider', $this->provider_order->provider_id, $current_acount) + $this->provider_order->total;

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

                'cost'          => GeneralHelper::getPivotValue($new_article, 'cost'),
                'amount'        => GeneralHelper::getPivotValue($new_article, 'amount'),
                'price'         => GeneralHelper::getPivotValue($new_article, 'price'),
                'discount'      => GeneralHelper::getPivotValue($new_article, 'discount'),
                'notes'         => GeneralHelper::getPivotValue($new_article, 'notes'),
                'iva_id'        => GeneralHelper::getPivotValue($new_article, 'iva_id'),
            ]);

            $this->update_article($new_article);
        }

    }

    function update_article($new_article) {

        $article = Article::find($new_article['id']);

        if (!is_null($article)) {

            if ($this->provider_order->update_prices) {

                $article = $this->update_cost($article, $new_article);

                $article = $this->update_price($article, $new_article);
            }
            
            $article = $this->update_iva($article, $new_article);

            $article = $this->check_article_status($article, $new_article);
            
            if ($this->provider_order->update_stock) {

                $article = $this->update_stock($article, $new_article);
            }

            $article->save();
        }
    }

    function check_article_status($article, $new_article) {

        if ($article->status == 'inactive' 
            && $this->provider_order->update_stock
            && $new_article['pivot']['amount'] > 0) {

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
            && !is_null($amount)) {

            Log::info('*****************');
            Log::info('save_stock_movement para '.$article->name);
        
            if (is_null($article->stock)) {
                $article->stock = 0;
                $article->save();
            }

            $ct_stock_movement = new StockMovementController();

            Log::info('amount '.$amount);

            if (isset($this->ultimos_articulos_recividos[$article->id])) {
                Log::info('antes habia '.$this->ultimos_articulos_recividos[$article->id]);
                $amount -= $this->ultimos_articulos_recividos[$article->id];
                Log::info('amount quedo en: '.$amount);
            }

            if ($amount != 0) {

                $request = new \Illuminate\Http\Request();
                $request->model_id = $article->id;

                if (!is_null($this->provider_order->address_id)
                    && $this->provider_order->address_id != 0
                    && (
                        count($article->addresses) >= 1 
                        || $article->stock == 0
                        || is_null($article->stock)
                    )
                ) {

                    $request->to_address_id = $this->provider_order->address_id;
                } 

                $request->amount = $amount;

                $request->provider_id = $this->provider_order->provider_id;
                
                $request->concepto = 'Pedido Proveedor N° '.$this->provider_order->num;

                $ct_stock_movement->store($request);
            }

        }

        return $article;
    }

    function update_iva($article, $new_article) {

        if (isset($new_article['pivot']['iva_id'])
            && !is_null($new_article['pivot']['iva_id']) 
            && $new_article['pivot']['iva_id'] != 0 
            && $article->iva_id != $new_article['pivot']['iva_id']) {

            $article->iva_id = $new_article['pivot']['iva_id'];
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
            $article->save();

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