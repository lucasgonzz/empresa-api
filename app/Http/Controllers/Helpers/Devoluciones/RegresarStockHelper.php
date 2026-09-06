<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\Sale;

class RegresarStockHelper {
	
	/**
	 * @param  \Illuminate\Http\Request        $request
	 * @param  \App\Models\CurrentAcount|null  $nota_credito  La NC recien creada, para dejar el
	 *                                                        movimiento atado a ella.
	 * @return void
	 */
	static function regresar_stock($request, $nota_credito = null) {
		//
		foreach ($request->items as $item) {

			if (isset($item['is_article'])) {

				if (
					!is_null($item['stock'])
					&& isset($item['unidades_devueltas'])
					&& $item['unidades_devueltas'] > 0
				) {

					Self::crear_stock_movement($request, $item, $nota_credito);
				}

			}
		}
	}

	static function crear_stock_movement($request, $article, $nota_credito = null) {

		$ct = new StockMovementController();

		$data = [];

		$data['model_id'] = $article['id'];

		if ($request->sale_id) {
			$data['sale_id'] = $request->sale_id;
		}

		if (!is_null($nota_credito)) {
			$data['nota_credito_id'] = $nota_credito->id;
		}

		if (
			isset($article['article_variant_id'])
			&& $article['article_variant_id']
		) {
			$data['article_variant_id'] = $article['article_variant_id'];
		}
		
		$article_model = Article::find($article['id']);
		if (count($article_model->addresses) >= 1) {

			$data['to_address_id'] = $request->address_id;
		}
		
		$data['amount'] = (float)$article['unidades_devueltas'];

		// Solo vuelve lo que la venta desconto y no devolvio todavia (ver ValidarDevolucionHelper).
		if ($request->sale_id) {

			$sale = Sale::withTrashed()->find($request->sale_id);

			if (!is_null($sale)) {

				$variant_id = isset($data['article_variant_id']) ? $data['article_variant_id'] : null;

				$data['amount'] = ValidarDevolucionHelper::unidades_a_reponer($sale, $article['id'], $variant_id, $data['amount']);
			}
		}

		if ($data['amount'] <= 0) {
			return;
		}

		$data['concepto_stock_movement_name'] = 'Nota de credito';

		$ct->crear($data);
	}
}