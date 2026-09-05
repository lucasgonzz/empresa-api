<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\ConceptoStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateHelper {

	/**
	 * Devuelve al stock los renglones que la actualizacion saco de la venta.
	 *
	 * 🔴 Un renglon se identifica por articulo Y variante (auditoria de stock, 5/9/2026). Antes
	 * se comparaba solo el id: sacar la Remera L de una venta que tambien tenia la Remera M no
	 * contaba como "se elimino" (el id seguia estando) y esas unidades nunca volvian al stock.
	 *
	 * 🔴 Y lo que se devuelve es lo que la venta DESCONTO segun su libro de movimientos, no la
	 * cantidad del renglon. Son la misma cosa en la venta comun, y distintas justo en los casos que
	 * inflaban el stock: un renglon que ya tenia una parte devuelta por nota de credito (volvia
	 * entero, y la parte devuelta se sumaba dos veces), un articulo que no llevaba stock cuando se
	 * vendio (nunca se desconto, y "volvia" igual) y una venta con `discount_stock` apagado.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @param  array             $items             Renglones que llegaron en la actualizacion.
	 * @param  mixed             $previus_articles  Renglones que tenia la venta antes (con pivot).
	 * @param  bool              $se_esta_confirmando_por_primera_vez
	 * @return void
	 */
	static function check_articulos_eliminados($sale, $items, $previus_articles, $se_esta_confirmando_por_primera_vez) {

		if (is_null($previus_articles)) {
			return;
		}

		// Varios precios deja mas de un renglon del mismo articulo: el libro se devuelve una sola vez.
		$ya_devueltos = [];

		foreach ($previus_articles as $previus_article) {

			if (Self::sigue_en_la_venta($items, $previus_article)) {
				continue;
			}

			$clave = $previus_article->id.'-'.(int)$previus_article->pivot->article_variant_id;

			if (isset($ya_devueltos[$clave])) {
				continue;
			}

			$ya_devueltos[$clave] = true;

			Self::save_stock_movement($sale, $previus_article);
		}

	}

	/**
	 * Devuelve al stock los articulos de los combos que la actualizacion saco de la venta.
	 *
	 * 🔴 No existia (auditoria de stock, 5/9/2026): sacar un combo de una venta ya confirmada
	 * dejaba descontados para siempre los articulos que lo componian. La cantidad sale del combo
	 * y de su receta (lo mismo que ComboHelper desconto al venderlo), y se limita a los articulos
	 * que llevan stock.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @param  array             $items           Renglones que llegaron en la actualizacion.
	 * @param  mixed             $previus_combos  Combos que tenia la venta antes (con pivot amount).
	 * @return void
	 */
	static function check_combos_eliminados($sale, $items, $previus_combos) {

		if (is_null($previus_combos)) {
			return;
		}

		foreach ($previus_combos as $previus_combo) {

			$sigue = false;

			foreach ($items as $item) {

				if (isset($item['is_combo']) && $item['id'] == $previus_combo->id) {
					$sigue = true;
				}
			}

			if ($sigue) {
				continue;
			}

			foreach ($previus_combo->articles as $article) {

				if (is_null($article->stock)) {
					continue;
				}

				$amount = (float)$previus_combo->pivot->amount * (float)$article->pivot->amount;

				if ($amount <= 0) {
					continue;
				}

				$data = [
					'model_id'                      => $article->id,
					'amount'                        => $amount,
					'sale_id'                       => $sale->id,
					'concepto_stock_movement_name'  => 'Se elimino de la venta',
					'observations'                  => (float)$previus_combo->pivot->amount.' combo '.$previus_combo->name,
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
	 * @param  array               $items
	 * @param  \App\Models\Article  $previus_article  Con pivot.
	 * @return bool
	 */
	static function sigue_en_la_venta($items, $previus_article) {

		foreach ($items as $item) {

			if (
				isset($item['is_article'])
				&& $item['id'] == $previus_article->id
				&& ArticleHelper::misma_variante($previus_article->pivot->article_variant_id, SaleHelper::getArticleVariantId($item))
			) {
				return true;
			}
		}

		return false;
	}

	static function save_stock_movement($sale, $article) {

		$neto = Self::neto_en_el_libro($sale, $article->id, $article->pivot->article_variant_id);

		// Sin descuento pendiente en el libro no hay nada que devolver.
		if ($neto > -0.0001) {
			Log::info('Se saco el articulo '.$article->id.' de la venta '.$sale->id.' pero su libro no tiene stock descontado (neto '.$neto.'): no se devuelve nada.');
			return;
		}

        $ct = new StockMovementController();

        $data = [];
        $data['model_id'] 			= $article->id;
        $data['from_address_id'] 	= null;

        if (count($article->addresses) >= 1) {

        	$data['to_address_id'] 		= $sale->address_id;
        }

        $data['amount'] 			= -$neto;
        $data['sale_id'] 			= $sale->id;
        $data['article_variant_id'] = $article->pivot->article_variant_id;
        $data['concepto_stock_movement_name'] 			= 'Se elimino de la venta';

        $ct->crear($data, false);
	}

	/**
	 * Neto de los movimientos de stock de la venta para un (articulo, variante): negativo mientras
	 * la venta tenga stock descontado sin devolver.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @param  int               $article_id
	 * @param  int|null          $article_variant_id
	 * @return float
	 */
	static function neto_en_el_libro($sale, $article_id, $article_variant_id) {

		$query = DB::table('stock_movements')
					->where('sale_id', $sale->id)
					->where('article_id', $article_id);

		if (is_null($article_variant_id) || (int)$article_variant_id == 0) {
			$query->where(function ($q) {
				$q->whereNull('article_variant_id')->orWhere('article_variant_id', 0);
			});
		} else {
			$query->where('article_variant_id', $article_variant_id);
		}

		return (float)$query->sum('amount');
	}

}
