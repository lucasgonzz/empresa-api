<?php

namespace App\Http\Controllers\Helpers\inventoryPerformance;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\InventoryPerformance;
use App\Models\PromocionVinoteca;
use Illuminate\Support\Facades\Log;

class InventoryPerformanceHelper {

	public $articles;

	public $cantidad_articulos;
	public $stockeados;
	public $sin_stockear;
	public $porcentaje_stockeado;

	public $valor_inventario_en_costos;
	public $valor_inventario_en_precios;
    
	public $articulos_con_costos;
	public $articulos_sin_costos;
	public $porcentaje_con_costos;
    
	public $sin_stock;
	public $stock_minimo;

	public $inventory_performance;

	function __construct() {

		$this->cantidad_articulos = 0;
		$this->stockeados = 0;
		$this->sin_stockear = 0;
		$this->porcentaje_stockeado = 0;

		$this->valor_inventario_en_costos = 0;
		$this->valor_inventario_en_precios = 0;
	    
		$this->articulos_con_costos = 0;
		$this->articulos_sin_costos = 0;
		$this->porcentaje_con_costos = 0;
	    
		$this->sin_stock = 0;
		$this->stock_minimo = 0;


	}

	function create() {

		$this->set_articles();

		$this->procesar_articulos();

		$this->promocion_vinotecas();

		$this->crear_inventory_performance();

		return $this->inventory_performance;
	}

	function crear_inventory_performance() {

		if ($this->cantidad_articulos > 0) {
			
			$porcentaje_stockeado = $this->stockeados * 100 / $this->cantidad_articulos;

			$porcentaje_con_costos = $this->articulos_con_costos * 100 / $this->cantidad_articulos;

			$this->inventory_performance = InventoryPerformance::create([

				'cantidad_articulos'				=> $this->cantidad_articulos,
				'stockeados'						=> $this->stockeados,
				'sin_stockear'						=> $this->sin_stockear,
				'porcentaje_stockeado'				=> round($porcentaje_stockeado),

				'valor_inventario_en_costos'		=> $this->valor_inventario_en_costos,
				'valor_inventario_en_precios'		=> $this->valor_inventario_en_precios,
		    
				'articulos_con_costos'				=> $this->articulos_con_costos,
				'articulos_sin_costos'				=> $this->articulos_sin_costos,
				'porcentaje_con_costos'				=> round($porcentaje_con_costos),
		    
				'sin_stock'							=> $this->sin_stock,
				'stock_minimo'						=> $this->stock_minimo,

				'user_id'							=> UserHelper::userId(),
			]);
		}

	}


	function procesar_articulos() {

		foreach ($this->articles as $article) {

			$this->cantidad_articulos++;

			if (is_null($article->cost)) {

				$this->articulos_sin_costos++;
				
			} else {

				$this->articulos_con_costos++;

			}

			if (is_null($article->stock)) {

				$this->sin_stockear++;

			} else {

				$this->stockeados++;

				if (!is_null($article->cost)) {

					$cost = $article->cost;

					if (!is_null($article->presentacion)) {
						$cost *= $article->presentacion;
					}

					$total_article_cost = $cost * $article->stock;

					$this->valor_inventario_en_costos += $total_article_cost;

				}

				if (!is_null($article->final_price)) {

					$total_article_price = $article->final_price * $article->stock;

					$this->valor_inventario_en_precios += $total_article_price;

				}

				if ($article->stock <= 0) {

					$this->sin_stock++;
				
				} else if (!is_null($article->stock_min)
							&& $article->stock < $article->stock_min) {

					$this->stock_minimo++;

				}

			}
		}

	}	

	function promocion_vinotecas() {
		$promos = PromocionVinoteca::all();
		foreach ($promos as $promo) {
			if (!is_null($promo->stock)) {
				
				Log::info('Sumando costo '.$promo->cost * $promo->stock.' de la promo '.$promo->name);
				Log::info('Sumando precio '.$promo->final_price * $promo->stock.' de la promo '.$promo->name);
				$this->valor_inventario_en_costos += $promo->cost * $promo->stock;
				$this->valor_inventario_en_precios += $promo->final_price * $promo->stock;
			}
		}
	}	

	function set_articles() {

		$this->articles = Article::where('user_id', UserHelper::userId())
							->where('status', 'active')
							->orderBy('created_at', 'ASC')
							->get();
	}

}