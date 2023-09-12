<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\SellerCommissionHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\Check;
use App\Models\CreditCard;
use App\Models\CreditCardPaymentPlan;
use App\Models\CurrentAcount;
use App\Models\ErrorCurrentAcount;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CurrentAcountHelper {

    static function checkCurrentAcountSaldo($model_name, $model_id) {
        $current_acounts = CurrentAcount::where($model_name.'_id', $model_id)
                                        ->orderBy('created_at', 'DESC')
                                        ->take(3)
                                        ->get();
        if (isset($current_acounts[2])) {
            Self::checkSaldos($model_name, $model_id, $current_acounts[2]);
        }
    }

    static function updateSellerCommissionsStatus($pago) {
        foreach ($pago->pagando_las_comisiones as $seller_commission) {
            $seller_commission->status = 'inactive';
            $seller_commission->save();
        } 
    }

    static function getCreatedAt($request) {
        if ($request->current_date) {
            return Carbon::now();
        } else {
            return Carbon::parse($request->created_at.substr(Carbon::now(), 11));    
        }
    }

    static function saldoCheckeado($model_name, $model_id) {
        if ($model_name == 'client') {
            $client = Client::find($model_id);
            if (!$client->saldo_checkeado) {
                Self::checkSaldos($model_name, $model_id, null, true);
            }
        }
    }

    static function getNumReceipt($from_nota_credito = false) {
        $last_receipt = CurrentAcount::where('user_id', UserHelper::userId())
                                        ->orderBy('created_at', 'DESC');
        if ($from_nota_credito) {
            $last_receipt = $last_receipt->where('status', 'nota_credito');
        } else {
            $last_receipt = $last_receipt->where('status', 'pago_from_client');
        }
        $last_receipt = $last_receipt->first();
        return is_null($last_receipt) ? 1 : $last_receipt->num_receipt + 1;
    }


    static function updateModelSaldo($current_acount, $model_name, $model_id) {
        $model_name = GeneralHelper::getModelName($model_name);
        $model = $model_name::find($model_id);
        $model->saldo = $current_acount->saldo;
        $model->save();
    } 

    static function getSaldo($model_name, $model_id, $until_current_acount) {
        $last = CurrentAcount::orderBy('created_at', 'DESC')
                                ->where('created_at', '<', $until_current_acount->created_at);
        if ($model_name == 'client') {
            $last = $last->where('client_id', $model_id);
        } else {
            $last = $last->where('provider_id', $model_id);
        }
        $last = $last->first();
        if (is_null($last)) {
            return 0;
        } else {
            return $last->saldo;
        }
    }

    static function notaCredito($haber, $description, $model_name, $model_id, $sale_id = null, $articles = null) {
        $nota_credito = CurrentAcount::create([
            'description'   => $description,
            'haber'         => $haber,
            'status'        => 'nota_credito',
            'client_id'     => $model_name == 'client' ? $model_id : null,
            'provider_id'   => $model_name == 'provider' ? $model_id : null,
            'sale_id'       => $sale_id,
            'num_receipt'   => CurrentAcountHelper::getNumReceipt(true),
            'user_id'       => UserHelper::userId(),
        ]);
        $nota_credito->saldo = Self::getSaldo($model_name, $model_id, $nota_credito) - $haber;
        $nota_credito->detalle = 'Nota Credito N°'.$nota_credito->num_receipt;
        $nota_credito->save();
        Self::attachNotaCreditoArticles($nota_credito, $articles);
        Self::updateModelSaldo($nota_credito, $model_name, $model_id);

        $pago_helper = new CurrentAcountPagoHelper($model_name, $model_id, $nota_credito);
        $pago_helper->init();
        return $nota_credito;
    }

    static function attachNotaCreditoArticles($nota_credito, $articles) {
        if (!is_null($articles)) {
            Log::info('attachNotaCreditoArticles articles:');
            Log::info($articles);
            $nota_credito->articles()->detach();
            foreach ($articles as $article) {
                $nota_credito->articles()->attach($article['id'], [
                                                    'amount' => $article['returned_amount'],
                                                    'price'  => $article['price_vender'],
                                                ]);
            }
        }
    }

    static function procesarPago($model_name, $model_id, $haber, $until_pago, $to_pay_id = null) {
        $detalle = '';
        if (!is_null($to_pay_id)) {
            $until_pago->to_pay_id = $to_pay_id;
            $until_pago->save();
            $haber = Self::saldarSpecificCurrentAcount($to_pay_id, $pago, $haber);
        } 
        $haber_restante = Self::saldarPagandose($model_name, $model_id, $haber, $until_pago);
        // $saldar_pagandose = Self::saldarPagandose($model_name, $model_id, $haber, $until_pago);
        // $haber_restante = $saldar_pagandose['haber'];
        // $detalle .= $saldar_pagandose['detalle'];    
        // $detalle .= Self::saldarCuentasSinPagar($model_name, $model_id, $haber_restante, $until_pago);
        Self::saldarCuentasSinPagar($model_name, $model_id, $haber_restante, $until_pago);
        // return $detalle;
    }

    static function saldarPagandose($model_name, $model_id, $haber, $until_pago) {
        // $detalle = '';
        $sin_pagar = Self::getFirstSinPagar($model_name, $model_id, $until_pago);
        $pagandose = Self::getFirstPagandose($model_name, $model_id, $until_pago);
        while (!is_null($sin_pagar) && !is_null($pagandose) && $sin_pagar->created_at->lt($pagandose->created_at) && $haber > 0) {
            $haber = Self::saldarCurrentAcount($sin_pagar, $haber, $until_pago);
            // $res = Self::saldarCurrentAcount($sin_pagar, $haber, $until_pago);
            // $detalle .= $res['detalle'];
            // $haber = $res['haber'];
            $sin_pagar = Self::getFirstSinPagar($model_name, $model_id, $until_pago);
            $pagandose = Self::getFirstPagandose($model_name, $model_id, $until_pago);
        }
        while (!is_null($pagandose) && $haber > 0) {
            $haber += $pagandose->pagandose;
            $pagandose->pagandose = 0;
            $pagandose->save();
            $haber = Self::saldarCurrentAcount($pagandose, $haber, $until_pago);
            // $res = Self::saldarCurrentAcount($pagandose, $haber, $until_pago);
            // $detalle .= $res['detalle'];
            // $haber = $res['haber'];
            $pagandose = Self::getFirstPagandose($model_name, $model_id, $until_pago);
        }
        return $haber;
    }

    static function saldarCuentasSinPagar($model_name, $model_id, $haber, $until_pago = null) {
        $detalle = '';
        $sin_pagar = Self::getFirstSinPagar($model_name, $model_id, $until_pago);
        while (!is_null($sin_pagar) && $haber > 0) {
            $haber = Self::saldarCurrentAcount($sin_pagar, $haber, $until_pago);
            $sin_pagar = Self::getFirstSinPagar($model_name, $model_id, $until_pago);
        }
        return $detalle;
    }

    static function saldarSpecificCurrentAcount($to_pay_id, $pago, $haber) {
        $current_acount = CurrentAcount::find($to_pay_id);
        if (!is_null($current_acount)) {
            $haber =  Self::saldarCurrentAcount($current_acount, $haber, $pago);
            return $haber;
        }
    }

    static function saldarCurrentAcount($current_acount, $haber, $pago) {
        if ($haber >= $current_acount->debe) {
            $current_acount->status = 'pagado';
            $current_acount->save();
            Self::savePagadoPor($current_acount, $pago, $haber);
            SellerCommissionHelper::checkCommissionStatus($current_acount);
            $haber -= $current_acount->debe;
        } else { 
            $previus_pagandose = $current_acount->pagandose;
            if ($current_acount->status == 'pagandose') {
                $current_acount->pagandose += $haber;
            } else {
                $current_acount->status = 'pagandose';
                $current_acount->pagandose = $haber;
            }
            $current_acount->save();
            Self::savePagadoPor($current_acount, $pago, $haber - $current_acount->debe);
            $haber = 0;
        }
        return $haber;
    }

    // static function savePagadoPor($current_acount, $pago, $haber) {
    //     $current_acount->pagado_por()->attach($pago->id, [
    //         'pagado'        => $haber,
    //         'total_pago'    => $pago->haber,
    //     ]);
    // }

    static function getFirstSinPagar($model_name, $model_id, $until_pago) {
        $first = CurrentAcount::where('status', 'sin_pagar')
                                ->orderBy('created_at', 'ASC')
                                ->where('created_at', '<', $until_pago->created_at);
        if ($model_name == 'client') {
            $first = $first->where('client_id', $model_id);
        } else {
            $first = $first->where('provider_id', $model_id);
        }
        $first = $first->first();
        return $first;
    }

    static function getFirstPagandose($model_name, $model_id, $until_pago) {
        $pagandose = CurrentAcount::where('status', 'pagandose')
                                ->orderBy('created_at', 'ASC')
                                ->where('created_at', '<', $until_pago->created_at);
        if ($model_name == 'client') {
            $pagandose = $pagandose->where('client_id', $model_id);
        } else {
            $pagandose = $pagandose->where('provider_id', $model_id);
        }
        $pagandose = $pagandose->first();
        return $pagandose;
    }

    static function checkSaldos($model_name, $model_id, $from_current_acount = null) {
        $current_acounts = CurrentAcount::orderBy('created_at', 'ASC');
        if (!is_null($from_current_acount)) {
            $current_acounts = $current_acounts->where('created_at', '>', $from_current_acount->created_at);
        }
        if ($model_name == 'client') {
            $current_acounts = $current_acounts->where('client_id', $model_id);
        } else {
            $current_acounts = $current_acounts->where('provider_id', $model_id);
        }
        $current_acounts = $current_acounts->get();

        foreach ($current_acounts as $current_acount) {
            $saldo = Self::getSaldo($model_name, $model_id, $current_acount);
            if (!is_null($current_acount->debe)) {
                $current_acount->saldo = Numbers::redondear($saldo + $current_acount->debe);
            } else if (!is_null($current_acount->haber)) {
                $current_acount->saldo = Numbers::redondear($saldo - $current_acount->haber);
            }
            $current_acount->save();
        }
        $app_model_name = GeneralHelper::getModelName($model_name);
        $model = $app_model_name::find($model_id);
        if (count($current_acounts) >= 1) {
            $model->saldo = $current_acounts[count($current_acounts)-1]->saldo;
        } else {
            $model->saldo = 0;
        }
        $model->save();
    }

    static function checkPagos($model_name, $model_id, $si_o_si = false) {
        $model = GeneralHelper::getModelName($model_name)::find($model_id);
        if (!$model->pagos_checkeados || $si_o_si) {
            $debitos = CurrentAcount::orderBy('created_at', 'ASC')
                                            ->whereNotNull('debe');
            if ($model_name == 'client') {
                $debitos = $debitos->where('client_id', $model_id);
            } else {
                $debitos = $debitos->where('provider_id', $model_id);
            }
            $debitos = $debitos->get();

            foreach ($debitos as $debito) {
                $debito->pagado_por()->detach();
                $debito->pagandose = 0;
                $debito->status = 'sin_pagar';
                $debito->save();
            }

            $pagos = CurrentAcount::orderBy('created_at', 'ASC')
                                        ->whereNotNull('haber');
            if ($model_name == 'client') {
                $pagos = $pagos->where('client_id', $model_id);
            } else {
                $pagos = $pagos->where('provider_id', $model_id);
            }
            $pagos = $pagos->get();

            foreach ($pagos as $pago) {
                $pago_helper = new CurrentAcountPagoHelper($model_name, $model_id, $pago);
                $pago_helper->init();
            }
            $model->pagos_checkeados = 1;
            $model->save();
            Log::info('Se checo los pagos de '.$model->name);
        } else {
            Log::info('No se chequearon pagos de '.$model->name);
        }
    }

    static function checkSaldoInicial($client_id) {
        $saldo_inicial = CurrentAcount::where('client_id', $client_id)
                                        ->where('detalle', 'Saldo inicial')
                                        ->first();
        if (!is_null($saldo_inicial)) {
            if ($saldo_inicial->haber) {
                $saldo_inicial->status = 'pago_from_client';
                $saldo_inicial->saldo = $saldo_inicial->haber;
            } else if ($saldo_inicial->debe) {
                $saldo_inicial->status = 'sin_pagar';
                $saldo_inicial->pagandose = null;
                $saldo_inicial->saldo = $saldo_inicial->debe;
            }
            $saldo_inicial->save();
        } 
        return $saldo_inicial;
    }

    static function isSaldoInicial($current_acount) {
        return $current_acount->detalle == 'Saldo inicial';
    }

    static function getDescription($sale, $total = null) {
        if (count($sale->discounts) >= 1) {
            if (!is_null($total)) {
                $description = '$'.Numbers::price($total);
            } else {
                $description = '$'.Numbers::price(SaleHelper::getTotalSale($sale));
            }
            foreach ($sale->discounts as $discount) {
                $description .= '(-'.$discount->pivot->percentage . '% '. substr($discount->name, 0, 3) .')';
            }
            foreach ($sale->surchages as $surchage) {
                $description .= '(+'.$surchage->pivot->percentage . '% '. substr($surchage->name, 0, 3) .')';
            }
            return $description;
        } else {
            return null;
        }
    }

    static function getCurrentAcountsSinceMonths($model_name, $model_id, $months_ago) {
        $months_ago = Carbon::now()->subMonths($months_ago);
        $current_acounts = CurrentAcount::whereDate('created_at', '>=', $months_ago)
                                        ->orderBy('created_at', 'ASC')
                                        ->with(['sale' => function($q) {
                                            return $q->withAll();
                                        }])
                                        ->with(['budget' => function($q) {
                                            return $q->withAll();
                                        }])
                                        ->with('payment_method')
                                        ->with('checks');
        if ($model_name == 'client') {
            $current_acounts = $current_acounts->where('client_id', $model_id);
        } else {
            $current_acounts = $current_acounts->where('provider_id', $model_id);
        }
        $current_acounts = $current_acounts->get();
        $current_acounts = Self::format($current_acounts);
        return $current_acounts;
    }

    static function format($current_acounts) {
        foreach ($current_acounts as $current_acount) {
            if (!is_null($current_acount->num_receipt)) {
                $current_acount->detalle = 'Recibo pago '.$current_acount->num_receipt;
                // $current_acount->detalle = 'ReciboPago'.Self::getFormatedNum($current_acount->num_receipt);
            }
            if (!is_null($current_acount->sale_id)) {
                $current_acount->detalle = 'Remito N°'.Self::getNum('sales', $current_acount->sale_id, 'num');
            }
            if (!is_null($current_acount->budget_id)) {
                $current_acount->detalle = 'Presupuesto N°'.Self::getNum('budgets', $current_acount->budget_id ,'num');
            }
            if (!is_null($current_acount->provider_order_id)) {
                $current_acount->detalle = 'Pedido N°'.Self::getNum('provider_orders', $current_acount->provider_order_id ,'num');
            }
            if (!is_null($current_acount->order_production_id)) {
                $current_acount->detalle = 'Orden de produccion N°'.Self::getNum('order_productions', $current_acount->order_production_id ,'num');
            }
            if ($current_acount->status == 'nota_credito') {
                $current_acount->detalle = 'Nota credito';
            }
            if ($current_acount->detalle == 'Saldo inicial') {
                $current_acount->detalle = 'Saldo inicial';
            }
            if ($current_acount->detalle == 'Nota de debito') {
                $current_acount->detalle = 'Nota debito';
            }
            if (!is_null($current_acount->current_acount_payment_methods)) {
                foreach ($current_acount->current_acount_payment_methods as $payment_method) {
                    if (!is_null($payment_method->pivot->credit_card_id)) {
                        $credit_card = CreditCard::find($payment_method->pivot->credit_card_id);
                        $payment_method->credit_card = $credit_card;
                        if (!is_null($payment_method->pivot->credit_card_payment_plan_id)) {
                            $credit_card_payment_plan = CreditCardPaymentPlan::find($payment_method->pivot->credit_card_payment_plan_id);
                            $payment_method->credit_card_payment_plan = $credit_card_payment_plan;
                        }
                    }
                }
            }
        }
        return $current_acounts;
    }

    static function getNum($table, $id, $prop) {
        $model = DB::table($table)->where('id', $id)->first();
        if (!is_null($model)) {
            return $model->{$prop};
        }
        // return Self::getFormatedNum($model->{$prop});
    }

    static function getFormatedNum($num) {
        $letras_faltantes = 8 - strlen($num);
        $cbte_numero = '';
        for ($i=0; $i < $letras_faltantes; $i++) { 
            $cbte_numero .= '0'; 
        }
        $cbte_numero  .= $num;
        return $cbte_numero;
    }

}