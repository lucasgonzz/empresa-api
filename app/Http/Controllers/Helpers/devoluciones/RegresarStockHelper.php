<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\Sale;

class RegresarStockHelper {
	
	static function regresar_stock($request) {

		$sale = Sale::find($request->sale_id);
		
		foreach ($request->items as $item) {
			
			if (isset($item['is_article'])) {

				if (isset($item['unidades_devueltas'])) {

					Self::crear_stock_movement($request, $item);
				}

			}
		}
	}

	static function crear_stock_movement($request, $article) {

		$ct = new StockMovementController();

		$data = [];

		$data['model_id'] = $article['id'];
		
		$article_model = Article::find($article['id']);
		if (count($article_model->addresses) >= 1) {

			$data['to_address_id'] = $request->address_id;
		}
		
		$data['amount'] = $article['unidades_devueltas'];
		$data['concepto_stock_movement_name'] = 'Nota de credito';

		$ct->crear($data);
	}
}