<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountFromSaleHelper extends Controller {

    public $sale;
    public $current_acount;

    function __construct($sale, $index = null) {
        $this->sale = $sale;

        $moneda_id = $this->sale->moneda_id;

        if (!$moneda_id) {
            $moneda_id = 1;
        }


        $this->credit_account = CreditAccount::where('model_name', 'client')
                                            ->where('model_id', $this->sale->client_id)
                                            ->where('moneda_id', $moneda_id)
                                            ->first();



    }

    function crear_current_acount() {

        /*
         * Candado de la cuenta corriente del cliente (misión cuenta-corriente-carrera-y-velocidad,
         * 23/9/2026), ANTES de insertar el movimiento. La venta de Vender, la edición y la baja ya lo
         * tomaron al abrir su transacción y acá es gratis; lo que cubre este es el resto de los
         * caminos por los que una venta entra a la cuenta (confirmar un presupuesto o un pedido,
         * facturar con "guardar en cuenta corriente después de facturar", restaurar de la
         * papelera): todos pasan por acá. Ver CuentaCorrienteLock.
         */
        CuentaCorrienteLock::bloquear('client', $this->sale->client_id);

        $debe = $this->sale->total;

        $this->current_acount = CurrentAcount::create([
            'detalle'           => 'Venta N°'.$this->sale->num,
            'debe'              => $debe,
            'status'            => 'sin_pagar',
            'client_id'         => $this->sale->client_id,
            'seller_id'         => $this->sale->seller_id,
            'sale_id'           => $this->sale->id,
            // 'description'        => CurrentAcountHelper::getDescription($this->sale, $this->debe_sin_descuentos),
            'created_at'        => $this->sale->created_at,
            'employee_id'       => UserHelper::userId(false),
            'credit_account_id' => $this->credit_account->id,
        ]);

        $this->saldo_actual = CurrentAcountHelper::getSaldo($this->credit_account->id, $this->current_acount);
        // $this->saldo_actual = CurrentAcountHelper::getSaldo('client', $this->sale->client_id, $this->current_acount);

        $saldo = $this->saldo_actual + $debe;

        $this->current_acount->saldo = Numbers::redondear($saldo);
        $this->current_acount->save();

        CurrentAcountHelper::checkCurrentAcountSaldo($this->credit_account->id);

        $this->update_client_saldo();
    }

    function update_client_saldo() {

        $client_id = $this->sale->client_id;

        if ($this->es_el_ultimo_movimiento()) {


            $this->credit_account->saldo = $this->current_acount->saldo;
            $this->credit_account->save();

            CurrentAcountHelper::set_model_saldo($this->credit_account);

            /* 
                Si tiene saldo negativo (a favor del cliente)
                Se ejecuta checkPagos para que se marque esta venta como pagandose
            */
            $this->check_saldo_a_favor();

        } else {

            CurrentAcountHelper::checkSaldos($this->credit_account->id);
            CurrentAcountHelper::checkPagos($this->credit_account->id, true);
        }
    }

    function check_saldo_a_favor() {
        if ($this->saldo_actual < 0) {

            CurrentAcountHelper::checkPagos($this->credit_account->id, true);
        }
    }

    function es_el_ultimo_movimiento() {

        $current_acount = CurrentAcount::where('client_id', $this->sale->client_id)
                                    ->where('credit_account_id', $this->credit_account->id)
                                    ->whereDate('created_at', '>', $this->current_acount->created_at)
                                    ->first();

        return is_null($current_acount);
    }
}

