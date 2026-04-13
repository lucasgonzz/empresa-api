<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Http\Controllers\Helpers\SellerCommissionHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Models\Check;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountPagoHelper {

    public $model_name;
    public $model_id;
    public $pago;
    public $fondos;
    public $fondos_iniciales;
    public $sin_pagar;
    public $sin_pagar_index;

    /** Instancia de CreditAccount asociada a este procesamiento */
    public $credit_account;

    /** Monto pendiente del débito actual (debe - pagandose) */
    public $debe;

    /**
     * Colección de débitos pendientes cargada en memoria al inicio.
     * Se consume con shift() para evitar un query por iteración en setSinPagar().
     *
     * @var \Illuminate\Support\Collection
     */
    public $debitos_pendientes;

    /**
     * Constructor del helper de imputación de pagos.
     *
     * @param int $credit_account_id  ID de la cuenta corriente
     * @param string $model_name      Tipo de modelo: 'client' o 'provider'
     * @param int $model_id           ID del cliente o proveedor
     * @param CurrentAcount $pago     Movimiento de tipo haber (pago) recién creado
     * @param CreditAccount|null $credit_account  Instancia ya cargada para evitar query extra
     */
    function __construct($credit_account_id, $model_name, $model_id, $pago, $credit_account = null) {
        Log::info('-------------');
        Log::info('procesando '.$pago->detalle);
        $this->model_name = $model_name;
        $this->model_id = $model_id;

        // Reusar la instancia ya cargada si se pasó como parámetro (evita query extra en checkPagos)
        $this->credit_account = $credit_account ?? CreditAccount::find($credit_account_id);

        $this->pago = $pago;
        $this->fondos = $pago->haber;
        $this->sin_pagar = null;
        $this->sin_pagar_index = 0;

        // Cargar todos los débitos pendientes de una sola vez en memoria
        // para evitar hacer 1 query por cada débito procesado en setSinPagar()
        $this->debitos_pendientes = CurrentAcount::where('credit_account_id', $this->credit_account->id)
                                                 ->whereIn('status', ['sin_pagar', 'pagandose'])
                                                 ->orderBy('created_at', 'ASC')
                                                 ->get();

        $this->setSinPagar();
    }

    /**
     * Determina el próximo débito a procesar.
     * Si el pago tiene to_pay_id y es la primera llamada, usa ese débito específico.
     * En los demás casos consume el primer débito pendiente de la colección en memoria.
     */
    function setSinPagar() {

        $this->sin_pagar_index++;

        if (!is_null($this->pago->to_pay_id) && is_null($this->sin_pagar)) {

            // Buscar el débito dirigido dentro de la colección en memoria
            $found = $this->debitos_pendientes->firstWhere('id', $this->pago->to_pay_id);

            if ($found) {
                // Removerlo de la colección para no procesarlo de nuevo en las iteraciones siguientes
                $this->debitos_pendientes = $this->debitos_pendientes
                                                ->reject(function ($d) { return $d->id == $this->pago->to_pay_id; })
                                                ->values();
                $this->sin_pagar = $found;
            } else {
                // No estaba en los pendientes (puede ser un débito ya pagado o de otro estado)
                $this->sin_pagar = CurrentAcount::find($this->pago->to_pay_id);
            }

            Log::info('con to_pay_id '.$this->pago->to_pay_id);
        } else {

            // Tomar el siguiente débito pendiente de la colección en memoria (FIFO)
            $this->sin_pagar = $this->debitos_pendientes->shift();

        }

        if (!is_null($this->sin_pagar)) {
            Log::info('se puso '.$this->sin_pagar->detalle.' para sin pagar');
        }

    }

    function init() {
        while (!is_null($this->sin_pagar) && $this->fondos > 0) {
            $this->fondos_iniciales = $this->fondos;
            $this->procesarPago();
            $this->setSinPagar();
        }
        Log::info('Ya no entro en procesar pago');
        $this->setModelPagosCheckeados();
    }

    function setModelPagosCheckeados() {
        $model = GeneralHelper::getModelName($this->model_name)::find($this->model_id);
        if ($model) {
            $model->pagos_checkeados = 0;
            $model->save();
        }
    }

    function procesarPago() {
        Log::info('procesarPago');
        $this->debe = $this->sin_pagar->debe - $this->sin_pagar->pagandose;

        // $fondos = (float)$this->fondos;
        // $debe = (float)$this->debe;

        Log::info('Los fondos son '.Numbers::price($this->fondos).' y se debe '.Numbers::price($this->debe));
        Log::info('Los fondos son mayor o iguales: ');

        $delta = 0.00001;
        Log::info($this->fondos > $this->debe || abs($this->fondos - $this->debe) < $delta);

        if ($this->fondos > $this->debe || abs($this->fondos - $this->debe) < $delta) {
            $this->sin_pagar->pagandose += $this->debe;
            $this->sin_pagar->status = 'pagado';
            $this->fondos -= $this->debe;
            $this->savePagadoPor($this->debe);
            SellerCommissionHelper::checkCommissionStatus($this->sin_pagar, $this->pago);
            Log::info('Se puso en pagado');
        } else {
            $this->sin_pagar->pagandose += $this->fondos;
            $this->sin_pagar->status = 'pagandose';
            $pagado = $this->fondos;
            $this->fondos = 0;
            $this->savePagadoPor($pagado);
            Log::info('Se puso sin pagar');
        }
        $this->sin_pagar->save();
    }

    function savePagadoPor($pagado) {
        $this->sin_pagar->pagado_por()->attach($this->pago->id, [
            'pagado'            => $pagado,
            'total_pago'        => $this->pago->haber,
            'a_cubrir'          => $this->debe,
            'fondos_iniciales'  => $this->fondos_iniciales,
            'nuevos_fondos'     => $this->fondos,
            'remantente'        => $this->debe - $pagado,
            'created_at'        => $this->pago->created_at->addSeconds($this->sin_pagar_index),
        ]);
    }

    static function attachPaymentMethods($pago, $payment_methods, $model_name = null) {
        
        PaymentMethodHelper::attach_payment_methods($pago, $payment_methods);

        $pago->load('current_acount_payment_methods');
        
        foreach ($pago->current_acount_payment_methods as $payment_method) {

            if ($payment_method->pivot->caja_id) {

                CurrentAcountCajaHelper::guardar_pago($payment_method->pivot->amount, $payment_method->pivot->caja_id, $model_name, $pago);

            // $amount = $payment_method['amount'];
            // $amount_cotizado = isset($payment_method['amount_cotizado']) ? $payment_method['amount_cotizado'] : null;
            // $cotizacion = isset($payment_method['cotizacion']) ? $payment_method['cotizacion'] : null;
            // $moneda_id = isset($payment_method['moneda_id']) ? $payment_method['moneda_id'] : null;

            // $haber = 0;

            // if (
            //     !is_null($amount_cotizado)
            //     && $amount_cotizado != ''
            //     && (float)$amount_cotizado > 0
            // ) {
            //     $haber = $amount_cotizado;
            // } else {

            //     $haber = $amount;
            // }
            
            // if ($amount == '' || is_null($amount)) {
            //     $amount = $pago->haber;
            // }
            
            // // Si es cheque
            // if ($payment_method['current_acount_payment_method_id'] == 1) {
            //     ChequeHelper::crear_cheque($pago, $payment_method);
            // }

            // $pago->current_acount_payment_methods()->attach($payment_method['current_acount_payment_method_id'], [
            //         'amount'    => $haber,
            //         'amount_cotizado'    => $amount_cotizado,
            //         'cotizacion'    => $cotizacion,
            //         'moneda_id'    => $moneda_id,
            //         'user_id'   => UserHelper::userId(),
            // ]);

            // if (
            //     $payment_method['current_acount_payment_method_id'] != 1
            //     && isset($payment_method['caja_id'])
            //     && $payment_method['caja_id'] != 0
            //     && !is_null($model_name)
            // ) {

            //     CurrentAcountCajaHelper::guardar_pago($amount, $payment_method['caja_id'], $model_name, $pago);
            // }
            }


        }
    }

    static function get_check_status($payment_method) {

        // if ($this->estadoManual === 'cobrado') return 'Cobrado';
        // if ($this->estadoManual === 'rechazado') return 'Rechazado';

        return 1;

        $hoy = Carbon::today();
        $fecha_pago = Carbon::parse($payment_method['fecha_pago']);
        $vencimiento = $fecha_pago->copy()->addDays(30);

        if ($hoy->lt($fecha_pago)) {
            return 'Pendiente';
        }

        if ($hoy->between($fecha_pago, $vencimiento->copy()->subDays(3))) {
            return 'Disponible para cobrar';
        }

        if ($hoy->between($vencimiento->copy()->subDays(2), $vencimiento)) {
            return 'Pronto a vencerse';
        }

        if ($hoy->gt($vencimiento)) {
            return 'Vencido';
        }
    }

}