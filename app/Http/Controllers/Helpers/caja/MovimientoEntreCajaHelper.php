<?php

namespace App\Http\Controllers\Helpers\caja;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use Illuminate\Support\Facades\Log;

class MovimientoEntreCajaHelper {

	function mover($movimiento_entre_caja) {

		$this->movimiento_entre_caja = $movimiento_entre_caja;

		$this->movimiento_caja_helper = new MovimientoCajaHelper();

		$this->egreso_caja_origen();

		$this->ingreso_caja_destino();
	}

	function egreso_caja_origen() {

        $notas = 'Transferencia HACIA '.$this->movimiento_entre_caja->to_caja->name;

        $data = [
            'concepto_movimiento_caja_id'   => 5,
            'ingreso'                       => null,
            'egreso'                        => $this->movimiento_entre_caja->amount,
            'notas'                         => $notas,
            'caja_id'                       => $this->movimiento_entre_caja->from_caja_id,
            'employee_id'                   => $this->movimiento_entre_caja->employee_id,
            'movimiento_entre_caja_id'		=> $this->movimiento_entre_caja->id,
        ];

        $this->movimiento_caja_helper->crear_movimiento($data);
	}

	function ingreso_caja_destino() {
        
        $notas = 'Transferencia DESDE '.$this->movimiento_entre_caja->from_caja->name;

        $data = [
            'concepto_movimiento_caja_id'   => 5,
            'ingreso'                       => $this->movimiento_entre_caja->amount,
            'egreso'                       	=> null,
            'notas'                         => $notas,
            'caja_id'                       => $this->movimiento_entre_caja->to_caja_id,
            'employee_id'                   => $this->movimiento_entre_caja->employee_id,
            'movimiento_entre_caja_id'		=> $this->movimiento_entre_caja->id,
        ];

        $this->movimiento_caja_helper->crear_movimiento($data);
	}

}