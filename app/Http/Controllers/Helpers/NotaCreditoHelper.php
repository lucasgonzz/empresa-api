<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;


class NotaCreditoHelper {

	/**
	 * Al borrar una nota de credito de la cuenta corriente, deshace lo que esa NC habia devuelto:
	 * baja `returned_amount` en la venta y saca del stock lo que la NC habia repuesto.
	 *
	 * 🔴 El movimiento va por `crear()` con concepto "Nota de credito" y cantidad NEGATIVA
	 * (auditoria de stock, 5/9/2026). Antes iba por `store()`, que ignora `nota_credito_id`, el
	 * texto de `concepto` y no lleva `sale_id`: quedaba como un "Ingreso manual" negativo suelto,
	 * y en un articulo con `unidades_individuales` la cantidad se multiplicaba. Con el mismo
	 * concepto que la devolucion original, la suma de movimientos "Nota de credito" de la venta
	 * vuelve a coincidir con `returned_amount`, que es lo que lee DeleteSaleHelper para no reponer
	 * dos veces lo devuelto.
	 *
	 * @param  \App\Models\CurrentAcount  $nota_credito
	 * @return void
	 */
	static function resetUnidadesDevueltas($nota_credito) {
		if (!is_null($nota_credito->sale) && count($nota_credito->articles) >= 1) {
			$sale = $nota_credito->sale;
			foreach ($nota_credito->articles as $article_nota_credito) {
				foreach ($sale->articles as $article_sale) {
					if ($article_sale->id == $article_nota_credito->id) {

						$new_returned_amount = $article_sale->pivot->returned_amount - $article_nota_credito->pivot->amount;
						$sale->articles()->updateExistingPivot($article_sale->id, [
							'returned_amount'	=> $new_returned_amount,
						]);

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
							'article_variant_id'            => $article_sale->pivot->article_variant_id,
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
		}
	}

}
