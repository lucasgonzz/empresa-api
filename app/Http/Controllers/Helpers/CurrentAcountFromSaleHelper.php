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

        $this->update_client_saldo();

        return $this->credit_account->id;
    }

    /**
     * Deja la cuenta corriente consistente después de meter el movimiento de la venta.
     *
     * 🔴 SIEMPRE RECALCULA LA CADENA ENTERA (misión cuenta-corriente-carrera-y-velocidad,
     * 23/9/2026). Hasta hoy había dos atajos, y los dos dejaban cadenas cortadas:
     *   - `es_el_ultimo_movimiento()` comparaba con `whereDate`: una venta con fecha anterior a otros
     *     movimientos DEL MISMO DÍA se tomaba como la última, se le copiaba su saldo a la cuenta y no
     *     se recalculaba nada de lo que venía después.
     *   - `checkCurrentAcountSaldo()` recalculaba solo desde el antepenúltimo movimiento.
     * Con el índice de la cuenta y checkSaldos() en una sola lectura, recalcular la cuenta entera
     * cuesta una consulta más las filas que cambian: el atajo ya no ahorraba nada.
     *
     * La re-imputación (checkPagos), que es la parte cara, sigue en los mismos dos casos que antes:
     * hay movimientos posteriores a la venta (un pago posterior puede tener que saldarla a ella antes
     * que a otra) o el cliente tenía saldo a favor (la venta se tiene que marcar pagándose).
     *
     * @return void
     */
    function update_client_saldo() {

        CurrentAcountHelper::checkSaldos($this->credit_account->id);

        if ($this->tiene_movimientos_posteriores() || $this->saldo_actual < 0) {

            CurrentAcountHelper::checkPagos($this->credit_account->id, true);
        }
    }

    /**
     * Si hay en la cuenta algún movimiento después del de la venta, en el orden de la cadena
     * (`created_at, id`), no por día.
     *
     * @return bool
     */
    function tiene_movimientos_posteriores() {

        $current_acount = $this->current_acount;

        return CurrentAcount::where('credit_account_id', $this->credit_account->id)
                            ->where('id', '!=', $current_acount->id)
                            ->where(function ($q) use ($current_acount) {
                                $q->where('created_at', '>', $current_acount->created_at)
                                  ->orWhere(function ($q2) use ($current_acount) {
                                      $q2->where('created_at', '=', $current_acount->created_at)
                                         ->where('id', '>', $current_acount->id);
                                  });
                            })
                            ->exists();
    }
}

