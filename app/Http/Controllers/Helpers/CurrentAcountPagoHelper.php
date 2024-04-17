<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\SellerCommissionHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Check;
use App\Models\CurrentAcount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountPagoHelper {

    public $model_name;
    public $model_id;
    public $pago;
    public $fondos;
    public $sin_pagar;

    function __construct($model_name, $model_id, $pago) {
        $this->model_name = $model_name;
        $this->model_id = $model_id;
        $this->pago = $pago;
        $this->fondos = $pago->haber;
        $this->setSinPagar();
        Log::info('Porcesando '.$pago->detalle);
    }

    function setSinPagar() {
        if (!is_null($this->pago->to_pay_id) && is_null($this->sin_pagar)) {
            $this->sin_pagar = CurrentAcount::find($this->pago->to_pay_id);
        } else {
            $this->sin_pagar = CurrentAcount::where($this->model_name.'_id', $this->model_id)
                                            ->whereIn('status', ['sin_pagar', 'pagandose'])
                                            // ->where('created_at', '<', $this->pago->created_at)
                                            ->orderBy('created_at', 'ASC')
                                            ->first();
        }
    }

    function init() {
        while (!is_null($this->sin_pagar) && $this->fondos > 0) {
            $this->procesarPago();
            $this->setSinPagar();
        }
        // $this->setModelPagosCheckeados();
    }

    function setModelPagosCheckeados() {
        $model = GeneralHelper::getModelName($this->model_name)::find($this->model_id);
        $model->pagos_checkeados = 0;
        $model->save();
    }

    function procesarPago() {
        $this->debe = $this->sin_pagar->debe - $this->sin_pagar->pagandose;
        if ($this->fondos >= $this->debe) {
            $this->sin_pagar->pagandose += $this->debe;
            $this->sin_pagar->status = 'pagado';
            $this->fondos -= $this->debe;
            $this->savePagadoPor($this->debe);
            SellerCommissionHelper::checkCommissionStatus($this->sin_pagar, $this->pago);
        } else {
            $this->sin_pagar->pagandose += $this->fondos;
            $this->sin_pagar->status = 'pagandose';
            $this->savePagadoPor($this->fondos);
            $this->fondos = 0;
        }
        $this->sin_pagar->save();
    }

    function savePagadoPor($pagado) {
        $this->sin_pagar->pagado_por()->attach($this->pago->id, [
            'pagado'        => $pagado,
            'total_pago'    => $this->pago->haber,
            'created_at'    => $this->pago->created_at,
        ]);
        echo('GUARDANDO EL PAGADO de '.$this->sin_pagar->detalle.' </br>');
        // Log::info('El pagado_por de la '.$this->sin_pagar->detalle.' quedo asi:');
        // foreach ($this->sin_pagar->pagado_por as $pagado_por) {
            // Log::info('El pago N°'.$pagado_por->num_receipt.' pago '.$pagado_por->pivot->pagado.' a la '.CurrentAcount::find($pagado_por->pivot->debe_id)->detalle);
        // }
        // Log::info('-------------------------------------------------');
    }

    static function saveCheck($pago, $checks) {
        Log::info('enotr a guardar cheques');
        foreach ($checks as $check) {
            Check::create([
                'bank'                  => $check['bank'],
                'payment_date'          => $check['payment_date'],
                'amount'                => $check['amount'],
                'num'                   => $check['num'],
                'current_acount_id'     => $pago->id,
            ]);
        }
    }

    static function attachPaymentMethods($pago, $payment_methods) {
        foreach ($payment_methods as $payment_method) {
            $amount = $payment_method['amount'];
            if ($amount == '' || is_null($amount)) {
                $amount = $pago->haber;
            }
            $pago->current_acount_payment_methods()->attach($payment_method['current_acount_payment_method_id'], [
                                                        'amount'                        => $amount,
                                                        'bank'                          => $payment_method['bank'],
                                                        'payment_date'                  => $payment_method['payment_date'],
                                                        'num'                           => $payment_method['num'],
                                                        'credit_card_id'                => $payment_method['credit_card_id'] != 0 ? $payment_method['credit_card_id'] : null,
                                                        'credit_card_payment_plan_id' => $payment_method['credit_card_payment_plan_id'] != 0 ? $payment_method['credit_card_payment_plan_id'] : null,
                                                        'user_id'   => UserHelper::userId(),
                                                    ]);
        }
    }

}