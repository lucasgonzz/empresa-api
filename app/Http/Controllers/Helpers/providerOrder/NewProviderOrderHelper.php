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
use App\Models\Provider;
use App\Models\ProviderOrderDiscount;
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

        // Prompt 262: el descuento de la orden (provider_order_discounts) recién ahora que
        // set_totales() calculó "descuentos_compra" se prorratea sobre el costo de catálogo de
        // cada artículo de la compra. Tiene que ir DESPUÉS de set_totales() porque necesita el
        // total ya calculado.
        $this->aplicar_descuento_compra_a_costo_articulos();

        $this->set_current_acount();
    }

    /**
     * Prompt 262 - Tarea 1: pre-carga las bonificaciones del proveedor (provider_discounts,
     * dato maestro de la negociación con el proveedor) como descuentos EDITABLES de esta orden
     * de compra puntual (provider_order_discounts), al momento de crearla.
     *
     * Racional: desde el prompt 261, la bonificación del proveedor dejó de aplicarse
     * automáticamente al costo de catálogo (Capa 1). Ahora es solo un dato "sugerido" que
     * pre-completa el descuento de la orden de compra concreta, para que el usuario lo vea, lo
     * pueda editar o eliminar antes de confirmar la compra. Recién lo que quede cargado en
     * provider_order_discounts en el momento de confirmar es lo que impacta el costo real, vía
     * aplicar_descuento_compra_a_costo_articulos().
     *
     * No pisa nada si la orden ya trae descuentos propios (por ejemplo, si el usuario ya los
     * mandó explícitamente en el request al crear la orden) — solo pre-completa cuando la orden
     * todavía no tiene ningún provider_order_discount cargado.
     */
    function precargar_bonificaciones_proveedor() {

        // Si la orden ya tiene descuentos propios cargados, no se pisan con los del proveedor.
        if ($this->provider_order->provider_order_discounts()->count() > 0) {
            return;
        }

        $provider = Provider::find($this->provider_order->provider_id);

        if (is_null($provider)) {
            return;
        }

        foreach ($provider->provider_discounts as $provider_discount) {

            // Solo se pre-cargan bonificaciones porcentuales (dato maestro del proveedor,
            // ver ProviderDiscount): si no tiene porcentaje cargado, no hay nada que copiar.
            if (is_null($provider_discount->percentage) || $provider_discount->percentage == '') {
                continue;
            }

            ProviderOrderDiscount::create([
                'description'        => 'Bonificación de proveedor',
                'percentage'         => $provider_discount->percentage,
                'provider_order_id'  => $this->provider_order->id,
            ]);
        }

        // Se vuelve a cargar la relación para que set_totales() (que corre después) vea
        // los descuentos recién creados.
        $this->provider_order->load('provider_order_discounts');
    }

    /**
     * Prompt 262 - Tarea 2: prorratea el descuento agregado de la orden de compra
     * (provider_order_discounts, ya sumarizado por set_totales() en "descuentos_compra") sobre
     * el costo de catálogo de cada artículo de la orden.
     *
     * Racional: la bonificación del proveedor ahora es un dato de la NEGOCIACIÓN de esta compra
     * puntual (cargada como descuento editable de la orden), no una propiedad permanente del
     * costo de catálogo del artículo. Por eso NO se persiste como un ArticleDiscount ni se
     * aplica en ArticlePricesHelper (Capa 1 sigue sin tocarse, ver prompt 261) — el único canal
     * por el que impacta el costo real es este: la misma compra concreta, igual que ya hace
     * update_cost() con el costo "en bruto" que viene del formulario de la orden.
     *
     * Fórmula de prorrateo por ítem (igual a la usada para costos extra/flete prorrateados):
     *   descuento_item = descuentos_compra * subtotal_item / total_compra
     * Como acá lo que se ajusta es el costo UNITARIO (no un total), y descuentos_compra /
     * total_compra ya es la misma proporción para cualquier ítem (se cancela el subtotal_item),
     * esa fórmula equivale a aplicar el mismo ratio de descuento directamente sobre el costo
     * unitario original de cada artículo: costo_final = costo_original * (1 - ratio).
     *
     * Solo corre si la orden tiene marcado update_prices (mismo gate que usa update_cost() para
     * decidir si la compra actualiza el costo de catálogo) y si efectivamente hubo
     * descuentos_compra > 0. Es idempotente: cada vez que se recalculan los totales de la orden
     * (por ejemplo al editarla), vuelve a partir del costo "en bruto" que está en el pivot de la
     * compra (article_provider_order.cost, que este método NO modifica) y aplica el ratio vigente
     * — no hay riesgo de descontar dos veces sobre un costo ya descontado.
     */
    function aplicar_descuento_compra_a_costo_articulos() {

        // Si la orden no está configurada para actualizar el costo/precio de catálogo, no se
        // toca nada (mismo gate que usa update_cost()/update_price()).
        if (!$this->provider_order->update_prices) {
            return;
        }

        $descuentos_compra = (float)$this->provider_order->descuentos_compra;
        $total_articulos   = (float)$this->provider_order->sub_total;

        // Sin descuento de compra o sin una base válida para prorratear, no hay nada que hacer.
        if ($descuentos_compra <= 0 || $total_articulos <= 0) {
            return;
        }

        // Ratio de descuento agregado de la orden sobre el total de artículos, ver fórmula en el
        // comentario del método.
        $ratio_descuento = $descuentos_compra / $total_articulos;

        if ($ratio_descuento <= 0) {
            return;
        }

        foreach ($this->provider_order->articles as $article) {

            // Costo "en bruto" cargado en esta compra puntual para este artículo (pivot de la
            // orden), sin descuento de orden aplicado todavía.
            $costo_original = (float)$article->pivot->cost;

            if ($costo_original <= 0) {
                continue;
            }

            $costo_final = $costo_original * (1 - $ratio_descuento);

            $articulo = Article::find($article->id);

            if (is_null($articulo)) {
                continue;
            }

            $articulo->cost = $costo_final;
            $articulo->save();

            Log::info('aplicar_descuento_compra_a_costo_articulos: costo de '.$articulo->name.' ajustado de '.$costo_original.' a '.$costo_final.' (ratio descuento compra: '.$ratio_descuento.')');

            ArticleHelper::setFinalPrice($articulo);
        }
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

    function attach_articles(bool $overwrite_articles = false) {

        $syncData = [];

        foreach ($this->new_articles as $new_article) {
            
            // Solo seteamos en la pivot las props cuyo valor venga distinto de `null`,
            // para evitar sobrescribir columnas con `null` cuando se actualizan artículos.
            $pivotData = [];
            $pivotKeys = [
                'cost',
                'amount',
                'received',
                'price',
                'discount',
                'notes',
                'iva_id',
                'cost_in_dollars',
                'amount_pedida',
                'update_provider',
            ];

            foreach ($pivotKeys as $pivotKey) {
                $value = GeneralHelper::getPivotValue($new_article, $pivotKey);
                if (!is_null($value)) {
                    $pivotData[$pivotKey] = $value;
                }
            }

            $syncData[$new_article['id']] = $pivotData;
        }

        if ($overwrite_articles) {
            $this->provider_order->articles()->sync($syncData);
        } else {
            $this->provider_order->articles()->syncWithoutDetaching($syncData);
        }

        foreach ($this->new_articles as $new_article) {
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
        if (isset($new_article['pivot']['update_provider']) && (bool)$new_article['pivot']['update_provider']) {
            
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

        $amount = (isset($new_article['pivot']['received']) && $new_article['pivot']['received'] > 0)
            ? $new_article['pivot']['received']
            : $new_article['pivot']['amount'];

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