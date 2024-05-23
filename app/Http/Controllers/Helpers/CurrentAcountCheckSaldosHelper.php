<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\Log;

class CurrentAcountCheckSaldosHelper {

    static function checkSaldos($model_name, $model_id, $from_current_acount = null, $procesar_pagos = false) {
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

        if ($procesar_pagos) {
            Self::procesarPagos($model_name, $model_id);
        }

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

    static function procesarPagos($model_name, $model_id) {

        Log::info('Entro a procesarPagos');
        
        Self::resetDebitos($model_name, $model_id);

        $pagos = Self::getPagos($model_name, $model_id);

        foreach ($pagos as $pago) {
            $pago_helper = new CurrentAcountPagoHelper($model_name, $model_id, $current_acount);
            $pago_helper->init();
        }
    }

    static function getPagos($model_name, $model_id) {
        $pagos = CurrentAcount::orderBy('created_at', 'ASC')
                                    ->whereNotNull('haber');
        if ($model_name == 'client') {
            $pagos = $pagos->where('client_id', $model_id);
        } else {
            $pagos = $pagos->where('provider_id', $model_id);
        }
        $pagos = $pagos->get();
        return $pagos;
    }

    static function resetDebitos($model_name, $model_id) {
        $debitos = CurrentAcount::orderBy('created_at', 'ASC')
                                        ->whereNotNull('debe');
        if ($model_name == 'client') {
            $debitos = $debitos->where('client_id', $model_id);
        } else {
            $debitos = $debitos->where('provider_id', $model_id);
        }

        $debitos->update([
            'pagandose' => 0,
            'status' => 'sin_pagar',
        ]);
    }

}