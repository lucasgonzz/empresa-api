<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Models\Sale;

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

	/**
	 * Normaliza el `dias` que puede venir por query string en el endpoint de ventas sin cobrar.
	 *
	 * Devuelve un entero SOLO si el valor es una cadena de digitos limpia. Cualquier otra cosa
	 * -null, vacio, '-5', 'abc', '5.5'- devuelve null, que es la senal de "no vino nada usable"
	 * y deja que la cascada por rol del controller siga mandando, exactamente como hasta hoy.
	 *
	 * Se usa `ctype_digit()` y no `filter_var()`/`is_numeric()` a proposito: rechaza el signo, el
	 * punto decimal y el texto de una sola pasada, sin nada de PHP 8 y sin sorpresas de casteo
	 * ('5.5' es numerico para `is_numeric()` y se comeria el decimal en el `(int)`).
	 *
	 * El '0' SI es valido: significa "traeme todas las ventas sin cobrar, sin importar la
	 * antiguedad", que es un pedido legitimo del usuario.
	 *
	 * @param mixed $valor Lo que llego por `$request->query('dias')`.
	 * @return int|null Entero valido, o null si no hay que pisar la cascada.
	 */
	static function dias_del_input($valor) {

		if (is_null($valor)) {
			return null;
		}

		$limpio = trim((string) $valor);

		if ($limpio === '' || !ctype_digit($limpio)) {
			return null;
		}

		return (int) $limpio;
	}

	/**
	 * La query de ventas sin cobrar, en un solo lugar.
	 *
	 * Es la query que vivia dentro de `SaleController::ventas_sin_cobrar()`, extraida TAL CUAL:
	 * mismo `whereHas` sobre `current_acount` y mismo `whereRaw` con el
	 * `COALESCE(sales.dias_alerta_venta_no_cobrada_personalizado, ?)`, que hace que el umbral
	 * propio de una venta le gane siempre al umbral general.
	 *
	 * Devuelve el Builder sin ejecutar, para que cada caller le agregue lo suyo (el `with()`, el
	 * `orderBy()`, un `where('client_id', ...)` encima) sin que el recorte se reescriba dos veces.
	 *
	 * @param int      $owner_id    Dueno de las ventas (`sales.user_id`).
	 * @param int|null $employee_id Si viene, recorta a las ventas de ese empleado
	 *                              (`ver_solo_las_ventas_suyas`). null = sin recorte.
	 * @param int      $dias        Umbral general de antiguedad, en dias.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	static function query_de_ventas($owner_id, $employee_id, $dias) {

		$sales = Sale::where('user_id', $owner_id)
						->whereHas('current_acount', function($q) {
							return $q->where('debe', '>', 0)
										->where('status', 'sin_pagar')
										->orWhere('status', 'pagandose')
										->where(function ($query) {
											$query->whereNull('pagandose')
											->orWhereRaw('debe - pagandose > 300');
										});
						})
						// ->whereHas('client', function ($query) {
						//     $query->whereHas(function ($q) {
						//         $q->whereHas('credit_account', function($q_c_a) {
						//             $q_c_a->where('saldo', '>', 300);
						//         })
						//     });
						//     // $query->where('saldo', '>', 300);
						// })
						->whereRaw(
							'DATE(`sales`.`created_at`) <= DATE_SUB(CURDATE(), INTERVAL COALESCE(`sales`.`dias_alerta_venta_no_cobrada_personalizado`, ?) DAY)',
							[$dias]
						);

		if (!is_null($employee_id)) {
			$sales = $sales->where('employee_id', $employee_id);
		}

		return $sales;
	}

}
