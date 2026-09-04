<?php

namespace App\Http\Controllers\Helpers\expense;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExpenseCajaHelper {

	static function guardar_movimiento_caja($expense, $data) {

        // if (!is_null($expense->caja_id)
        //     && $expense->caja_id != 0) {

        if (
            isset($data['caja_id'])
            && !is_null($data['caja_id'])
        ) {

    		$helper = new MovimientoCajaHelper();

            $data = [
                'concepto_movimiento_caja_id'   => 2,
                'ingreso'                       => null,
                'egreso'                        => $data['amount'],
                'notas'                         => !is_null($expense->expense_concept) ? $expense->expense_concept->name : null,
                'caja_id'                       => $data['caja_id'],
                'expense_id'					=> $expense->id,
            ];

            $helper->crear_movimiento($data);
        }


	}

    /**
     * Reparte el monto NUEVO del gasto entre los métodos de pago ya adjuntos, en proporción
     * a lo que cada uno pagaba (tanda correctivos 2408, ítem 5).
     *
     * La SPA edita el gasto sin re-mandar el desglose (la tabla de métodos de pago del form
     * es de solo lectura), así que al cambiar `amount` el pivot quedaba con los montos
     * viejos: el estado de resultados mostraba el monto nuevo y el desglose/flujo de caja,
     * el viejo. Con un solo método (el caso normal) esto es una asignación directa del
     * total; con varios, mantiene las proporciones y el desglose vuelve a sumar el total.
     *
     * Los cheques NO se tocan: el monto de un cheque emitido no puede cambiar desde la
     * edición del gasto. El resto del desglose absorbe la diferencia.
     *
     * @param \App\Models\Expense $expense Gasto ya guardado con su `amount` nuevo.
     * @return void
     */
    static function escalar_desglose_por_nuevo_monto($expense) {

        $expense->load('current_acount_payment_methods');

        /** Métodos cuyo pivot se puede escalar (todo lo que no sea cheque). */
        $escalables = [];

        /** Suma actual del desglose escalable, base del factor de proporción. */
        $suma_actual = 0;

        /** Parte del gasto cubierta por cheques, que queda fija. */
        $suma_cheques = 0;

        foreach ($expense->current_acount_payment_methods as $payment_method) {

            if ($payment_method->type == 'cheque') {
                $suma_cheques += (float) $payment_method->pivot->amount;
                continue;
            }

            $suma_actual += (float) $payment_method->pivot->amount;
            $escalables[] = $payment_method;
        }

        if (count($escalables) == 0 || $suma_actual == 0) {
            return;
        }

        /** Lo que el desglose escalable tiene que sumar ahora. */
        $total_a_repartir = (float) $expense->amount - $suma_cheques;

        if ($total_a_repartir < 0) {
            $total_a_repartir = 0;
        }

        /** Factor de proporción entre el desglose viejo y el monto nuevo. */
        $factor = $total_a_repartir / $suma_actual;

        if ($factor == 1) {
            return;
        }

        foreach ($escalables as $payment_method) {

            /** Monto nuevo del método, manteniendo su proporción en el desglose. */
            $nuevo_amount = round((float) $payment_method->pivot->amount * $factor, 2);

            /** amount_cotizado escala por el mismo factor (la cotización no cambió). */
            $nuevo_amount_cotizado = null;

            if (!is_null($payment_method->pivot->amount_cotizado)) {
                $nuevo_amount_cotizado = round((float) $payment_method->pivot->amount_cotizado * $factor, 2);
            }

            $expense->current_acount_payment_methods()->updateExistingPivot($payment_method->id, [
                'amount'          => $nuevo_amount,
                'amount_cotizado' => $nuevo_amount_cotizado,
            ]);
        }
    }

    /**
     * Sincroniza los movimientos de caja del gasto con su desglose de métodos de pago
     * actual, y recalcula los saldos de las cajas afectadas.
     *
     * Existía desde siempre pero SIN llamadores (el bloque de ExpenseController::update()
     * que la invocaba estaba comentado) y con dos defectos que la hacían inejecutable:
     * manejaba un único movimiento con first() y el fallback llamaba a
     * guardar_movimiento_caja() sin el segundo argumento (error fatal). Reescrita al
     * conectarla (tanda correctivos 2408, ítem 5):
     *
     *  - un movimiento por caja del desglose: existe → se actualiza el egreso; no existe →
     *    se crea (guardar_movimiento_caja, que ya recalcula saldos al crear);
     *  - movimientos del gasto en cajas que ya no están en el desglose → se eliminan;
     *  - por cada caja editada o limpiada se recalculan los saldos con el mismo par que ya
     *    usa MovimientoCajaController::destroy() (recalcular_saldos por caja +
     *    set_apertura_caja_ingresos_egresos de la apertura tocada).
     *
     * Los cheques no generan movimiento de caja (mismo criterio que el store del gasto).
     *
     * @param \App\Models\Expense $expense Gasto con `amount` y pivot ya actualizados.
     * @return void
     */
    static function editar_movimiento_caja($expense) {

        $expense->load('current_acount_payment_methods');

        /** Movimientos de caja existentes del gasto, indexados por caja. */
        $movimientos_por_caja = [];

        foreach (MovimientoCaja::where('expense_id', $expense->id)->get() as $movimiento) {
            $movimientos_por_caja[$movimiento->caja_id] = $movimiento;
        }

        /** Cajas presentes en el desglose actual (para detectar movimientos huérfanos). */
        $cajas_del_desglose = [];

        /** Cajas cuyo saldo hay que recalcular a mano (editadas o limpiadas), caja_id => apertura_caja_id. */
        $cajas_a_recalcular = [];

        foreach ($expense->current_acount_payment_methods as $payment_method) {

            // Mismo criterio que el store: el cheque no mueve caja.
            if (
                $payment_method->type == 'cheque'
                || is_null($payment_method->pivot->caja_id)
            ) {
                continue;
            }

            /** Caja de este método de pago del desglose. */
            $caja_id = $payment_method->pivot->caja_id;

            $cajas_del_desglose[$caja_id] = true;

            if (isset($movimientos_por_caja[$caja_id])) {

                /** El movimiento ya existe: se actualiza el egreso con el monto nuevo del método. */
                $movimiento = $movimientos_por_caja[$caja_id];

                $movimiento->egreso = $payment_method->pivot->amount;
                $movimiento->notas  = !is_null($expense->expense_concept) ? $expense->expense_concept->name : null;
                $movimiento->save();

                $cajas_a_recalcular[$caja_id] = $movimiento->apertura_caja_id;

            } else {

                // No había movimiento para esta caja: se crea igual que en el alta del
                // gasto. crear_movimiento() ya recalcula saldos y totales de la apertura.
                Self::guardar_movimiento_caja($expense, [
                    'amount'  => $payment_method->pivot->amount,
                    'caja_id' => $caja_id,
                ]);
            }
        }

        /*
         * Movimientos del gasto en cajas que ya no aparecen en el desglose: se eliminan,
         * y esa caja también entra al recálculo.
         */
        foreach ($movimientos_por_caja as $caja_id => $movimiento) {

            if (!isset($cajas_del_desglose[$caja_id])) {

                $apertura_caja_id = $movimiento->apertura_caja_id;

                $movimiento->delete();

                $cajas_a_recalcular[$caja_id] = $apertura_caja_id;
            }
        }

        foreach ($cajas_a_recalcular as $caja_id => $apertura_caja_id) {

            MovimientoCajaHelper::recalcular_saldos(null, $caja_id);

            if (!is_null($apertura_caja_id)) {
                MovimientoCajaHelper::set_apertura_caja_ingresos_egresos($apertura_caja_id);
            }
        }
    }

}
