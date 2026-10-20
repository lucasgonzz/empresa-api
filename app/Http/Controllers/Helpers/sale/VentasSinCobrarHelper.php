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
	 * Es la query que vivia dentro de `SaleController::ventas_sin_cobrar()`: mismo `whereHas` sobre
	 * `current_acount` y mismo `whereRaw` con el
	 * `COALESCE(sales.dias_alerta_venta_no_cobrada_personalizado, ?)`, que hace que el umbral
	 * propio de una venta le gane siempre al umbral general. (Salvo que el que llama pida
	 * `$respetar_umbral_propio = false`: lo hace el asistente cuando la persona dijo los dias, ver abajo.)
	 *
	 * Devuelve el Builder sin ejecutar, para que cada caller le agregue lo suyo (el `with()`, el
	 * `orderBy()`, un `where('client_id', ...)` encima) sin que el recorte se reescriba dos veces.
	 *
	 * 🔴 DOS CORRECCIONES DEL 21/9/2026 (mision asistente-ventas-y-fotos), al exponer esta query en
	 * el asistente. Se corrigen ACA y no en una copia porque una segunda definicion de "venta
	 * impaga" es el camino mas corto a que la pantalla y el chat le den dos numeros distintos al
	 * mismo comerciante. Las dos son a favor de la pantalla, no en contra:
	 *
	 *   1. Los parentesis del `whereHas`. Estaba escrito
	 *      `where(debe > 0)->where(status='sin_pagar')->orWhere(status='pagandose')->where(...)`,
	 *      y por la precedencia de AND sobre OR de MySQL eso se lee
	 *      `(debe > 0 AND status='sin_pagar') OR (status='pagandose' AND (...))`: el `debe > 0`
	 *      aplicaba SOLO a la primera rama, asi que una cuenta `pagandose` con `debe` en 0 o en
	 *      negativo entraba igual al listado de ventas sin cobrar. Ahora el `debe > 0` esta afuera
	 *      del OR y cubre las dos ramas, que es lo que el listado siempre quiso decir.
	 *
	 *   2. `soloVentasReales()`. Sin el scope, una venta CONTENEDORA de consolidacion AFIP
	 *      (`is_consolidacion_facturacion = 1`) podria aparecer como venta sin cobrar y sumar
	 *      deuda que ya esta contada en las ventas que agrupa. Hoy esas contenedoras no generan
	 *      cuenta corriente, asi que en la practica no cambia ninguna fila — pero eso no estaba
	 *      escrito en ningun lado y es justo lo que se rompe en silencio el dia que cambie. Es el
	 *      mismo scope que ya usan Rendimiento, performance y el resumen de ventas del asistente.
	 *
	 * ⚠️ LO QUE NO FILTRA, Y NO SE LE AGREGA: `sales.terminada`. La pantalla de ventas sin cobrar
	 * tampoco lo filtra, y el valor de esta query es que el chat y la pantalla contesten IGUAL.
	 * Agregarlo aca haria que el asistente conteste un numero que el comerciante no puede
	 * reproducir en ninguna pantalla.
	 *
	 * @param int      $owner_id    Dueno de las ventas (`sales.user_id`).
	 * @param int|null $employee_id Si viene, recorta a las ventas de ese empleado
	 *                              (`ver_solo_las_ventas_suyas`). null = sin recorte.
	 * @param int      $dias        Umbral general de antiguedad, en dias.
	 * @param bool     $con_umbral  false = sin NINGUN umbral de antiguedad, ni el general ni el
	 *                              propio de cada venta: TODA la deuda viva. Lo usa el asistente para
	 *                              la "deuda total" de un cliente (mision asistente-altas-y-conversacion,
	 *                              16/10/2026): "me deben mas de X hace mas de N dias" compara X contra
	 *                              todo lo que el cliente debe, y una venta con su propio umbral de
	 *                              alerta todavia sin cumplir sigue siendo plata que debe. Por defecto
	 *                              true: los demas llamadores no cambian.
	 * @param bool     $respetar_umbral_propio  Solo cuenta con `$con_umbral = true`. true (el default, y lo
	 *                              que usan la pantalla y la alerta automatica) = el `COALESCE` de
	 *                              siempre: el umbral propio de la venta le gana al general. false = SOLO
	 *                              el `$dias` que vino, sin mirar el umbral propio de ninguna venta:
	 *                              `DATE(sales.created_at) <= DATE_SUB(CURDATE(), INTERVAL ? DAY)`.
	 *
	 *                              🔴 POR QUE EXISTE (mision asistente-arreglos-segunda-prueba, 19/10/2026).
	 *                              En la segunda prueba por WhatsApp contra demo3 el dueno pidio "los que
	 *                              me deben hace mas de 20 dias" y el asistente dejo afuera una venta de 23
	 *                              dias: toda venta a cuenta corriente sembrada en demo3 tiene
	 *                              `dias_alerta_venta_no_cobrada_personalizado = 30` (SemillaHelper), y el
	 *                              COALESCE la media contra 30 y no contra los 20 que dijo el dueno. Los
	 *                              dias que pide una persona en el chat son SU pregunta, no la alerta: por
	 *                              eso el asistente, cuando la persona dijo dias, llama con false. La
	 *                              pantalla "Ventas sin cobrar" y la alerta automatica no cambian (las
	 *                              cuida Alertas/1_Ventas_sin_cobrar_dias_Test).
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	static function query_de_ventas($owner_id, $employee_id, $dias, $con_umbral = true, $respetar_umbral_propio = true) {

		$sales = Sale::where('user_id', $owner_id)
						->soloVentasReales()
						->whereHas('current_acount', self::condicion_de_deuda())
						// ->whereHas('client', function ($query) {
						//     $query->whereHas(function ($q) {
						//         $q->whereHas('credit_account', function($q_c_a) {
						//             $q_c_a->where('saldo', '>', 300);
						//         })
						//     });
						//     // $query->where('saldo', '>', 300);
						// })
						;

		if ($con_umbral && $respetar_umbral_propio) {
			$sales = $sales->whereRaw(
				'DATE(`sales`.`created_at`) <= DATE_SUB(CURDATE(), INTERVAL COALESCE(`sales`.`dias_alerta_venta_no_cobrada_personalizado`, ?) DAY)',
				[$dias]
			);
		} elseif ($con_umbral) {
			// Los dias que pidio la persona, tal cual: el umbral propio de la venta no entra (ver el docblock).
			$sales = $sales->whereRaw(
				'DATE(`sales`.`created_at`) <= DATE_SUB(CURDATE(), INTERVAL ? DAY)',
				[(int) $dias]
			);
		}

		if (!is_null($employee_id)) {
			$sales = $sales->where('employee_id', $employee_id);
		}

		return $sales;
	}

	/**
	 * QUE ES UNA DEUDA VIVA, en una sola definicion: la condicion sobre la fila de `current_acounts`
	 * que hace que su venta cuente como "sin cobrar".
	 *
	 * Es la condicion que `query_de_ventas()` le pasa al `whereHas`, devuelta como Closure para que
	 * quien tenga que SUMAR esas filas —y no solo filtrar las ventas— use exactamente la misma y no
	 * una copia. Sin esto, el total que informa el asistente y el listado que ve la pantalla podrian
	 * estar mirando conjuntos distintos y nadie tendria como darse cuenta.
	 *
	 * Las columnas van SIN calificar (`debe`, `status`, `pagandose`) a proposito: asi sirve tanto
	 * adentro del `whereHas` —donde la subquery ya corre sobre `current_acounts`— como en un
	 * `DB::table('current_acounts')` directo. Si algun caller le joinea otra tabla con una columna
	 * `status`, tiene que calificarla el, no este helper.
	 *
	 * @return \Closure
	 */
	static function condicion_de_deuda() {

		return function ($q) {

			$q->where('debe', '>', 0)
				->where(function ($rama) {
					$rama->where('status', 'sin_pagar')
						->orWhere(function ($pagandose) {
							$pagandose->where('status', 'pagandose')
								->where(function ($query) {
									$query->whereNull('pagandose')
										->orWhereRaw('debe - pagandose > 300');
								});
						});
				});
		};
	}

}
