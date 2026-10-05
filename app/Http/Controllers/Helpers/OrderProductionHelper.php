<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\OrderProductionRecipe;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Http\Controllers\Helpers\sale\DeleteSaleHelper;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\OrderProduction;
use App\Models\OrderProductionStatus;
use App\Models\Sale;
use App\Notifications\OrderProductionNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderProductionHelper {

	static function checkFinieshed($order_production) {
		if ($order_production->finished && is_null($order_production->budget_id)) {
			Self::deleteCurrentAcount($order_production);
			Self::saveCurrentAcount($order_production);
	        CurrentAcountHelper::checkSaldos('client', $order_production->client_id);
	        Self::saveSale($order_production);
		}
	}

	/**
	 * Baja completa de una orden de producción: sus ventas, sus movimientos de cuenta corriente y
	 * la orden. Es todo lo que hace `OrderProductionController::destroy()` salvo buscar la orden,
	 * responder y notificar; mismo reparto que `SaleController::destroy()` →
	 * `DeleteSaleHelper::eliminar_venta()`.
	 *
	 * Misión orden-produccion-baja-de-venta (5/10/2026). Hasta acá el destroy() borraba el
	 * movimiento de la orden fuera de toda transacción, recalculaba con una firma muerta (500 con la
	 * cuenta ya sin el débito) y, si llegaba, borraba la venta con un `$sale->delete()` crudo: sin
	 * devolver stock, sin sacarla de la cuenta corriente y sin mirar si estaba facturada.
	 *
	 * Un rechazo NO escribe nada: la orden, sus ventas y su cuenta corriente quedan como estaban.
	 *
	 * @param  \App\Models\OrderProduction  $order_production  La orden, ya verificada como del dueño.
	 * @param  mixed                        $instance          Controlador que dispara, para las notificaciones de la baja de cada venta.
	 * @return array  [
	 *                  'rechazo'               => string|null  Mensaje para el 422, o null si se borró.
	 *                  'error_venta_facturada' => bool         true si el rechazo es por un comprobante de ARCA.
	 *                  'movimientos'           => \App\Models\CurrentAcount[]  Movimientos de la orden que se borraron (vacío si hubo rechazo).
	 *                ]
	 */
	static function eliminar_orden($order_production, $instance) {

		/*
			Lecturas COMUNES, antes de abrir la transacción (adentro fijarían la foto de la base antes
			de esperar los candados; ver CuentaCorrienteLock).

			🔴 Las ventas se leen acá por `order_production_id` y adentro se bloquean por CLAVE
			PRIMARIA, no con un `where('order_production_id')->lockForUpdate()`: `sales` no tiene
			índice en `order_production_id` (medido el 5/10/2026), y en InnoDB un SELECT ... FOR
			UPDATE sin índice bloquea cada fila que recorre, o sea la tabla de ventas entera, durante
			toda la transacción (que incluye recalcular cadenas). Vender quedaría esperando.

			TODAS las ventas vivas, no la primera: dos saveSale() en carrera podían dejar dos.
		*/
		$ids_de_ventas = Sale::where('order_production_id', $order_production->id)
								->orderBy('id')
								->pluck('id')
								->all();

		// Los dueños de las cuentas de las que van a salir los movimientos de la orden, resueltos
		// afuera con duenio_de_la_cuenta(), como pide CuentaCorrienteLock.
		$clientes_a_bloquear = [$order_production->client_id];

		$credit_account_ids_de_la_orden = CurrentAcount::where('order_production_id', $order_production->id)
														->whereNotNull('credit_account_id')
														->pluck('credit_account_id')
														->unique()
														->all();

		foreach ($credit_account_ids_de_la_orden as $credit_account_id) {

			$duenio = CuentaCorrienteLock::duenio_de_la_cuenta($credit_account_id);

			if (!is_null($duenio) && $duenio['model_name'] == 'client') {
				$clientes_a_bloquear[] = $duenio['model_id'];
			}
		}

		return DB::transaction(function () use ($order_production, $instance, $ids_de_ventas, $clientes_a_bloquear) {

			// 1. Primera sentencia: las ventas vivas, con candado. SoftDeletes deja afuera las que
			// ya se borraron desde Ventas: esas ya devolvieron su stock y no se tocan de nuevo.
			$ventas = collect();

			if (count($ids_de_ventas) > 0) {
				$ventas = Sale::whereIn('id', $ids_de_ventas)
								->orderBy('id')
								->lockForUpdate()
								->get();
			}

			/*
				2. Todas las cuentas en UNA sola llamada, que las bloquea en orden ascendente: el
				cliente de cada venta, el de la orden y los dueños de los movimientos de la orden.
				Una orden a la que le cambiaron el cliente después de terminarla toca dos cuentas, y
				bloquearlas de a una (como haría eliminar_venta() y después la orden) en distinto
				orden que otro request es un deadlock. Orden venta → cuentas, el del resto del
				sistema; eliminar_venta() después las vuelve a pedir y no espera.
			*/
			foreach ($ventas as $venta) {
				$clientes_a_bloquear[] = $venta->client_id;
			}

			CuentaCorrienteLock::bloquear('client', $clientes_a_bloquear);

			$num_orden = !is_null($order_production->num) ? $order_production->num : $order_production->id;

			/*
				3. 🔴 La guarda va ACÁ ADENTRO, con las ventas ya bloqueadas, y no antes de abrir la
				transacción: así ninguna escritura que tome el candado de la venta puede colarse entre
				la pregunta y la baja. Una venta con comprobante de ARCA no se borra (su factura
				desaparecería del Libro IVA mientras sigue vigente en ARCA), así que la orden tampoco.
				El criterio es el de SaleController::destroy().

				El texto no repite el consejo del motivo como si alcanzara: una factura con nota de
				crédito también frena, así que "hacé una devolución" no destraba la orden (decisión del
				orquestador de la misión, 5/10/2026).
			*/
			foreach ($ventas as $venta) {

				$motivo = DeleteSaleHelper::motivo_por_el_que_no_se_puede_eliminar($venta);

				if (!is_null($motivo)) {

					$num_venta = !is_null($venta->num) ? $venta->num : $venta->id;

					$rechazo = 'No se puede eliminar la orden de producción N° '.$num_orden.': su venta N° '.$num_venta.' tiene un comprobante de ARCA asociado, y mientras lo tenga la orden no se puede borrar. '.$motivo;

					Log::info('eliminar_orden: orden id '.$order_production->id.' rechazada. '.$rechazo);

					return ['rechazo' => $rechazo, 'error_venta_facturada' => true, 'movimientos' => []];
				}
			}

			/*
				4. 🔴 Una venta con cobros tampoco se borra desde acá. La venta de una orden nace sin
				cobro (saveSale), pero editada desde Ventas queda cobrada
				(SaleController::update → attachSelectedPaymentMethods). Darla de baja con
				compensar_caja = false dejaba la plata en la caja sin contrapartida. Desde Ventas el
				usuario elige si compensa; esta pantalla no tiene ese checkbox ni el chequeo de cajas
				abiertas, así que no se compensa desde acá: se manda a borrarla allá.
			*/
			foreach ($ventas as $venta) {

				if ($venta->current_acount_payment_methods()->exists()) {

					$num_venta = !is_null($venta->num) ? $venta->num : $venta->id;

					$rechazo = 'No se puede eliminar la orden de producción N° '.$num_orden.': su venta N° '.$num_venta.' tiene cobros cargados. Eliminá primero la venta desde Ventas (ahí elegís si se compensa la caja) y después la orden.';

					Log::info('eliminar_orden: orden id '.$order_production->id.' rechazada. '.$rechazo);

					return ['rechazo' => $rechazo, 'error_venta_facturada' => false, 'movimientos' => []];
				}
			}

			/*
				5. 🔴 Cada venta por el flujo REAL, no con un `$sale->delete()` crudo (lo que hacía
				SaleHelper::deleteSaleFrom(), que se borró en esta misión): devuelve el stock que dice
				el libro de la venta, saca sus movimientos de cuenta corriente, comisiones y puntos,
				igual que borrarla desde Ventas. Una venta de orden nace sin descontar stock y
				regresar_stock() lo respeta: sin movimientos en el libro, no repone nada.
				`compensar_caja` en false: con el paso 4, acá solo llegan ventas sin cobros.
			*/
			foreach ($ventas as $venta) {
				DeleteSaleHelper::eliminar_venta($venta, $instance, false);
			}

			// 6. Los movimientos de la orden, y la cadena de cada cuenta de la que salieron.
			$movimientos = Self::deleteCurrentAcount($order_production);

			/*
				Por `credit_account_id`. 🔴 No volver a `CurrentAcountHelper::checkSaldos('client',
				$client_id)`: esa firma murió el 10/9/2025 (f5ff6d0d) y tiraba un 500 después de
				haber borrado el movimiento.
			*/
			$credit_account_ids = [];

			foreach ($movimientos as $movimiento) {

				if (is_null($movimiento->credit_account_id)) {

					// La basura que deja hoy el terminar roto (saveCurrentAcount con la firma
					// vieja de getSaldo): no está en ninguna cadena, no hay nada que recalcular.
					Log::info('eliminar_orden: orden id '.$order_production->id.': se borró el movimiento de cuenta corriente '.$movimiento->id.' sin credit_account_id. No hay cadena que recalcular.');

					continue;
				}

				$credit_account_ids[$movimiento->credit_account_id] = $movimiento->credit_account_id;
			}

			foreach ($credit_account_ids as $credit_account_id) {
				CurrentAcountHelper::check_saldos_y_pagos($credit_account_id);
			}

			// 7. La orden.
			$order_production->delete();

			return ['rechazo' => null, 'error_venta_facturada' => false, 'movimientos' => $movimientos];
		});
	}

	/**
	 * Borra TODOS los movimientos de cuenta corriente de la orden y los devuelve.
	 *
	 * Misión orden-produccion-baja-de-venta (5/10/2026):
	 *  - Todos, no el primero: un terminar fallido puede haber dejado más de uno, y el que quedaba
	 *    seguía sumándole la orden al cliente aunque la orden ya no existiera.
	 *  - Antes de cada delete() se liberan los pagos dirigidos y las imputaciones, igual que
	 *    SaleHelper::deleteCurrentAcountFromSale(). `CurrentAcount` no usa SoftDeletes: sin esto un
	 *    pago queda apuntando con `to_pay_id` a una fila que no existe y deja de imputarse, sin
	 *    error visible.
	 *  - NO recalcula ninguna cadena: eso le toca al llamador, que sabe qué cuentas tocó (por el
	 *    `credit_account_id` de lo que se devuelve). checkFinieshed() ignora el retorno.
	 *
	 * @param  \App\Models\OrderProduction  $order_production
	 * @return \App\Models\CurrentAcount[]  Los movimientos borrados; vacío (falsy) si no había.
	 */
	static function deleteCurrentAcount($order_production) {
		$current_acounts = CurrentAcount::where('order_production_id', $order_production->id)
										->orderBy('id')
										->get();

		$borrados = [];

		foreach ($current_acounts as $current_acount) {

			$pagos_dirigidos = CurrentAcount::where('to_pay_id', $current_acount->id)
											->get();

			foreach ($pagos_dirigidos as $pago_dirigido) {
				$pago_dirigido->to_pay_id = null;
				$pago_dirigido->save();
			}

			$current_acount->pagado_por()->detach();
			$current_acount->delete();

			$borrados[] = $current_acount;
		}

		return $borrados;
	}

	static function saveCurrentAcount($order_production) {
		$debe = Self::getTotal($order_production);
        $current_acount = CurrentAcount::create([
            'detalle'     			=> 'Order de Produccion N°'.$order_production->num,
            'debe'        			=> $debe,
            'status'      			=> 'sin_pagar',
            'client_id'   			=> $order_production->client_id,
            'order_production_id'   => $order_production->id,
            'description' 			=> null,
            'created_at'  			=> Carbon::now(),
        ]);
        $current_acount->saldo = Numbers::redondear(CurrentAcountHelper::getSaldo('client', $order_production->client_id, $current_acount) + $debe);
        $current_acount->save();
	}

	static function saveSale($order_production) {
		if (is_null($order_production->sale)) {
	        $ct = new Controller();
	        $sale = Sale::create([
	            'num' 					=> $ct->num('sales'),
	            'user_id' 				=> UserHelper::userId(),
	            'client_id' 			=> $order_production->client_id,
	            'order_production_id' 	=> $order_production->id,
            	'employee_id'           => SaleHelper::getEmployeeId(),
	            'save_current_acount' 	=> 0,
	        ]);
	        Self::attachSaleArticles($sale, $order_production);
        	$ct->sendAddModelNotification('Sale', $sale->id, false);
		}
	}

	static function attachSaleArticles($sale, $order_production) {
		foreach($order_production->articles as $article) {
			$sale->articles()->attach($article->id, [
				'amount'	=> $article->pivot->amount,
				'price'	    => $article->pivot->price,
				'discount'	=> $article->pivot->bonus,
			]);
		}
	}

	static function setArticles($order_productions) {
		foreach ($order_productions as $order_production) {
			foreach ($order_production->articles as $article) {
				foreach (Self::getStatuses() as $status) {
					$article->pivot->{'order_production_status_'.$status->id} = Self::getArticleFinishedAmount($order_production, $article, $status);  
				}
			}
		}
		return $order_productions;
	} 

	static function getArticleFinishedAmount($order_production, $article, $status) {
		$article_finished_res = null;
		foreach ($order_production->articles_finished as $article_finished) {
			if ($article_finished->id == $article->id && $article_finished->pivot->order_production_status_id == $status->id) {
				$article_finished_res = $article_finished;
				break;
			}
		}
		if (!is_null($article_finished_res)) {
			return $article_finished_res->pivot->amount;
		}
		return 0;
	}

	static function attachArticles($order_production, $articles) {
		$cantidades_actuales = OrderProductionRecipe::getCantidadesActuales($order_production);
		$ids = array_map(function($item) {
			return $item['id'];
		}, $articles);

		$order_production->articles()->detach($ids);
		$order_production->articles_finished()->detach($ids);
		
		foreach ($articles as $article) {
			if (isset($article['pivot']['delivered'])) {
				$delivered = $article['pivot']['delivered'];
			} else {
				$delivered = null;
			}
			if ($article['status'] == 'inactive') {
				$art = Article::find($article['id']);
				$art->bar_code = $article['bar_code'];
				$art->provider_code = $article['provider_code'];
				$art->name = $article['name'];
				$art->save();
			}
			$order_production->articles()->attach($article['id'], [
											'amount' 		=> $article['pivot']['amount'],
											'price' 		=> $article['pivot']['price'],
											'bonus' 		=> $article['pivot']['bonus'],
											'location' 		=> $article['pivot']['location'],
											'employee_id'   => isset($article['pivot']['employee_id']) ? $article['pivot']['employee_id'] : null,
											'delivered' 	=> $delivered,
										]);
		  	// $order_production_statuses = Self::getStatuses();
		  	// foreach ($order_production_statuses as $status) {
		  	// 	if (isset($article['pivot']['order_production_status_'.$status->id])) {
			// 	  	$order_production->articles_finished()->attach($article['id'], [
			// 	  									'order_production_status_id' => $status->id,
			// 	  									'amount' 					 => $article['pivot']['order_production_status_'.$status->id]
			// 	  								]);
		  	// 	}
		  	// }
		}
		// $order_production = OrderProduction::find($order_production->id);
		// OrderProductionRecipe::checkRecipes($order_production, $cantidades_actuales);
	}

	static function getTotal($order_production) {
		$total = 0;
		foreach ($order_production->articles as $article) {
			$total += Self::totalArticle($article);
		}
		return $total;
	}

	static function totalArticle($article) {
		$total = $article->pivot->price * $article->pivot->amount;
		if (!is_null($article->pivot->bonus)) {
			$total -= $total * (float)$article->pivot->bonus / 100;
		}
		return $total;
	}

	static function getStatuses() {
	  	return OrderProductionStatus::where('user_id', UserHelper::userId())
									->whereNotNull('position')
									->orderBy('position', 'ASC')
									->get();
	}

	static function getFisrtStatus() {
		$status = OrderProductionStatus::where('user_id', UserHelper::userId())
										->orderBy('position', 'ASC')
										->first();
		return $status->id;
	}

	static function sendCreatedMail($order_production, $send_mail) {
		if ($send_mail && $order_production->budget->client->email != '') {
			$subject = 'ORDEN DE PRODUCCION CREADA';
			$line = 'Empezamos a trabajar en tu pedido, actualmente se encuentra en la primer fase, nos comunicaremos por este medio para informarte sobre cualquier actualización en el estado de producción.';
			$order_production->budget->client->notify(new OrderProductionNotification($order_production, $subject, $line));
		}
	}

	static function sendUpdatedMail($order_production) {
		if (!is_null($order_production->client) && $order_production->client->email != '') {
			$subject = 'ORDEN DE PRODUCCION ACTUALIZADA';
			$line = 'Nos alegra informarte que tu pedido avanzo a la siguiente fase, nos comunicaremos por este medio para informarte sobre cualquier actualización en el estado de producción.';
			$order_production->client->notify(new OrderProductionNotification($order_production, $subject, $line));
		}
	}

}