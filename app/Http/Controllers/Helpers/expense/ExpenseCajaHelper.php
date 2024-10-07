<?php

namespace App\Http\Controllers\Helpers\expense;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExpenseCajaHelper {

	static function guardar_movimiento_caja($expense) {

        if (!is_null($expense->caja_id)
            && $expense->caja_id != 0) {

    		$helper = new MovimientoCajaHelper();

            $data = [
                'concepto_movimiento_caja_id'   => 2,
                'ingreso'                       => null,
                'egreso'                        => $expense->amount,
                'notas'                         => $expense->expense_concept->name,
                'caja_id'                       => $expense->caja_id,
                'expense_id'					=> $expense->id,
            ];

            $helper->crear_movimiento($data);
        }


	}

    static function editar_movimiento_caja($expense) {

        $movimiento_caja = MovimientoCaja::where('expense_id', $expense->id)
                                            ->first();

        if (!is_null($movimiento_caja)) {

            $movimiento_caja->egreso    = $expense->amount;
            $movimiento_caja->notas     = $expense->expense_concept->name;

            $movimiento_caja->save();

            MovimientoCajaHelper::recalcular_saldos($movimiento_caja);

        } else {

            Self::guardar_movimiento_caja($expense);
        }


    }
	
}