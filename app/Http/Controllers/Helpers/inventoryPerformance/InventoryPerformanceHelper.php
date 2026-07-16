<?php

namespace App\Http\Controllers\Helpers\inventoryPerformance;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\InventoryPerformance;
use App\Models\PromocionVinoteca;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryPerformanceHelper {

	// Id del usuario owner para el que se genera el reporte.
	// Se recibe explícito porque el helper puede correr dentro de un job (sin sesión HTTP ni Auth).
	public $user_id;

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
	public $articles_stock_minimo;

	// Cantidad de artículos con stock estrictamente menor a 0 (distinto de sin_stock, que
	// ya cuenta los que están exactamente en 0). Casi siempre indica un error de carga o
	// ventas sin ingreso de mercadería registrado.
	public $stock_negativo;

	// Costo estimado de reponer, para todos los artículos bajo el mínimo, la cantidad que
	// falta para llegar al stock mínimo (suma de faltante * costo_unitario_normalizado).
	public $costo_reposicion_stock_minimo;

	public $inventory_performance;

	/**
	 * Inicializa los contadores del reporte.
	 *
	 * @param int|null $user_id Id del owner. Si es null se resuelve desde la sesión
	 *                          con UserHelper::userId() (uso desde el request); si viene
	 *                          un id se usa ese (uso desde el job, sin sesión).
	 */
	function __construct($user_id = null) {

		// Si no se recibe user_id explícito, se cae al comportamiento actual basado en la sesión.
		$this->user_id = is_null($user_id) ? UserHelper::userId() : $user_id;

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
		$this->articles_stock_minimo = [];

		$this->stock_negativo = 0;
		$this->costo_reposicion_stock_minimo = 0;


	}

	function create() {

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

				'stock_negativo'					=> $this->stock_negativo,
				'costo_reposicion_stock_minimo'	=> $this->costo_reposicion_stock_minimo,

				'user_id'							=> $this->user_id,
			]);

			// Timestamp único para todas las filas del pivot de este reporte.
			$now = Carbon::now();

			// Insert masivo sobre la pivot (article_inventory_performance) en lotes de 1000.
			// Se reemplaza el attach() uno por uno (un INSERT por artículo) por un solo INSERT por lote,
			// evitando decenas de miles de queries en cuentas grandes.
			foreach (array_chunk($this->articles_stock_minimo, 1000) as $rows) {

				// A cada fila plana acumulada se le agrega el id del reporte y los timestamps.
				$rows_con_id = [];

				foreach ($rows as $row) {

					$rows_con_id[] = [
						'article_id'				=> $row['article_id'],
						'inventory_performance_id'	=> $this->inventory_performance->id,
						'address_id'				=> $row['address_id'],
						'stock_address'				=> $row['stock_address'],
						'stock_min_address'			=> $row['stock_min_address'],
						'created_at'				=> $now,
						'updated_at'				=> $now,
					];
				}

				DB::table('article_inventory_performance')->insert($rows_con_id);
			}

			// Recién ahora, con el reporte nuevo ya creado y su pivot completa, se borran los reportes
			// anteriores del usuario. Si el proceso fallara antes de este punto, el cliente conserva
			// el reporte viejo en lugar de quedarse sin nada.
			$old_ids = InventoryPerformance::where('user_id', $this->user_id)
							->where('id', '!=', $this->inventory_performance->id)
							->pluck('id')
							->all();

			if (count($old_ids) > 0) {

				// Se borran también las filas de pivot de esos reportes viejos para no dejar basura.
				DB::table('article_inventory_performance')
					->whereIn('inventory_performance_id', $old_ids)
					->delete();

				InventoryPerformance::whereIn('id', $old_ids)->delete();
			}
		}

	}


	function procesar_articulos() {

		$columns = collect(\Illuminate\Support\Facades\Schema::getColumnListing((new Article)->getTable()))
					->reject(function ($column) {
						return in_array($column, ['embedding'], true);
					})
					->values()
					->all();

		Article::select($columns)
			->with('addresses')
			->where('user_id', $this->user_id)
			->where('status', 'active')
			->orderBy('created_at', 'ASC')
			->chunk(2000, function ($articles) {

				foreach ($articles as $article) {

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

						if (
							$article->stock > 0
						) {
							

							// Costo unitario normalizado (presentación / unidades individuales),
							// extraído a un método privado para reusar la misma fórmula en el
							// cálculo del costo de reposición de stock mínimo.
							$cost = $this->costo_unitario_normalizado($article);

							$total_article_cost = $cost * $article->stock;

							$this->valor_inventario_en_costos += $total_article_cost;


							if (!is_null($article->final_price)) {

								$total_article_price = $article->final_price * $article->stock;

								$this->valor_inventario_en_precios += $total_article_price;

							}
						}

						if ($article->stock <= 0) {

							$this->sin_stock++;

						}

						// Stock estrictamente negativo (distinto de sin_stock, que ya cuenta el 0).
						if ($article->stock < 0) {

							$this->stock_negativo++;
						}

						// if (
						// 	count($article->addresses) == 0
						// 	&& !is_null($article->stock_min)
						// 	&& !is_null($article->stock)
						// 	&& $article->stock < $article->stock_min
						// ) {
						if (
							!is_null($article->stock_min)
							&& !is_null($article->stock)
							&& $article->stock <= $article->stock_min
						) {

							$this->stock_minimo++;

							// Se acumula sólo lo necesario para el pivot (array plano), no el modelo Eloquent
							// completo con sus relaciones: en cuentas grandes eso es OOM latente.
							$this->articles_stock_minimo[] = [
								'article_id'		=> $article->id,
								'address_id'		=> null,
								'stock_address'		=> null,
								'stock_min_address'	=> null,
							];

							// Faltante para llegar al stock mínimo (ej: min 1, stock -3 => faltante 4).
							$faltante = $article->stock_min - $article->stock;

							// Sólo se estima costo si falta reponer y el artículo tiene costo cargado
							// (si no tiene costo, no se puede estimar: se ignora, no se asume 0).
							if ($faltante > 0 && !is_null($article->cost)) {

								$costo_unitario = $this->costo_unitario_normalizado($article);

								$this->costo_reposicion_stock_minimo += $faltante * $costo_unitario;
							}

						} else if (
							count($article->addresses) > 0
						) {

							foreach ($article->addresses as $address) {

								if (
									!is_null($address->pivot->stock_min)
									&& $address->pivot->stock_min >= $address->pivot->amount
								) {

									$this->stock_minimo++;

									// Misma acumulación plana, pero apuntando al depósito puntual bajo mínimo.
									$this->articles_stock_minimo[] = [
										'article_id'		=> $article->id,
										'address_id'		=> $address->id,
										'stock_address'		=> $address->pivot->amount,
										'stock_min_address'	=> $address->pivot->stock_min,
									];

									// Faltante calculado con los valores del depósito puntual.
									$faltante_address = $address->pivot->stock_min - $address->pivot->amount;

									if ($faltante_address > 0 && !is_null($article->cost)) {

										$costo_unitario = $this->costo_unitario_normalizado($article);

										$this->costo_reposicion_stock_minimo += $faltante_address * $costo_unitario;
									}
								}
							}
						}

					}
				}
			});

	}

	/**
	 * Normaliza el costo unitario de un artículo aplicando presentación y unidades
	 * individuales, la misma fórmula que ya usaba valor_inventario_en_costos. Se extrae
	 * a un método único para que este cálculo y el de costo_reposicion_stock_minimo no
	 * terminen siendo dos versiones de la misma fórmula que se desincronizan con el tiempo.
	 *
	 * @param  Article $article Artículo del que se calcula el costo unitario.
	 * @return float Costo unitario ya normalizado. Si $article->cost es null se devuelve
	 *                null (el llamador debe chequear is_null($article->cost) antes de usar
	 *                este método en un cálculo).
	 */
	function costo_unitario_normalizado($article) {

		if (is_null($article->cost)) {

			return null;
		}

		$cost = $article->cost;

		// Multiplica por la cantidad de unidades que trae cada presentación (ej: pack x6).
		if (!is_null($article->presentacion)) {
			$cost *= $article->presentacion;
		}

		// Divide por las unidades individuales cuando el costo está cargado por el total
		// de la presentación en lugar de por unidad.
		if (
			!is_null($article->unidades_individuales)
			&& $article->unidades_individuales > 0
		) {
			$cost /= $article->unidades_individuales;
		}

		return $cost;
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

}