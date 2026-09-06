<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use Illuminate\Support\Facades\DB;


class NotaCreditoHelper {

	/**
	 * Al borrar una nota de credito de la cuenta corriente, deshace lo que esa NC habia devuelto:
	 * baja `returned_amount` en la venta y saca del stock lo que la NC habia repuesto.
	 *
	 * 🔴 El movimiento va por `crear()` con concepto "Nota de credito" y cantidad NEGATIVA
	 * (auditoria de stock, 5/9/2026). Antes iba por `store()`, que ignora `nota_credito_id`, el
	 * texto de `concepto` y no lleva `sale_id`: quedaba como un "Ingreso manual" negativo suelto,
	 * y en un articulo con `unidades_individuales` la cantidad se multiplicaba. Con el mismo
	 * concepto que la devolucion original, el neto de movimientos "Nota de credito" de la venta
	 * vuelve a reflejar lo realmente devuelto, que es lo que leen DeleteSaleHelper (para no reponer
	 * dos veces) y ValidarDevolucionHelper (para topar la proxima devolucion).
	 *
	 * ⚠️ El pivot de la NC (`article_current_acount`) no guarda la variante ni distingue renglones:
	 * si la venta tiene el mismo articulo en dos renglones (dos variantes, varios precios), se
	 * deshace UNA sola vez, sobre el primer renglon que tenga unidades devueltas. Antes se
	 * deshacia una vez por renglon coincidente, o sea de mas.
	 *
	 * @param  \App\Models\CurrentAcount  $nota_credito
	 * @return void
	 */
	static function resetUnidadesDevueltas($nota_credito) {
		if (!is_null($nota_credito->sale) && count($nota_credito->articles) >= 1) {
			$sale = $nota_credito->sale;
			foreach ($nota_credito->articles as $article_nota_credito) {

				$renglon = Self::renglon_a_deshacer($sale, $article_nota_credito->id);

				if (is_null($renglon)) {
					continue;
				}

				$new_returned_amount = (float)$renglon->returned_amount - (float)$article_nota_credito->pivot->amount;

				DB::table('article_sale')
					->where('id', $renglon->id)
					->update(['returned_amount' => $new_returned_amount]);

				$article = Article::find($article_nota_credito->id);

				// Sin stock no hay nada que deshacer (la NC tampoco lo repuso).
				if (is_null($article) || is_null($article->stock)) {
					continue;
				}

				$data = [
					'model_id'                      => $article->id,
					'amount'                        => -(float)$article_nota_credito->pivot->amount,
					'sale_id'                       => $sale->id,
					'nota_credito_id'               => $nota_credito->id,
					'article_variant_id'            => $renglon->article_variant_id,
					'concepto_stock_movement_name'  => 'Nota de credito',
					'observations'                  => 'Eliminacion Nota C. N° '.$nota_credito->num_receipt.' - Venta N° '.$sale->num,
				];

				if (count($article->addresses) >= 1) {
					$data['to_address_id'] = $sale->address_id;
				}

				$ct = new StockMovementController();
				$ct->crear($data, false);
			}
		}
	}

	/**
	 * El renglon de la venta (fila de `article_sale`) sobre el que se deshace la devolucion de un
	 * articulo: el que mas unidades devueltas registra, o el primero del articulo si ninguno tiene.
	 *
	 * Se lee de la tabla y no de `$sale->articles`, porque esa relacion no expone el `id` del pivot
	 * y sin el id no se puede escribir UN renglon cuando el articulo aparece en varios.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @param  int               $article_id
	 * @return object|null  Fila con id, article_variant_id y returned_amount.
	 */
	static function renglon_a_deshacer($sale, $article_id) {

		return DB::table('article_sale')
					->select('id', 'article_variant_id', 'returned_amount')
					->where('sale_id', $sale->id)
					->where('article_id', $article_id)
					->orderBy('returned_amount', 'DESC')
					->orderBy('id', 'ASC')
					->first();
	}

}
