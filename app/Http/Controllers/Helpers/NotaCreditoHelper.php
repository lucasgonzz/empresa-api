<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\Devoluciones\NotaCreditoProveedorHelper;
use App\Http\Controllers\Helpers\Devoluciones\VarianteEnNotaCreditoEsquemaHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
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
	 * 🔴 Se deshace EXACTAMENTE lo que la NC hizo (misión variantes-mismo-articulo-en-vender,
	 * 8/10/2026). Cada movimiento que la devolucion dejo atado a la NC (`nota_credito_id`) se
	 * invierte con la misma variante y en el MISMO deposito al que entro (su `to_address_id`), no en
	 * la sucursal de la venta: en Devoluciones el deposito lo elige el operador y puede ser otro.
	 * La inversion va con `to_address_id` y cantidad negativa, la misma forma que el reverso de
	 * siempre: el motor suma la cantidad con signo en ese deposito, y el libro de la venta (neto por
	 * articulo + variante) queda en cero para esa NC. Si el movimiento original no tenia deposito
	 * (fue al stock global), el reverso tampoco lo tiene.
	 *
	 * El renglon de la venta cuyo `returned_amount` se baja es el de la variante devuelta: la que
	 * guarda el pivot de la NC (`article_current_acount.article_variant_id`) o, si el pivot no la
	 * tiene, la del movimiento atado.
	 *
	 * ⚠️ NC viejas: sin variante en el pivot y SIN movimientos atados (anteriores a la auditoria de
	 * stock), se sigue el criterio de antes: el renglon con mas unidades devueltas y la sucursal de
	 * la venta. Si la NC si tiene movimientos atados pero un articulo no (la devolucion no repuso
	 * nada de ese articulo: la venta no lo habia descontado), ese articulo no toca el stock.
	 *
	 * @param  \App\Models\CurrentAcount  $nota_credito
	 * @return void
	 */
	static function resetUnidadesDevueltas($nota_credito) {

		/*
			NC a PROVEEDOR (devolución de compra, misión devoluciones-compras-y-rediseno,
			1/10/2026): no tiene venta, así que el camino de abajo no hacía nada y borrar la NC
			dejaba el stock como si la mercadería se hubiera devuelto. Su gemelo vuelve a meter en
			el stock lo que la NC sacó. Una NC de cliente nunca tiene provider_id.
		*/
		if (!is_null($nota_credito->provider_id)) {
			NotaCreditoProveedorHelper::deshacer_stock($nota_credito);
			return;
		}

		if (!is_null($nota_credito->sale) && count($nota_credito->articles) >= 1) {

			$sale = $nota_credito->sale;

			$movimientos = Self::movimientos_atados($nota_credito);

			$nc_con_libro = count($movimientos) > 0;

			// id del movimiento => true, para no invertir dos veces el mismo.
			$usados = [];

			foreach ($nota_credito->articles as $article_nota_credito) {

				$variante_del_pivot = Self::variante_del_pivot($article_nota_credito);

				$movimiento = Self::movimiento_del_renglon($movimientos, $usados, $article_nota_credito, $variante_del_pivot);

				if (!is_null($movimiento)) {
					$usados[$movimiento->id] = true;
				}

				/*
					La variante del renglon a deshacer: la del pivot; si no, la del movimiento atado;
					si no hay ninguna de las dos (NC vieja), cualquier renglon del articulo.
				*/
				if (!is_null($variante_del_pivot)) {
					$renglon = Self::renglon_a_deshacer($sale, $article_nota_credito->id, $variante_del_pivot, true);
				} else if (!is_null($movimiento)) {
					$renglon = Self::renglon_a_deshacer($sale, $article_nota_credito->id, $movimiento->article_variant_id, true);
				} else {
					$renglon = Self::renglon_a_deshacer($sale, $article_nota_credito->id);
				}

				if (is_null($renglon)) {
					continue;
				}

				/*
					Nunca por debajo de cero: una NC guardada sin "actualizar unidades devueltas" no
					sumo nada al renglon, y restarle igual lo dejaba en negativo.
				*/
				$new_returned_amount = max(0, (float)$renglon->returned_amount - (float)$article_nota_credito->pivot->amount);

				DB::table('article_sale')
					->where('id', $renglon->id)
					->update(['returned_amount' => $new_returned_amount]);

				$article = Article::find($article_nota_credito->id);

				// Sin stock no hay nada que deshacer (la NC tampoco lo repuso).
				if (is_null($article) || is_null($article->stock)) {
					continue;
				}

				$observaciones = 'Eliminacion Nota C. N° '.$nota_credito->num_receipt.' - Venta N° '.$sale->num;

				if (!is_null($movimiento)) {

					$data = [
						'model_id'                      => $article->id,
						'amount'                        => -(float)$movimiento->amount,
						'sale_id'                       => $sale->id,
						'nota_credito_id'               => $nota_credito->id,
						'article_variant_id'            => $movimiento->article_variant_id,
						'to_address_id'                 => $movimiento->to_address_id,
						'concepto_stock_movement_name'  => 'Nota de credito',
						'observations'                  => $observaciones,
					];

				} else if ($nc_con_libro) {

					// La NC tiene libro y este articulo no repuso nada: no hay stock que sacar.
					continue;

				} else {

					// NC vieja, sin movimientos atados: el criterio de antes.
					$data = [
						'model_id'                      => $article->id,
						'amount'                        => -(float)$article_nota_credito->pivot->amount,
						'sale_id'                       => $sale->id,
						'nota_credito_id'               => $nota_credito->id,
						'article_variant_id'            => $renglon->article_variant_id,
						'concepto_stock_movement_name'  => 'Nota de credito',
						'observations'                  => $observaciones,
					];

					if (count($article->addresses) >= 1) {
						$data['to_address_id'] = $sale->address_id;
					}
				}

				$ct = new StockMovementController();
				$ct->crear($data, false);
			}
		}
	}

	/**
	 * Los movimientos de stock que la devolucion dejo atados a la NC (concepto "Nota de credito",
	 * cantidad positiva), en orden.
	 *
	 * @param  \App\Models\CurrentAcount  $nota_credito
	 * @return array
	 */
	static function movimientos_atados($nota_credito) {

		$concepto = ConceptoStockMovement::where('name', 'Nota de credito')->first();

		if (is_null($concepto)) {
			return [];
		}

		return DB::table('stock_movements')
					->select('id', 'article_id', 'article_variant_id', 'to_address_id', 'amount')
					->where('nota_credito_id', $nota_credito->id)
					->where('concepto_stock_movement_id', $concepto->id)
					->where('amount', '>', 0)
					->orderBy('id', 'ASC')
					->get()
					->all();
	}

	/**
	 * La variante que guarda el pivot de la NC para este renglon (null si no la guarda o si la
	 * columna todavia no existe).
	 *
	 * @param  \App\Models\Article  $article_nota_credito  Con pivot.
	 * @return int|null
	 */
	static function variante_del_pivot($article_nota_credito) {

		if (!VarianteEnNotaCreditoEsquemaHelper::hay_columna()) {
			return null;
		}

		$variante = isset($article_nota_credito->pivot->article_variant_id) ? $article_nota_credito->pivot->article_variant_id : null;

		return ArticleHelper::misma_variante($variante, null) ? null : (int)$variante;
	}

	/**
	 * El movimiento atado que corresponde a un renglon de la NC, todavia no usado: del mismo
	 * articulo y, si el pivot sabe la variante, de esa variante; primero el de la misma cantidad,
	 * si no el primero. Los renglones del pivot y los movimientos nacen en el mismo orden (los dos
	 * recorren los items de la devolucion), asi que con dos renglones del mismo articulo sin
	 * variante en el pivot (NC anterior a la columna) cada uno toma el suyo.
	 *
	 * @param  array                $movimientos
	 * @param  array                $usados
	 * @param  \App\Models\Article  $article_nota_credito
	 * @param  int|null             $variante_del_pivot
	 * @return object|null
	 */
	static function movimiento_del_renglon($movimientos, $usados, $article_nota_credito, $variante_del_pivot) {

		$candidatos = [];

		foreach ($movimientos as $movimiento) {

			if (isset($usados[$movimiento->id]) || (int)$movimiento->article_id != (int)$article_nota_credito->id) {
				continue;
			}

			if (!is_null($variante_del_pivot) && !ArticleHelper::misma_variante($movimiento->article_variant_id, $variante_del_pivot)) {
				continue;
			}

			$candidatos[] = $movimiento;
		}

		if (count($candidatos) == 0) {
			return null;
		}

		foreach ($candidatos as $movimiento) {
			if (abs((float)$movimiento->amount - (float)$article_nota_credito->pivot->amount) < 0.0001) {
				return $movimiento;
			}
		}

		return $candidatos[0];
	}

	/**
	 * El renglon de la venta (fila de `article_sale`) sobre el que se deshace la devolucion de un
	 * articulo: el que mas unidades devueltas registra, o el primero del articulo si ninguno tiene.
	 *
	 * Con `$con_variante` se busca solo entre las filas de esa variante (null = las filas sin
	 * variante): con el mismo articulo en dos variantes, la fila con mas devuelto puede ser la de la
	 * otra.
	 *
	 * Se lee de la tabla y no de `$sale->articles`, porque esa relacion no expone el `id` del pivot
	 * y sin el id no se puede escribir UN renglon cuando el articulo aparece en varios.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @param  int               $article_id
	 * @param  int|null          $variante
	 * @param  bool              $con_variante  Si se filtra por `$variante`.
	 * @return object|null  Fila con id, article_variant_id y returned_amount.
	 */
	static function renglon_a_deshacer($sale, $article_id, $variante = null, $con_variante = false) {

		$query = DB::table('article_sale')
					->select('id', 'article_variant_id', 'returned_amount')
					->where('sale_id', $sale->id)
					->where('article_id', $article_id);

		if ($con_variante) {

			if (ArticleHelper::misma_variante($variante, null)) {
				$query->where(function ($q) {
					$q->whereNull('article_variant_id')->orWhere('article_variant_id', 0);
				});
			} else {
				$query->where('article_variant_id', (int)$variante);
			}
		}

		return $query->orderBy('returned_amount', 'DESC')
					->orderBy('id', 'ASC')
					->first();
	}

}
