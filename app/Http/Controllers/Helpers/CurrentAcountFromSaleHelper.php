<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountFromSaleHelper extends Controller {

    public $sale;
    public $current_acount;

    function __construct($sale, $index = null) {
        $this->sale = $sale;
    }

    function crear_current_acount() {

        $debe = $this->sale->total;

        $this->current_acount = CurrentAcount::create([
            'detalle'     => 'Venta N°'.$this->sale->num,
            'debe'        => $debe,
            'status'      => 'sin_pagar',
            'client_id'   => $this->sale->client_id,
            'seller_id'   => $this->sale->seller_id,
            'sale_id'     => $this->sale->id,
            // 'description' => CurrentAcountHelper::getDescription($this->sale, $this->debe_sin_descuentos),
            'created_at'  => $this->sale->created_at,
            'employee_id' => UserHelper::userId(false),
        ]);

        $saldo_actual = CurrentAcountHelper::getSaldo('client', $this->sale->client_id, $this->current_acount);

        $saldo = $saldo_actual + $debe;

        $this->current_acount->saldo = Numbers::redondear($saldo);
        $this->current_acount->save();

        $this->check_current_acount_saldo();

        $this->update_client_saldo();
    }

    function check_current_acount_saldo() {

        CurrentAcountHelper::checkCurrentAcountSaldo('client', $this->sale->client_id);
    }

    function update_client_saldo() {

        $client_id = $this->sale->client_id;

        if ($this->es_el_ultimo_movimiento()) {

            $client = Client::find($client_id);
            $client->saldo = $this->current_acount->saldo;
            $client->save();

        } else {

            CurrentAcountHelper::checkSaldos('client', $client_id);
            CurrentAcountHelper::checkPagos('client', $client_id, true);
        }
    }

    function es_el_ultimo_movimiento() {

        $current_acount = CurrentAcount::where('client_id', $this->sale->client_id)
                                    ->whereDate('created_at', '>', $this->current_acount->created_at)
                                    ->first();

        return is_null($current_acount);
    }
}

