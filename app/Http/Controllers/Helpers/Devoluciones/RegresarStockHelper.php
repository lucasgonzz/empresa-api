<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Http\Controllers\Stock\StockMovementController;
use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Models\Article;
use App\Models\ArticleVariant;
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

		$sale = $request->sale_id ? Sale::withTrashed()->find($request->sale_id) : null;

		$variant_id = isset($data['article_variant_id']) ? $data['article_variant_id'] : null;

		/*
			🔴 El depósito se decide mirando QUÉ reparte por depósitos (misión
			variantes-mismo-articulo-en-vender, 8/10/2026): con variante, la VARIANTE; sin variante,
			el artículo. Antes se miraba siempre `$article_model->addresses`, que en un artículo con
			variantes es un pivot DERIVADO (suma de las variantes): una variante que no reparte
			recibía un depósito igual.

			Y si el request no trae depósito (0/null) pero lo que se devuelve reparte por depósitos,
			va a la sucursal de la VENTA. Sin depósito el movimiento solo tocaba el stock global de
			la variante, y setArticleStockFromAddresses() lo recalculaba enseguida desde sus
			depósitos: la unidad devuelta se perdía. Si el request trae depósito se respeta: en
			Devoluciones lo elige el operador.
		*/
		if (Self::reparte_por_depositos($article_model, $variant_id)) {

			$address_id = $request->address_id;

			if (SucursalVigenteHelper::es_vacio($address_id) && !is_null($sale)) {
				$address_id = $sale->address_id;
			}

			$data['to_address_id'] = $address_id;
		}
		
		$data['amount'] = (float)$article['unidades_devueltas'];

		// Solo vuelve lo que la venta desconto y no devolvio todavia (ver ValidarDevolucionHelper).
		if ($request->sale_id) {

			if (!is_null($sale)) {

				$data['amount'] = ValidarDevolucionHelper::unidades_a_reponer($sale, $article['id'], $variant_id, $data['amount']);
			}
		}

		if ($data['amount'] <= 0) {
			return;
		}

		$data['concepto_stock_movement_name'] = 'Nota de credito';

		$ct->crear($data);
	}

	/**
	 * Si lo que se devuelve reparte su stock por depósitos: la variante si hay variante (y existe),
	 * el artículo si no.
	 *
	 * @param  \App\Models\Article|null  $article_model
	 * @param  int|null                    $variant_id
	 * @return bool
	 */
	static function reparte_por_depositos($article_model, $variant_id) {

		if (is_null($article_model)) {
			return false;
		}

		if (!is_null($variant_id) && (int)$variant_id != 0) {

			$variante = ArticleVariant::find($variant_id);

			if (!is_null($variante)) {
				return count($variante->addresses) >= 1;
			}
		}

		return count($article_model->addresses) >= 1;
	}
}