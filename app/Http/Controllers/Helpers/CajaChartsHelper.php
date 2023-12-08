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

		$sales = Sale::where('user_id', $user_id)
                        ->orderBy('created_at', 'ASC');
        if (!is_null($until_date)) {
            $sales = $sales->whereDate('created_at', '>=', $from_date)
                            ->whereDate('created_at', '<=', $until_date);
        } else {
            $sales = $sales->whereDate('created_at', $from_date);
        }
		$sales = $sales->get();

		foreach ($sales as $sale) {
			$cantidad_ventas++;
			$total_ventas += SaleHelper::getTotalSale($sale);
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
					$clientes_monto_gastado[$sale->client_id]['amount'] += SaleHelper::getTotalSale($sale);
				} else {
					$clientes_monto_gastado[$sale->client_id] = [
						'name'		=> $sale->client->name,
						'amount'	=> SaleHelper::getTotalSale($sale), 
					]; 
				}
			} else if (is_null($sale->current_acount)) {
				if (!is_null($sale->current_acount_payment_method)) {
					if (isset($metodos_de_pago[$sale->current_acount_payment_method_id])) {
						$metodos_de_pago[$sale->current_acount_payment_method_id]['amount'] += SaleHelper::getTotalSale($sale);
					} else {
						$metodos_de_pago[$sale->current_acount_payment_method_id] = [
							'name'		=> $sale->current_acount_payment_method->name,
							'amount'	=> SaleHelper::getTotalSale($sale),
						]; 
					}
				} else {
					if (isset($metodos_de_pago[$p_m_efectivo->id])) {
						$metodos_de_pago[$p_m_efectivo->id]['amount'] += SaleHelper::getTotalSale($sale);
					} else {
						$metodos_de_pago[$p_m_efectivo->id] = [
							'name'		=> $p_m_efectivo->name,
							'amount'	=> SaleHelper::getTotalSale($sale),
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