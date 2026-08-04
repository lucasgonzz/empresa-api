<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\SellerCommissionHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Models\Caja;
use App\Models\ConceptoMovimientoCaja;
use App\Models\Seller;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Grupo 268 · Prompt 03 — pago a un vendedor con varios metodos de pago en un solo movimiento,
 * cada uno con su caja, generando egresos reales de caja. Espeja el patron de
 * `PaymentMethodHelper` + el pivot `current_acount_current_acount_payment_method`, pero sin
 * reusar esas funciones tal cual: asumen la relacion `current_acount_payment_methods()` y
 * `PaymentMethodHelper::attach_payment_methods()` ademas crea cheques, que quedan fuera de
 * alcance para el pago a un vendedor.
 */
class PagoVendedorHelper {

    /**
     * Arma el pago completo dentro de una transaccion: si falla la creacion de un movimiento de
     * caja, no puede quedar el `haber` registrado sin su contrapartida.
     *
     * @param \Illuminate\Http\Request $request Trae seller_id, moneda_id y current_acount_payment_methods.
     * @param int $seller_id
     * @return \App\Models\SellerCommission
     */
    static function pagar($request, $seller_id) {

        return DB::transaction(function () use ($request, $seller_id) {

            $moneda_id = $request->moneda_id;
            if (is_null($moneda_id) || $moneda_id == 0) {
                $moneda_id = 1;
            }

            $payment_methods = $request->current_acount_payment_methods;
            if (is_null($payment_methods)) {
                $payment_methods = [];
            }

            // No se confia en un total que mande el frontend: se recalcula en el backend, sumado
            // SIEMPRE en la moneda del ledger (amount_cotizado cuando existe, amount cuando no).
            $haber = 0;
            foreach ($payment_methods as $payment_method) {
                $haber += Self::monto_para_ledger($payment_method);
            }
            $haber = Numbers::redondear($haber);

            if ($haber <= 0) {
                // Ningun metodo de pago trajo un monto valido: no se deja una fila de pago en
                // $0 en el ledger. El controller devuelve 422 cuando esto pasa (null = abortado).
                return null;
            }

            $seller = Seller::find($seller_id);

            $ct = new Controller();

            $seller_commission = SellerCommission::create([
                'num'           => $ct->num('seller_commissions'),
                'seller_id'     => $seller_id,
                'description'   => SellerCommissionHelper::getDescription(),
                'haber'         => $haber,
                'moneda_id'     => $moneda_id,
                'status'        => 'active',
                'liquidada_at'  => now(),
                'user_id'       => $ct->userId(),
            ]);

            Self::attach_payment_methods($seller_commission, $payment_methods);

            // Los movimientos de caja se arman a partir del pivot YA PERSISTIDO, no del array
            // crudo del request: son dos loops con el mismo criterio de "que fila es valida" por
            // definicion, no dos filtros que puedan desalinearse (ej. una fila sin
            // current_acount_payment_method_id sumaria al haber y generaria un egreso de caja sin
            // dejar rastro en el pivot - despues destroy() no encontraria caja que bloquear y el
            // egreso quedaria huerfano).
            Self::crear_movimientos_caja($seller, $seller_commission->payment_methods()->get());

            ComisionesHelper::recalcular_saldos($seller_id, $moneda_id);

            return $seller_commission;
        });
    }

    /**
     * Monto que se acumula en el ledger de la comision (la moneda que el usuario esta mirando):
     * amount_cotizado cuando existe (metodo en otra moneda, ya convertido), amount cuando no.
     * Mismo criterio que el resto del sistema para pagos con moneda cruzada.
     *
     * @param array $payment_method
     * @return float
     */
    static function monto_para_ledger($payment_method) {

        if (
            isset($payment_method['amount_cotizado'])
            && !is_null($payment_method['amount_cotizado'])
            && $payment_method['amount_cotizado'] !== ''
            && (float)$payment_method['amount_cotizado'] > 0
        ) {
            return (float)$payment_method['amount_cotizado'];
        }

        return isset($payment_method['amount']) ? (float)$payment_method['amount'] : 0;
    }

    /**
     * Attach propio al pivot seller_commission_current_acount_payment_method (NO reusa
     * PaymentMethodHelper::attach_payment_methods(): esa asume current_acount_payment_methods()
     * y crea cheques, fuera de alcance aca).
     *
     * @param \App\Models\SellerCommission $seller_commission
     * @param array $payment_methods
     * @return void
     */
    static function attach_payment_methods($seller_commission, $payment_methods) {

        foreach ($payment_methods as $payment_method) {

            if (!isset($payment_method['current_acount_payment_method_id'])) {
                continue;
            }

            $amount = Self::normalizar_decimal(isset($payment_method['amount']) ? $payment_method['amount'] : null);

            if (is_null($amount) || $amount <= 0) {
                // Fila vacia (ej. metodo agregado por el usuario y despues no completado): no
                // deja rastro en el pivot, para que no quede desalineada con el haber ni con los
                // movimientos de caja (ambos ya ignoran esta fila via monto_para_ledger()).
                continue;
            }

            $caja_id = null;
            if (isset($payment_method['caja_id']) && $payment_method['caja_id'] != 0) {
                $caja_id = $payment_method['caja_id'];
            }

            $seller_commission->payment_methods()->attach($payment_method['current_acount_payment_method_id'], [
                'amount'            => $amount,
                'caja_id'           => $caja_id,
                'amount_cotizado'   => Self::normalizar_decimal(isset($payment_method['amount_cotizado']) ? $payment_method['amount_cotizado'] : null),
                'cotizacion'        => Self::normalizar_decimal(isset($payment_method['cotizacion']) ? $payment_method['cotizacion'] : null),
                'moneda_id'         => isset($payment_method['moneda_id']) ? $payment_method['moneda_id'] : null,
                'cuota_id'          => isset($payment_method['cuota_id']) ? $payment_method['cuota_id'] : null,
            ]);
        }
    }

    /**
     * El SPA manda columnas decimales vacias como `''` (fabrica de metodos de pago en blanco),
     * y la conexion corre con STRICT_TRANS_TABLES: un INSERT con `''` en una columna decimal
     * tira 1366 "Incorrect decimal value" y aborta toda la transaccion. Se normaliza a `null`
     * antes de llegar al pivot.
     *
     * @param mixed $value
     * @return float|null
     */
    static function normalizar_decimal($value) {
        if (is_null($value) || $value === '') {
            return null;
        }
        return (float)$value;
    }

    /**
     * Un egreso de caja por cada metodo de pago con caja_id cargado (distinto de null y de 0).
     * Es un EGRESO: siempre sale plata de la caja al pagarle a un vendedor, a diferencia de
     * CurrentAcountCajaHelper::guardar_pago() que decide ingreso/egreso por model_name == 'client'
     * (por eso no se reusa esa funcion aca).
     *
     * El movimiento se registra en la moneda de la CAJA (amount, no amount_cotizado): si la
     * moneda del metodo no coincide con la de la caja, es un error de datos, se loguea y se usa
     * igual el amount (mismo criterio del prompt, no se bloquea el pago por esto).
     *
     * @param \App\Models\Seller $seller
     * @param \Illuminate\Support\Collection $payment_methods Filas YA persistidas del pivot
     *        (`$seller_commission->payment_methods()->get()`), no el array crudo del request:
     *        asi el criterio de "que fila es valida" es uno solo (el que ya aplico el attach),
     *        sin riesgo de que este loop genere un egreso para una fila que no quedo en el pivot.
     * @return void
     */
    static function crear_movimientos_caja($seller, $payment_methods) {

        $concepto = ConceptoMovimientoCaja::where('name', 'Pago a Vendedor')->first();

        if (is_null($concepto)) {
            Log::info('No existe el concepto de movimiento de caja "Pago a Vendedor" (falta correr el seeder del grupo 268, prompt 01) - se usa "Varios" en su lugar.');
            $concepto = ConceptoMovimientoCaja::where('name', 'Varios')->first();
        }

        foreach ($payment_methods as $payment_method) {

            $caja_id = $payment_method->pivot->caja_id;

            if (is_null($caja_id) || $caja_id == 0) {
                // Sin caja: el pago se registra igual (ya se creo el haber/pivot arriba), pero
                // no genera movimiento de caja.
                continue;
            }

            $amount = (float)$payment_method->pivot->amount;

            $caja = Caja::find($caja_id);

            if (is_null($caja)) {
                Log::info('crear_movimientos_caja: no existe la caja_id '.$caja_id.', se omite el movimiento.');
                continue;
            }

            if (
                !is_null($payment_method->pivot->moneda_id)
                && !is_null($caja->moneda_id)
                && (int)$payment_method->pivot->moneda_id != (int)$caja->moneda_id
            ) {
                Log::info('crear_movimientos_caja: la moneda del metodo de pago ('.$payment_method->pivot->moneda_id.') no coincide con la de la caja '.$caja_id.' ('.$caja->moneda_id.'). Se usa igual el amount.');
            }

            $movimiento_caja_helper = new MovimientoCajaHelper();
            $movimiento_caja_helper->crear_movimiento([
                'concepto_movimiento_caja_id' => !is_null($concepto) ? $concepto->id : null,
                'ingreso'                     => null,
                'egreso'                      => $amount,
                'notas'                       => 'Pago a vendedor '.(!is_null($seller) ? $seller->name : ''),
                'caja_id'                     => $caja_id,
            ]);
        }
    }

}
