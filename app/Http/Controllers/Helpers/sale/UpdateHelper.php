<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Devoluciones\RegresarStockHelper;
use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
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

	/**
	 * La venta cambió de sucursal al editarla: lo que salió de la sucursal VIEJA vuelve a ella y sale
	 * de la NUEVA, por artículo + variante (misión variantes-mismo-articulo-en-vender, 8/10/2026).
	 *
	 * Hasta hoy el cambio de sucursal no movía nada: el cálculo por diferencia de la edición
	 * (`ArticleHelper::get_amount_for_stock_movement`) solo mira cantidades, así que lo vendido quedaba
	 * descontado de la sucursal vieja mientras la venta decía la nueva, y los ajustes y el borrado
	 * posteriores (que van contra `$sale->address_id`) devolvían a la nueva lo que había salido de la
	 * vieja.
	 *
	 * La implementación mínima: se corre ANTES de re-adjuntar los renglones, con `$sale->address_id`
	 * ya en la nueva. Por cada (artículo, variante) se toma del libro de la venta el neto de los
	 * movimientos que tocaron la sucursal vieja (`from_address_id` o `to_address_id`), sin contar las
	 * notas de crédito (esas entraron al depósito que eligió el operador y se quedan donde están), y
	 * se generan dos "Act Venta": uno que lo devuelve a la vieja y otro que lo descuenta de la nueva.
	 * Después de esto el libro de la venta tiene todo su descuento en la sucursal nueva, y el cálculo
	 * por diferencia, el borrado de renglones y el borrado de la venta operan sobre ella sin cambios.
	 *
	 * Solo para lo que reparte por depósitos (la variante, o el artículo si no tiene variante): el
	 * stock global no es de ninguna sucursal. Una venta que nunca descontó (to_check, sin
	 * `discount_stock`) no tiene nada en el libro y no mueve nada. Si la sucursal vieja ya no existe,
	 * no se muda nada: su stock lo resolvió la baja de la sucursal, y devolverle unidades las mandaría
	 * a otra por la guarda del motor (D12).
	 *
	 * @param  \App\Models\Sale  $sale                 Ya con la sucursal nueva.
	 * @param  int|null          $address_id_anterior
	 * @param  int|null          $address_id_nuevo
	 * @return void
	 */
	static function mudar_stock_de_sucursal($sale, $address_id_anterior, $address_id_nuevo) {

		if (
			SucursalVigenteHelper::es_vacio($address_id_anterior)
			|| SucursalVigenteHelper::es_vacio($address_id_nuevo)
			|| (int)$address_id_anterior == (int)$address_id_nuevo
		) {
			return;
		}

		if (!SucursalVigenteHelper::existe_para_stock($address_id_anterior, $sale->user_id)) {
			Log::info('Venta '.$sale->id.': cambio de sucursal desde la '.$address_id_anterior.', que ya no existe. No se muda stock.');
			return;
		}

		foreach (Self::neto_en_la_sucursal($sale, $address_id_anterior) as $renglon) {

			$article = Article::find($renglon->article_id);

			if (is_null($article) || is_null($article->stock)) {
				continue;
			}

			if (!RegresarStockHelper::reparte_por_depositos($article, $renglon->variant_id_neto)) {
				continue;
			}

			$observaciones = 'Cambio de sucursal de la venta';

			// Lo que salió de la vieja vuelve a ella (el neto es negativo: se invierte).
			$ct = new StockMovementController();
			$ct->crear([
				'model_id'                      => $article->id,
				'amount'                        => -(float)$renglon->neto,
				'sale_id'                       => $sale->id,
				'article_variant_id'            => $renglon->variant_id_neto,
				'to_address_id'                 => $address_id_anterior,
				'concepto_stock_movement_name'  => 'Act Venta',
				'observations'                  => $observaciones,
			], false);

			// Y sale de la nueva, igual que la venta original.
			$ct = new StockMovementController();
			$ct->crear([
				'model_id'                      => $article->id,
				'amount'                        => (float)$renglon->neto,
				'sale_id'                       => $sale->id,
				'article_variant_id'            => $renglon->variant_id_neto,
				'from_address_id'               => $address_id_nuevo,
				'concepto_stock_movement_name'  => 'Act Venta',
				'observations'                  => $observaciones,
			], false);
		}
	}

	/**
	 * Neto de los movimientos de stock de la venta que tocaron una sucursal, por (artículo, variante),
	 * sin las notas de crédito. Solo los que no dan cero.
	 *
	 * El GROUP BY va por posición, como en `DeleteSaleHelper::neto_por_renglon()` y por el mismo motivo
	 * (MariaDB bajo ONLY_FULL_GROUP_BY no compara expresiones entre el SELECT y el GROUP BY).
	 *
	 * @param  \App\Models\Sale  $sale
	 * @param  int               $address_id
	 * @return \Illuminate\Support\Collection  Objetos con article_id, variant_id_neto y neto.
	 */
	static function neto_en_la_sucursal($sale, $address_id) {

		$query = DB::table('stock_movements')
					->select('article_id', DB::raw('COALESCE(NULLIF(article_variant_id, 0), NULL) AS variant_id_neto'), DB::raw('SUM(amount) AS neto'))
					->where('sale_id', $sale->id)
					->whereNotNull('article_id')
					->where(function ($q) use ($address_id) {
						$q->where('from_address_id', $address_id)->orWhere('to_address_id', $address_id);
					});

		$concepto_nc = ConceptoStockMovement::where('name', 'Nota de credito')->first();

		if (!is_null($concepto_nc)) {
			$query->where(function ($q) use ($concepto_nc) {
				$q->whereNull('concepto_stock_movement_id')->orWhere('concepto_stock_movement_id', '<>', $concepto_nc->id);
			});
		}

		return $query->groupByRaw('1, 2')
					->havingRaw('ABS(SUM(amount)) > 0.0001')
					->get();
	}

}
