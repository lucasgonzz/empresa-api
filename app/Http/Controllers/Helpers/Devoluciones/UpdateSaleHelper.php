<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateSaleHelper {
	
	/**
	 * Escribe las unidades devueltas (`returned_amount`, ACUMULADO) en los renglones de la venta.
	 *
	 * 🔴 Por artículo + variante y por FILA (misión variantes-mismo-articulo-en-vender, 8/10/2026).
	 * Hasta hoy:
	 *   - un ítem SIN variante hacía `updateExistingPivot($id)`, que escribe TODAS las filas del
	 *     artículo, también las de sus variantes: devolver el renglón sin variante marcaba la Remera M;
	 *   - con varias filas de la misma clave (varios precios) cada ítem pisaba todas las filas, y la
	 *     última ganaba: con 1 devuelta de la fila de 100 × 2 y 3 de la de 50 × 3, las dos quedaban
	 *     en 3 (más devuelto que vendido en la de 100).
	 *
	 * El criterio ahora, por clave (artículo + variante normalizada, ver `ArticleHelper::misma_variante`):
	 *   - la SPA manda un ítem por fila (`format_items` de Devoluciones): cada ítem va a SU fila,
	 *     la primera libre con el mismo precio y la misma cantidad, si no con la misma cantidad (el
	 *     precio se puede editar en Devoluciones), si no con el mismo precio, si no la primera libre
	 *     (en orden de id);
	 *   - si llega UN solo ítem para una clave que tiene varias filas y ninguna tiene su cantidad
	 *     (un renglón agrupado), el total se reparte en orden de id sin pasar la cantidad de cada fila, y
	 *     lo que sobre va a la última.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return void
	 */
	static function update_sale_returned_items($request) {

		$sale = Sale::find($request->sale_id);

		// Ítems de artículo con returned_amount, agrupados por artículo + variante.
		$por_clave = [];

		foreach ($request->items as $item) {
			
			if (isset($item['is_article'])) {

				if (isset($item['returned_amount'])) {

					$variant_id = isset($item['article_variant_id']) ? $item['article_variant_id'] : null;

					$variant_id = ArticleHelper::misma_variante($variant_id, null) ? null : (int)$variant_id;

					$clave = (int)$item['id'].'-'.(is_null($variant_id) ? '' : $variant_id);

					if (!isset($por_clave[$clave])) {
						$por_clave[$clave] = [
							'article_id'	=> (int)$item['id'],
							'variant_id'	=> $variant_id,
							'items'			=> [],
						];
					}

					$por_clave[$clave]['items'][] = $item;
				}

			} else if (isset($item['is_service'])) {

				if (isset($item['returned_amount'])) {

					$sale->services()->updateExistingPivot($item['id'], [
						'returned_amount'	=> $item['returned_amount'],
					]);

				}

			}
		}

		foreach ($por_clave as $grupo) {
			Self::escribir_devueltas_de_la_clave($sale, $grupo['article_id'], $grupo['variant_id'], $grupo['items']);
		}
	}

	/**
	 * Escribe el `returned_amount` de los ítems de una clave (artículo + variante) en sus filas de
	 * `article_sale`. Criterio en el docblock de `update_sale_returned_items()`.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @param  int               $article_id
	 * @param  int|null          $variant_id  null = sin variante (filas con null o 0).
	 * @param  array             $items
	 * @return void
	 */
	static function escribir_devueltas_de_la_clave($sale, $article_id, $variant_id, $items) {

		$query = DB::table('article_sale')
					->select('id', 'amount', 'price', 'returned_amount')
					->where('sale_id', $sale->id)
					->where('article_id', $article_id)
					->orderBy('id', 'ASC');

		if (is_null($variant_id)) {
			$query->where(function ($q) {
				$q->whereNull('article_variant_id')->orWhere('article_variant_id', 0);
			});
		} else {
			$query->where('article_variant_id', $variant_id);
		}

		$filas = $query->get()->all();

		if (count($filas) == 0) {
			Log::info('Devolucion sobre la venta '.$sale->id.': el articulo '.$article_id.' (variante '.(is_null($variant_id) ? '-' : $variant_id).') no tiene renglon en la venta, no se marcan unidades devueltas.');
			return;
		}

		/*
			Un renglón agrupado para varias filas: se reparte. Solo si el ítem NO es una de las filas
			(misma cantidad vendida, ver es_una_de_las_filas()): con un único ítem que sí es una fila
			(el operador sacó las otras de la lista), repartir le pisaría el `returned_amount` a las
			demás.
		*/
		if (count($items) == 1 && count($filas) > 1 && !Self::es_una_de_las_filas($filas, $items[0])) {

			Self::repartir_devueltas($filas, $items[0]['returned_amount']);
			return;
		}

		$usadas = [];

		foreach ($items as $item) {

			$fila = Self::fila_para_el_item($filas, $usadas, $item);

			if (is_null($fila)) {
				Log::info('Devolucion sobre la venta '.$sale->id.': llegaron mas items que renglones para el articulo '.$article_id.'; el sobrante no se marca.');
				continue;
			}

			$usadas[$fila->id] = true;

			DB::table('article_sale')
				->where('id', $fila->id)
				->update(['returned_amount' => $item['returned_amount']]);
		}
	}

	/**
	 * ¿El ítem es una de las filas? Se reconoce por la CANTIDAD vendida de la fila (`amount`), que
	 * Devoluciones no deja editar; el precio (`price_vender`) sí se puede editar ahí, así que no
	 * hace falta que coincida (revisión de la misión variantes-mismo-articulo-en-vender, 8/10/2026).
	 *
	 * @param  array  $filas
	 * @param  array  $item
	 * @return bool
	 */
	static function es_una_de_las_filas($filas, $item) {

		if (!isset($item['amount'])) {
			return false;
		}

		foreach ($filas as $fila) {
			if (abs((float)$fila->amount - (float)$item['amount']) < 0.0001) {
				return true;
			}
		}

		return false;
	}

	/**
	 * La fila libre que le corresponde a un ítem: mismo precio y cantidad; si no, misma cantidad (el
	 * precio se puede editar en Devoluciones, la cantidad vendida no); si no, mismo precio; si no, la
	 * primera libre.
	 *
	 * @param  array  $filas   Filas de la clave, en orden de id.
	 * @param  array  $usadas  id => true de las filas ya asignadas.
	 * @param  array  $item
	 * @return object|null
	 */
	static function fila_para_el_item($filas, $usadas, $item) {

		$precio = isset($item['price_vender']) ? (float)$item['price_vender'] : null;
		$cantidad = isset($item['amount']) ? (float)$item['amount'] : null;

		$libres = array_values(array_filter($filas, function ($fila) use ($usadas) {
			return !isset($usadas[$fila->id]);
		}));

		if (count($libres) == 0) {
			return null;
		}

		if (!is_null($precio) && !is_null($cantidad)) {

			foreach ($libres as $fila) {
				if (abs((float)$fila->price - $precio) < 0.0001 && abs((float)$fila->amount - $cantidad) < 0.0001) {
					return $fila;
				}
			}
		}

		if (!is_null($cantidad)) {

			foreach ($libres as $fila) {
				if (abs((float)$fila->amount - $cantidad) < 0.0001) {
					return $fila;
				}
			}
		}

		if (!is_null($precio)) {

			foreach ($libres as $fila) {
				if (abs((float)$fila->price - $precio) < 0.0001) {
					return $fila;
				}
			}
		}

		return $libres[0];
	}

	/**
	 * Reparte un total de unidades devueltas entre las filas en orden de id, sin pasar la cantidad
	 * de cada una; lo que sobre (más devuelto que vendido, que ValidarDevolucionHelper ya frena) va a
	 * la última, para no perderlo.
	 *
	 * @param  array  $filas
	 * @param  mixed  $total
	 * @return void
	 */
	static function repartir_devueltas($filas, $total) {

		$resto = (float)$total;

		$ultima = count($filas) - 1;

		foreach ($filas as $indice => $fila) {

			$va = $indice == $ultima ? $resto : min($resto, max(0, (float)$fila->amount));

			$resto -= $va;

			DB::table('article_sale')
				->where('id', $fila->id)
				->update(['returned_amount' => $va]);
		}
	}
}
