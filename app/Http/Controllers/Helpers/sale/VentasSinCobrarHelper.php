<?php

namespace App\Http\Controllers\Helpers\sale;

class VentasSinCobrarHelper {
	
	static function ordenar_por_clientes($sales) {

		$clientes = [];

		foreach ($sales as $venta_sin_cobrar) {

			$cliente_id = $venta_sin_cobrar->client_id;

			if (isset($clientes[$venta_sin_cobrar->client_id])) {

				$clientes[$cliente_id]['ventas_sin_cobrar'][] = $venta_sin_cobrar;

				// $clientes[$venta_sin_cobrar->client_id]['ventas_sin_cobrar'][] = $venta_sin_cobrar;

			} else {

				$clientes[$cliente_id] = [
                    'client' => $venta_sin_cobrar->client,
                    'ventas_sin_cobrar' => [$venta_sin_cobrar]
                ];

				// $clientes[$venta_sin_cobrar->client_id] = [];

				// $clientes[$venta_sin_cobrar->client_id]['client'] = $venta_sin_cobrar->client;

				// $clientes[$venta_sin_cobrar->client_id]['ventas_sin_cobrar'] = [];

				// $clientes[$venta_sin_cobrar->client_id]['ventas_sin_cobrar'][] = $venta_sin_cobrar;

			
			}

		}

		$clientes_array = array_values($clientes);

		return $clientes_array;

	}

}