<?php

namespace App\Http\Controllers\Helpers\sale;

use Illuminate\Support\Facades\DB;

class SaleTotalesHelper {
	
	static function set_total_cost($sale) {

		$total = null;

		if (!$sale->to_check && !$sale->checked) {
			
			$total = 0;

			foreach ($sale->articles as $article) {

				if (!is_null($article->pivot->cost)) {

					$total += (float)$article->pivot->cost * (float)$article->pivot->amount;		
				}
			}

			foreach ($sale->promocion_vinotecas as $promo) {

				if (!is_null($promo->cost)) {

					$total += $promo->cost * $promo->pivot->amount;		
				}
			}

			$total += Self::costo_de_combos($sale);
		}

		$sale->total_cost = $total;
		$sale->timestamps = false;
		$sale->save();
		return $sale;
	}

	/**
	 * El costo de los combos de la venta: Σ (`combo_sale.cost` x `combo_sale.amount`).
	 *
	 * Mision combos-calculados, Parte A2 (30/9/2026). Hasta entonces `sales.total` incluia el
	 * precio del combo y `sales.total_cost` no incluia su costo, asi que la ganancia de una venta
	 * con combo tomaba el precio ENTERO del combo como ganancia. `combo_sale.cost` es el costo
	 * unitario congelado al vender (lo escribe `ComboCostoDeVentaHelper` via `attachCombos()`).
	 *
	 * 🔴 Se lee de la BASE y no de `$sale->combos`: en la edicion de una venta
	 * (`SaleController::update()`) esa relacion la deja cargada con los combos de ANTES de editar
	 * (`setRelation('combos', $previus_combos)`) y nadie la recarga, mientras que `$sale->articles`
	 * si se recarga. Sumar desde la relacion contaria los combos viejos. Una consulta directa
	 * tambien evita pisar la relacion del modelo que le pasa el llamador.
	 *
	 * `cost` NULL cuenta como 0: es la venta vieja (anterior a esta mision), la que se hizo desde
	 * un presupuesto/pedido sin costo, o un combo manual sin costo cargado. Da lo mismo que hasta
	 * hoy, que es lo unico que se puede afirmar de ellas.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @return float
	 */
	static function costo_de_combos($sale) {

		$total = 0.0;

		$filas = DB::table('combo_sale')
					->where('sale_id', $sale->id)
					->get(['cost', 'amount']);

		foreach ($filas as $fila) {

			if (!is_null($fila->cost)) {

				$total += (float)$fila->cost * (float)$fila->amount;
			}
		}

		return $total;
	}

}