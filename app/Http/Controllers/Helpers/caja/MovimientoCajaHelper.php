<?php

namespace App\Http\Controllers\Helpers\caja;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MovimientoCajaHelper {

	function crear_movimiento($data) {

		$this->caja_id = $data['caja_id'];

		$apertura_caja_id = $this->get_apertura_caja_id($data);
		$employee_id = UserHelper::userId(false);

		$this->movimiento_caja = MovimientoCaja::create([
            'concepto_movimiento_caja_id'	=> $data['concepto_movimiento_caja_id'],

            'ingreso'						=> $data['ingreso'],
            'egreso'						=> $data['egreso'],

            'notas'							=> $data['notas'],
            
            'employee_id'					=> $employee_id,
            'apertura_caja_id'				=> $apertura_caja_id,

            'sale_id'						=> isset($data['sale_id']) ? $data['sale_id'] : null, 
            'expense_id'					=> isset($data['expense_id']) ? $data['expense_id'] : null, 

            'caja_id'						=> $data['caja_id'],
		]);


		$this->set_saldos();

		return $this->movimiento_caja;

	}

	function get_apertura_caja_id($data) {

		if (isset($data['apertura_caja_id'])) {

			return $data['apertura_caja_id'];
		}

		$current_aperutra_caja_id = $this->get_current_aperutra_caja();

		return $current_aperutra_caja_id;
	}

	function get_current_aperutra_caja() {

		$current_aperutra_caja = AperturaCaja::where('caja_id', $this->caja_id)
												->orderBy('created_at', 'DESC')
												->first();

		return $current_aperutra_caja->id;
	}

	function set_saldos() {

		$caja = Caja::find($this->movimiento_caja->caja_id);
		$saldo_actual = $caja->saldo;

		$saldo = null;

		if (!is_null($this->movimiento_caja->ingreso)) {

			$saldo = $saldo_actual + $this->movimiento_caja->ingreso;

		} else if (!is_null($this->movimiento_caja->egreso)) {

			$saldo = $saldo_actual - $this->movimiento_caja->egreso;

		}

		$this->movimiento_caja->saldo = $saldo;
		$this->movimiento_caja->save();

		$this->movimiento_caja->caja->saldo = $saldo;
		$this->movimiento_caja->caja->save();
	}

    static function recalcular_saldos($desde_movimiento_caja = null, $caja_id = null) {

    	$caja = null;

    	$movimientos_caja_para_actualizar = null;
    	$saldo_anterior = null;

    	if (!is_null($desde_movimiento_caja)) {

    		$caja = Caja::find($desde_movimiento_caja->caja_id);

	    	$desde_movimiento_caja = Self::actualizar_saldo($desde_movimiento_caja);

	    	$movimientos_caja_para_actualizar = MovimientoCaja::where('apertura_caja_id', $desde_movimiento_caja->apertura_caja_id)
	    											->where('created_at', '>', $desde_movimiento_caja->created_at)
	    											->orderBy('created_at', 'ASC')
	    											->get();

	    	$saldo_anterior = $desde_movimiento_caja->saldo;

    	} else if (!is_null($caja_id)) {

    		$caja = Caja::find($caja_id);

	    	$movimientos_caja_para_actualizar = MovimientoCaja::where('apertura_caja_id', $caja->current_aperutra_caja_id)
	    											->orderBy('created_at', 'ASC')
	    											->get();

	    	$saldo_anterior = AperturaCaja::find($caja->current_aperutra_caja_id)->saldo_apertura;
    	}



    	foreach ($movimientos_caja_para_actualizar as $movimiento_caja) {
    		
			if (!is_null($movimiento_caja->ingreso)) {

				$nuevo_saldo = $saldo_anterior + $movimiento_caja->ingreso;

			} else if (!is_null($movimiento_caja->egreso)) {

				$nuevo_saldo = $saldo_anterior - $movimiento_caja->egreso;

			}

    		$movimiento_caja->saldo = $nuevo_saldo;
    		$movimiento_caja->save();

    		$saldo_anterior = $movimiento_caja->saldo;
    	}

    	$caja->saldo = $saldo_anterior;
    	$caja->save();

    }

    static function actualizar_saldo($movimiento_caja) {

    	$movimiento_anterior = MovimientoCaja::where('apertura_caja_id', $movimiento_caja->apertura_caja_id)
    											->where('created_at', '<', $movimiento_caja->created_at)
    											->orderBy('created_at', 'DESC')
    											->first();

    	if (!is_null($movimiento_anterior)) {

    		$nuevo_saldo = null;

			if (!is_null($movimiento_caja->ingreso)) {

				$nuevo_saldo = $movimiento_anterior->saldo + $movimiento_caja->ingreso;

			} else if (!is_null($movimiento_caja->egreso)) {

				$nuevo_saldo = $movimiento_anterior->saldo - $movimiento_caja->egreso;

			}

			Log::info('Nuevo saldo para movimiento con saldo de '.$movimiento_caja->saldo);

			$movimiento_caja->saldo = $nuevo_saldo;
			$movimiento_caja->save();
			Log::info('Nuevo saldo: '.$nuevo_saldo);
			
    	} else {

    		$nuevo_saldo = $movimiento_caja->apertura_caja->saldo_apertura;

    		if (!is_null($movimiento_caja->ingreso)) {
    			$nuevo_saldo += $movimiento_caja->ingreso;
    		} else if (!is_null($movimiento_caja->egreso)) {
    			$nuevo_saldo -= $movimiento_caja->egreso;
    		}

			$movimiento_caja->saldo = $nuevo_saldo;
			$movimiento_caja->save();
			Log::info('No habia movimientos anteriores');
    	}

    	return $movimiento_caja;
    }

}