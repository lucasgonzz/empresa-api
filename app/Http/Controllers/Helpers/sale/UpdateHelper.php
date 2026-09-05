<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Stock\StockMovementController;

class UpdateHelper {

	/**
	 * Devuelve al stock los renglones que la actualizacion saco de la venta.
	 *
	 * 🔴 Un renglon se identifica por articulo Y variante (auditoria de stock, 5/9/2026). Antes
	 * se comparaba solo el id: sacar la Remera L de una venta que tambien tenia la Remera M no
	 * contaba como "se elimino" (el id seguia estando) y esas unidades nunca volvian al stock.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @param  array             $items             Renglones que llegaron en la actualizacion.
	 * @param  mixed             $previus_articles  Renglones que tenia la venta antes (con pivot).
	 * @param  bool              $se_esta_confirmando_por_primera_vez
	 * @return void
	 */
	static function check_articulos_eliminados($sale, $items, $previus_articles, $se_esta_confirmando_por_primera_vez) {

		foreach ($previus_articles as $previus_article) {

			$se_elimino = true;

			foreach ($items as $item) {

				if (
					isset($item['is_article'])
					&& $item['id'] == $previus_article->id
					&& ArticleHelper::misma_variante($previus_article->pivot->article_variant_id, SaleHelper::getArticleVariantId($item))
				) {
					$se_elimino = false;
				}

			}

			if ($se_elimino) {

				Self::save_stock_movement($sale, $previus_article);

			}

		}

	}

	static function save_stock_movement($sale, $article) {

        $ct = new StockMovementController();

        $data = [];
        $data['model_id'] 			= $article->id;
        $data['from_address_id'] 	= null;

        if (count($article->addresses) >= 1) {

        	$data['to_address_id'] 		= $sale->address_id;
        }

        $data['amount'] 			= (float)$article->pivot->amount;
        $data['sale_id'] 			= $sale->id;
        $data['article_variant_id'] = $article->pivot->article_variant_id;
        $data['concepto_stock_movement_name'] 			= 'Se elimino de la venta';

        $ct->crear($data, false);
	}

}
