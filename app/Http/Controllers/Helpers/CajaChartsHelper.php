<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Sale;

class CajaChartsHelper {
	
	static function charts($instance = null, $from_date, $until_date, $user_id = null, $slice_articles = true) {
		$cantidad_ventas = 0;
		$total_ventas = 0;
		$categorias = [];
		$sub_categorias = [];
		$articulos = [];
		$clientes_cantidad_ventas = [];
		$clientes_monto_gastado = [];
		$metodos_de_pago = [];
		$p_m_efectivo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->first();

		if (is_null($user_id)) {
			$user_id = $instance->userId();
		}

		/*
		 * ESTE es el grafico que el comercio mira de verdad: `chart/from-date/{from}/{until}` ->
		 * CajaViejaController@charts, que es a lo que pega src/store/chart.js desde
		 * caja-vieja/NavComponent.vue. (SaleChartHelper, el otro grafico de ventas, cuelga de
		 * `sale/charts/{from}/{to}` y hoy no lo llama nadie en la SPA.) Por eso tiene que fechar con
		 * el mismo criterio que el listado: si no, prendida la preferencia el listado se mueve y el
		 * grafico se queda en fecha de carga, que es justo el acoplamiento que esta mision cierra.
		 *
		 * El scope reproduce las dos ramas de aca tal cual (rango con until_date, o un solo dia sin
		 * el), asi que con la preferencia apagada el SQL es el mismo de siempre. La unica diferencia
		 * de forma esta en dos casos que esta funcion no recibe: un $from_date nulo/vacio (el scope
		 * no filtra; el codigo viejo comparaba contra null) y un $until_date cadena vacia (el scope
		 * lo trata como "un solo dia"). Los tres llamadores pasan o un parametro de ruta o un Carbon.
		 */
		$sales = Sale::where('user_id', $user_id)
                        ->orderBy('created_at', 'ASC')
                        ->enRangoDeFechas($from_date, $until_date, $user_id)
                        ->get();

		foreach ($sales as $sale) {
			$cantidad_ventas++;
			$total_ventas += $sale->total;
			// $total_ventas += SaleHelper::getTotalSale($sale);
			if (!is_null($sale->client)) {
				if (isset($clientes_cantidad_ventas[$sale->client_id])) {
					$clientes_cantidad_ventas[$sale->client_id]['amount'] += 1;
				} else {
					$clientes_cantidad_ventas[$sale->client_id] = [
						'name'		=> $sale->client->name,
						'amount'	=> 1, 
					]; 
				}

				if (isset($clientes_monto_gastado[$sale->client_id])) {
					$clientes_monto_gastado[$sale->client_id]['amount'] += $sale->total;
				} else {
					$clientes_monto_gastado[$sale->client_id] = [
						'name'		=> $sale->client->name,
						'amount'	=> $sale->total, 
					]; 
				}
			} else if (is_null($sale->current_acount)) {
				if (!is_null($sale->current_acount_payment_method)) {
					if (isset($metodos_de_pago[$sale->current_acount_payment_method_id])) {
						$metodos_de_pago[$sale->current_acount_payment_method_id]['amount'] += $sale->total;
					} else {
						$metodos_de_pago[$sale->current_acount_payment_method_id] = [
							'name'		=> $sale->current_acount_payment_method->name,
							'amount'	=> $sale->total,
						]; 
					}
				} else {
					if (isset($metodos_de_pago[$p_m_efectivo->id])) {
						$metodos_de_pago[$p_m_efectivo->id]['amount'] += $sale->total;
					} else {
						$metodos_de_pago[$p_m_efectivo->id] = [
							'name'		=> $p_m_efectivo->name,
							'amount'	=> $sale->total,
						]; 
					}
				}
			}
			foreach ($sale->articles as $article) {
				if (isset($articulos[$article->id])) {
					$articulos[$article->id]['amount'] += (float)$article->pivot->amount;
					$articulos[$article->id]['rentabilidad'] += Self::get_rentabilidad_articulo($article, $sale);
				} else {
					$articulos[$article->id] = [
						'num'			=> $article->num,
						'bar_code'  	=> $article->bar_code,
						'provider_code' => $article->provider_code,
						'provider' 		=> $article->provider,
						'name'			=> $article->name,
						'amount'		=> (float)$article->pivot->amount, 
						'rentabilidad'	=> Self::get_rentabilidad_articulo($article, $sale), 
					]; 
				}

				if (!is_null($article->category)) {
					if (isset($categorias[$article->category_id])) {
						$categorias[$article->category_id]['amount'] += (float)$article->pivot->amount;
					} else {
						$categorias[$article->category_id] = [
							'name'		=> $article->category->name,
							'amount'	=> (float)$article->pivot->amount, 
						]; 
					}
				}

				if (!is_null($article->sub_category)) {
					if (isset($sub_categorias[$article->sub_category_id])) {
						$sub_categorias[$article->sub_category_id]['amount'] += (float)$article->pivot->amount;
					} else {
						$sub_categorias[$article->sub_category_id] = [
							'name'		=> $article->sub_category->name.' ('.$article->sub_category->category->name.')',
							'amount'	=> (float)$article->pivot->amount, 
						]; 
					}
				} 
			}
		}


		$metodos_de_pago = Self::metodos_de_pago($user_id, $from_date, $until_date, $metodos_de_pago, $p_m_efectivo);

		usort($articulos, function($a, $b) { 
			return $b['amount'] - $a['amount']; 
		});

		if ($slice_articles) {
			$articulos = array_slice($articulos, 0, 30);
		}

		usort($categorias, function($a, $b) { 
			return $b['amount'] - $a['amount']; 
		});

		usort($sub_categorias, function($a, $b) { 
			return $b['amount'] - $a['amount']; 
		});

		usort($clientes_cantidad_ventas, function($a, $b) { 
			return $b['amount'] - $a['amount']; 
		});

		usort($clientes_monto_gastado, function($a, $b) { 
			return $b['amount'] - $a['amount']; 
		});

		usort($metodos_de_pago, function($a, $b) { 
			return $b['amount'] - $a['amount']; 
		});

		return [
			'cantidad_ventas' 				=> $cantidad_ventas,
			'total_ventas' 					=> $total_ventas,
			'article' 						=> $articulos,
			'category' 						=> $categorias,
			'sub_category' 					=> $sub_categorias,
			'clientes_cantidad_ventas' 		=> $clientes_cantidad_ventas,
			'clientes_monto_gastado' 		=> $clientes_monto_gastado,
			'metodos_de_pago'				=> $metodos_de_pago,
		];

	}

	static function get_rentabilidad_articulo($article, $sale) {
		$rentabilidad = 0;
		$cost = Self::get_article_costo_realt($article);
		if (!is_null($cost)) {
			$price_vendido = $article->pivot->price;
			foreach ($sale->discounts as $discount) {
				$price_vendido -= $price_vendido * $discount->pivot->percentage / 100;
			}
			foreach ($sale->surchages as $surchage) {
				$price_vendido += $price_vendido * $surchage->pivot->percentage / 100;
			}
			$rentabilidad = $price_vendido - $cost; 
			$rentabilidad *= (float)$article->pivot->amount;
		}
		return $rentabilidad;
	}

	static function get_article_costo_realt($article) {
		$cost = null;
		if (!is_null($article->pivot->cost)) {
			$cost = $article->pivot->cost;
			if (!is_null($article->iva) 
				&& $article->iva->percentage != '0'
				&& $article->iva->percentage != 'Exento'
				&& $article->iva->percentage != 'No Gravado') {
				$cost += $cost * (float)$article->iva->percentage / 100;
			}
		}
		return $cost;
	}

	static function metodos_de_pago($user_id, $from_date, $until_date, $metodos_de_pago, $p_m_efectivo) {
		$pagos = CurrentAcount::where('user_id', $user_id)
                        ->orderBy('created_at', 'ASC')
            			->whereDate('created_at', '>=', $from_date)
                        ->whereDate('created_at', '<=', $until_date)
                        ->whereNotNull('haber')
                        ->whereNotNull('client_id')
                        ->get();

        foreach ($pagos as $pago) {
        	if (count($pago->current_acount_payment_methods) >= 1) {
	        	foreach ($pago->current_acount_payment_methods as $payment_method) {
					if (isset($metodos_de_pago[$payment_method->id])) {
						$metodos_de_pago[$payment_method->id]['amount'] += $payment_method->pivot->amount;
					} else {
						$metodos_de_pago[$payment_method->id] = [
							'name'		=> $payment_method->name,
							'amount'	=> $payment_method->pivot->amount,
						]; 
					}
	        	}
        	} else {
				if (isset($metodos_de_pago[$p_m_efectivo->id])) {
					$metodos_de_pago[$p_m_efectivo->id]['amount'] += $pago->haber;
				} else {
					$metodos_de_pago[$p_m_efectivo->id] = [
						'name'		=> $p_m_efectivo->name,
						'amount'	=> $pago->haber,
					]; 
				}
        	}
        }
        foreach ($metodos_de_pago as $metodo_de_pago) {
        	$metodo_de_pago['amount'] = Numbers::price($metodo_de_pago['amount']);
        }
        return $metodos_de_pago;
	}

}