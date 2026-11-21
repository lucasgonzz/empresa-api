<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\Image;
use App\Models\InventoryLinkage;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\SubCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use Illuminate\Database\Eloquent\Builder;

class InventoryLinkageHelper extends Controller {

	public $price_types;
	public $user_id;

	function __construct($inventory_linkage = null, $user_id = null) {
        set_time_limit(999999);
		$this->user_id = $user_id;
		if (is_null($user_id)) {
			$this->user_id = $this->userId();
		} 
		if (!is_null($inventory_linkage)) {
			$this->inventory_linkage = $inventory_linkage;
			$this->client = $inventory_linkage->client;
			// D2-M (R3.1): con el cliente en la papelera `client` es null y setProviderForClient()
			// lo desreferencia. Sin cliente no hay proveedor que buscar: setClientArticles() lo salta.
			if (!is_null($this->client)) {
				$this->setProviderForClient();
			}
		}
		$this->setPriceTypes();
	}

	function setPriceTypes() {
		$this->price_types = PriceType::where('user_id', $this->user_id)
											->orderBy('position', 'ASC')
											->get();
	}

	function setProviderForClient() {
		$this->provider_for_client = Provider::where('user_id', $this->inventory_linkage->client->comercio_city_user_id)
												->where('comercio_city_user_id', $this->user_id)
												->first();
	}

	function setClientCategories() {
		$categories = Category::where('user_id', $this->userId())
								->get();

		$index = count($categories);
		foreach ($categories as $category) {
			Category::create([
				'num'					=> $this->num('categories', $this->inventory_linkage->client->comercio_city_user_id),
				'name'					=> $category->name,
				'image_url'				=> $category->image_url,
				'user_id'				=> $this->inventory_linkage->client->comercio_city_user_id,
				'provider_category_id'	=> $category->id,
				'created_at'			=> Carbon::now()->subSeconds($index),
			]);
			$index--;
		}
	}

	function setClientSubCategories() {
		$sub_categories = SubCategory::where('user_id', $this->userId())
								->get();

		$index = count($sub_categories);
		foreach ($sub_categories as $sub_category) {
			$client_category = Category::where('user_id', $this->client->comercio_city_user_id)
										->where('provider_category_id', $sub_category->category_id)
										->first();
			SubCategory::create([
				'num'						=> $this->num('sub_categories', $this->inventory_linkage->client->comercio_city_user_id),
				'name'						=> $sub_category->name,
				'category_id'				=> $client_category->id,
				'user_id'					=> $this->inventory_linkage->client->comercio_city_user_id,
				'provider_sub_category_id'	=> $sub_category->id,
				'created_at'				=> Carbon::now()->subSeconds($index),
			]);
			$index--;
		}
	}
	
	function setClientArticles() {
		// D2-M (R3.1): una vinculación sin contexto (cliente borrado o sin usuario, o sin Provider hacia
		// el proveedor) no crea nada: antes dejaba categorías con user_id NULL o copias de un usuario inexistente.
		if (!isset($this->inventory_linkage) || !$this->contexto_de_vinculacion_valido($this->inventory_linkage)) {
			Log::warning('InventoryLinkageHelper: vinculacion sin contexto, no se crean copias (linkage '.(isset($this->inventory_linkage) ? $this->inventory_linkage->id : 'null').')');
			return;
		}

		$articles = Article::where('user_id', $this->userId())
							// ->where('id', '>', 102275)
							->orderBy('id', 'ASC')
							->get();

		$index = count($articles);
		foreach ($articles as $article) {
			$created_at = Carbon::now()->subSeconds($index);
			$this->createClientArticle($article, $created_at);
			$index--;
			// $client_article = Article::where('provider_article_id', $article->id)
			// 							->first();
			// if (is_null($client_article)) {
			// 	$created_at = Carbon::now()->subSeconds($index);
			// 	$this->createClientArticle($article, $created_at);
			// 	$index--;
			// }
		}
	}

	/**
	 * Elimina las copias del artículo proveedor en todos los clientes con inventory linkage.
	 *
	 * @param Article $article Artículo del usuario proveedor (dueño) que se está eliminando.
	 * @return void
	 */
	function delete_client_articles_for_provider_article($article) {
		// Vinculaciones del usuario proveedor dueño del artículo.
		$inventory_linkages = InventoryLinkage::where('user_id', $this->user_id)
												->get();

		if (count($inventory_linkages) < 1) {
			return;
		}

		foreach ($inventory_linkages as $inventory_linkage) {
			// Usuario ComercioCity del cliente destino del linkage.
			$client_user_id = $inventory_linkage->client->comercio_city_user_id;

			$client_article = Article::where('user_id', $client_user_id)
												->where('provider_article_id', $article->id)
												->first();

			if (!is_null($client_article)) {
				Log::info('InventoryLinkageHelper: eliminando articulo cliente '.$client_article->name.' por delete del proveedor');
				$client_article->delete();
			}
		}
	}

	/**
	 * Elimina en batch las copias cliente cuyo provider_article_id está en la lista de eliminados del proveedor.
	 *
	 * @param int $client_user_id ID del usuario cliente (comercio_city_user_id).
	 * @param array $deleted_provider_article_ids IDs de artículos soft-deleted del proveedor.
	 * @return int Cantidad de artículos cliente eliminados.
	 */
	function delete_client_articles_for_deleted_provider_article_ids($client_user_id, array $deleted_provider_article_ids) {
		$deleted_count = 0;

		if (empty($deleted_provider_article_ids)) {
			return $deleted_count;
		}

		foreach (array_chunk($deleted_provider_article_ids, 1000) as $deleted_provider_article_ids_chunk) {
			$client_articles_to_delete = Article::where('user_id', $client_user_id)
				->whereIn('provider_article_id', $deleted_provider_article_ids_chunk)
				->get();

			foreach ($client_articles_to_delete as $client_article) {
				Log::info('InventoryLinkageHelper: eliminando articulo cliente '.$client_article->name.' (reconciliacion batch)');
				$client_article->delete();
				$deleted_count++;
			}
		}

		return $deleted_count;
	}

	/**
	 * Crea el artículo en el cliente del linkage si aún no existe (provider_article_id + provider_id).
	 *
	 * @param InventoryLinkage $inventory_linkage Vinculación activa proveedor → cliente.
	 * @param Article $article Artículo fuente del usuario proveedor.
	 * @return Article|null Artículo cliente creado o existente; null si no hay proveedor configurado en el cliente.
	 */
	function ensure_client_article_for_linkage($inventory_linkage, $article) {
		// D2-M (R3.1): la guarda mira el cliente, su usuario y el Provider antes de cualquier efecto.
		if (!$this->contexto_de_vinculacion_valido($inventory_linkage)) {
			Log::warning('InventoryLinkageHelper: no hay contexto valido (cliente, usuario o provider_for_client) para linkage '.$inventory_linkage->id);
			return null;
		}

		$client_article = Article::where('user_id', $this->client->comercio_city_user_id)
												->where('provider_article_id', $article->id)
												->first();

		if (is_null($client_article)) {
			return $this->createClientArticle($article);
		}

		return $client_article;
	}

	/**
	 * Sincroniza solo precio y código de barras del artículo cliente respecto al proveedor.
	 * No modifica categoría ni otros campos que el cliente puede editar en su sistema.
	 *
	 * @param InventoryLinkage $inventory_linkage Vinculación activa proveedor → cliente.
	 * @param Article $article Artículo fuente del proveedor.
	 * @param Article $client_article Copia del artículo en el usuario cliente.
	 * @return bool True si se persistieron cambios.
	 */
	function sync_client_article_price_and_bar_code($inventory_linkage, $article, $client_article) {
		// D2-M (R3.1): sin contexto no se escribe nada.
		if (!$this->contexto_de_vinculacion_valido($inventory_linkage)) {
			Log::warning('InventoryLinkageHelper: sync salteado, vinculacion sin contexto (linkage '.$inventory_linkage->id.')');
			return false;
		}

		// D2-M (R3, R2-M1): el modelo del caché del cron sólo sirve para DESCARTAR. Si con esos valores
		// no hay nada que escribir se devuelve false sin tocar la base (no escribir nunca deja un estado malo).
		if (!$this->copia_necesita_escritura($client_article, $article)) {
			return false;
		}

		// Hay algo que escribir: se relee la copia bajo candado y se vuelve a decidir con la fila bloqueada.
		$resultado = $this->escribir_copia_bloqueada($client_article->id, function ($copia) use ($article) {
			return $this->aplicar_sincronizacion($copia, $article);
		});

		if (!is_null($resultado['copia'])) {
			// El objeto del llamador (caché del cron, log de CheckInventoryLinkages) queda con lo guardado.
			$client_article->setRawAttributes($resultado['copia']->getAttributes(), true);
		}

		return $resultado['escrito'];
	}

	/**
	 * Costo que le corresponde a la copia según su marca: con marca 1 el neto de `costo_para_la_copia`;
	 * con marca 0 el bruto de hoy.
	 *
	 * @param Article $client_article Copia (con la marca leída de la base).
	 * @param float $bruto Costo bruto que sale de la fuente (`final_price` o `getClientArticlePrice`).
	 * @return float
	 */
	function costo_esperado_de_la_copia($client_article, $bruto) {
		if (!empty($client_article->costo_neto_de_vinculacion)) {
			return $this->costo_para_la_copia($client_article, $bruto);
		}
		return $bruto;
	}

	/**
	 * ¿Hay algo para escribir en la copia respecto a la fuente? Misma regla que el cron de hoy, pero
	 * con el costo esperado según la marca (con marca 1, tolerancia de 0,000001 para no reescribir
	 * cada hora una copia neta).
	 *
	 * @param Article $client_article Copia (del caché del cron o releída bajo candado).
	 * @param Article $article Fuente.
	 * @return bool
	 */
	private function copia_necesita_escritura($client_article, $article) {
		if ($client_article->bar_code != $article->bar_code) {
			return true;
		}

		$esperado = $this->costo_esperado_de_la_copia($client_article, $article->final_price);

		if (!empty($client_article->costo_neto_de_vinculacion)) {
			return abs((float) $client_article->cost - (float) $esperado) > 0.000001;
		}

		return $client_article->cost != $esperado;
	}

	/**
	 * Cuerpo del cron bajo candado: decide con la fila releída y escribe.
	 *
	 * @param Article $copia Copia releída con lockForUpdate.
	 * @param Article $article Fuente que pasó el llamador (no se relee).
	 * @return bool True si se persistieron cambios.
	 */
	private function aplicar_sincronizacion($copia, $article) {
		if (!$this->copia_necesita_escritura($copia, $article)) {
			return false;
		}

		// Código de barras: debe reflejar el del proveedor.
		if ($copia->bar_code != $article->bar_code) {
			$copia->bar_code = $article->bar_code;
		}

		// Precio: cost = final_price del proveedor (con marca 1, el neto que le corresponde a la copia).
		$esperado = $this->costo_esperado_de_la_copia($copia, $article->final_price);
		$cambia_costo = !empty($copia->costo_neto_de_vinculacion)
						? abs((float) $copia->cost - (float) $esperado) > 0.000001
						: $copia->cost != $esperado;

		if ($cambia_costo) {
			$copia->cost = $esperado;
			ArticleHelper::setFinalPrice(
				$copia,
				$this->client->comercio_city_user_id,
				$this->client->comercio_city_user,
				$this->client->comercio_city_user->id
			);
		}

		$copia->save();

		return true;
	}

	/**
	 * ¿El costo de esta copia lo manda la vinculación y una compra de ese mismo proveedor NO debe
	 * pisarlo? Sí cuando la copia tiene la marca de costo neto (`costo_neto_de_vinculacion` = 1) y
	 * la compra es al proveedor que representa al dueño de la fuente: el renglón de la compra espejo
	 * (`SaleProviderOrderHelper`) trae el precio BRUTO de Fénix, y escribirlo en `articles.cost` de
	 * una copia neta la dejaba en 4828 en vez de 3990,082645 y el final en 6426,07 en vez de 5310,80.
	 *
	 * Con marca 0 (interruptor apagado, copia sin convertir) devuelve false SIN consultar nada: la
	 * compra se procesa exactamente como hoy. Tampoco toca las compras a otros proveedores.
	 *
	 * Lo usa NewProviderOrderHelper::update_cost().
	 *
	 * @param Article $copia Artículo de la compra.
	 * @param \App\Models\ProviderOrder $provider_order Compra que se procesa.
	 * @return bool
	 */
	public static function el_costo_lo_manda_la_vinculacion($copia, $provider_order) {
		if (empty($copia->costo_neto_de_vinculacion) || empty($copia->provider_article_id)) {
			return false;
		}

		$proveedor = Provider::where('id', $provider_order->provider_id)->first();

		if (is_null($proveedor) || empty($proveedor->comercio_city_user_id)) {
			return false;
		}

		$dueno_de_la_fuente = Article::withTrashed()->where('id', $copia->provider_article_id)->value('user_id');

		return !is_null($dueno_de_la_fuente) && (int) $dueno_de_la_fuente === (int) $proveedor->comercio_city_user_id;
	}

	/**
	 * Escribe una copia existente bajo candado de fila (D2-M R3, Diseño 3bis). Relee la copia por id
	 * con lockForUpdate dentro de una transacción (tres intentos ante un deadlock; cada intento empieza
	 * de cero) y deja que `$aplicar` decida con la marca y el costo releídos. E1 y la reversión toman el
	 * mismo candado, así que las tres escrituras se serializan.
	 *
	 * @param int $client_article_id Id de la copia.
	 * @param callable $aplicar Recibe la copia releída; devuelve true si escribió.
	 * @return array ['escrito' => bool, 'copia' => Article|null] (copia releída, o null si no existe).
	 */
	private function escribir_copia_bloqueada($client_article_id, $aplicar) {
		$resultado = ['escrito' => false, 'copia' => null];

		DB::transaction(function () use ($client_article_id, $aplicar, &$resultado) {
			$resultado = ['escrito' => false, 'copia' => null];

			$copia = Article::withTrashed()
							->where('id', $client_article_id)
							->lockForUpdate()
							->first();

			$resultado['copia'] = $copia;

			// Con la copia en la papelera (o inexistente) no se escribe, igual que checkArticle hoy.
			if (is_null($copia) || !is_null($copia->deleted_at)) {
				return;
			}

			$resultado['escrito'] = (bool) $aplicar($copia);
		}, 3);

		return $resultado;
	}

	/**
	 * Costo que guarda la copia a partir del bruto de la fuente. Con aplicar_iva en 0 (destildado en la
	 * ficha) el bruto es lo que paga; si la cuenta del cliente carga el costo bruto
	 * (`el_costo_cargado_es_bruto`) se le saca el IVA con el mismo back-out y la misma cuantización que
	 * ya usa MasiveUpdateHelper (round a 6 decimales: así la memoria y la base tienen el mismo valor).
	 *
	 * Es público sólo para que el comando `vinculacion:costo-neto-de-copias` verifique con la misma regla.
	 *
	 * @param Article $client_article Copia (necesita iva_id y aplicar_iva).
	 * @param float $bruto Costo bruto de la fuente.
	 * @return float
	 */
	public function costo_para_la_copia($client_article, $bruto) {
		if (empty($client_article->aplicar_iva)) {
			return $bruto;
		}

		$user = $this->client->comercio_city_user;

		if (ArticlePricesHelper::el_costo_cargado_es_bruto($user, true)) {
			return round(ArticlePricesHelper::back_out_iva($client_article, $bruto), 6);
		}

		return $bruto;
	}

	/**
	 * ¿La vinculación tiene el contexto completo para crear o recalcular copias? El cliente existe (no
	 * está en la papelera), tiene usuario de ComercioCity y ese usuario tiene un Provider hacia el
	 * dueño de la fuente. Deja asignados `inventory_linkage`, `client` y `provider_for_client`.
	 * El orden importa: `client` se mira antes de setProviderForClient(), que lo desreferencia.
	 *
	 * @param InventoryLinkage $inventory_linkage
	 * @return bool
	 */
	private function contexto_de_vinculacion_valido($inventory_linkage) {
		$this->inventory_linkage = $inventory_linkage;
		$this->client = $inventory_linkage->client;

		if (is_null($this->client)) {
			return false;
		}

		if (is_null($this->client->comercio_city_user)) {
			return false;
		}

		$this->setProviderForClient();

		return !is_null($this->provider_for_client);
	}

	function checkArticle($article) {
		$inventory_linkages = InventoryLinkage::where('user_id', $this->user_id)
												->get();
		if (count($inventory_linkages) >= 1) {
			foreach ($inventory_linkages as $inventory_linkage) {
				// D2-M (R3.1): una vinculación sin contexto se salta antes de cualquier efecto
				// (copias, categorías, subcategorías, imágenes). Las otras vinculaciones siguen.
				if (!$this->contexto_de_vinculacion_valido($inventory_linkage)) {
					Log::warning('InventoryLinkageHelper: checkArticle salteo la vinculacion '.$inventory_linkage->id.' sin contexto');
					continue;
				}
				// Log::info('Entro en la vinculacion del cliente '.$client->name);
				// Log::info('Buscando article con user_id = '.$client->comercio_city_user_id.' y provider_article_id = '.$article->id);
				$client_article = Article::where('user_id', $this->client->comercio_city_user_id)
												->where('provider_article_id', $article->id)
												->withTrashed()
												->first();
				if (!is_null($client_article)) {
					Log::info('Habia article');
					if (is_null($client_article->deleted_at)) {
						// D2-M (R3): se escribe bajo candado y se decide con la marca y el costo releídos.
						$this->escribir_copia_bloqueada($client_article->id, function ($copia) use ($article) {
							// Log::info('Costo nuevo: '.$this->getClientArticlePrice($article));
							$copia->cost = $this->costo_esperado_de_la_copia($copia, $this->getClientArticlePrice($article));

							$copia->category_id = $this->getClientCategoryId($article);
							$copia->sub_category_id = $this->getClientSubCategoryId($article);
							$copia->save();
							ArticleHelper::setFinalPrice($copia, $this->client->comercio_city_user_id);
							return true;
						});
					} 
				} else {
					Log::info('No habia article');
					$client_article = $this->createClientArticle($article);
				}
				// $this->sendAddModelNotification('article', $client_article->id, false, $this->client->comercio_city_user_id);
			}
		}
	}

	function createClientArticle($article, $created_at = null) {
		if (is_null($created_at)) {
			$created_at = Carbon::now();
		}
		$price = $this->getClientArticlePrice($article);

		// D2-M: con el interruptor de la vinculación prendido la copia nace en costo neto y con
		// aplicar_iva explícito. Article::create no hidrata el default de la columna: sin `aplicar_iva => 1`
		// el objeto en memoria lo tiene en null y setFinalPrice no le suma el IVA (daría 4389,09, no 5310,80).
		$costo_neto = !empty($this->inventory_linkage->copia_en_costo_neto);

		$datos_copia = [
			'num'					=> $this->num('articles', $this->client->comercio_city_user_id),
			'bar_code'				=> $article->bar_code,
			'provider_code'			=> $article->provider_code,
			'category_id'			=> $this->getClientCategoryId($article),
			'sub_category_id'		=> $this->getClientSubCategoryId($article),
			'name'					=> $article->name,
			'slug'					=> $article->slug,
			'cost'					=> $price,
			'iva_id'				=> $article->iva_id,
			'provider_id'			=> $this->provider_for_client->id,
			'provider_article_id'	=> $article->id,
			'user_id'				=> $this->client->comercio_city_user_id,
			'created_at'			=> $created_at,
			'apply_provider_percentage_gain'	=> 1,
		];

		if ($costo_neto) {
			$datos_copia['aplicar_iva'] = 1;
			$datos_copia['costo_neto_de_vinculacion'] = 1;
		}

		$client_article = Article::create($datos_copia);

		if ($costo_neto) {
			// El objeto ya tiene iva_id (lo necesita back_out_iva) y aplicar_iva.
			$client_article->cost = $this->costo_para_la_copia($client_article, $price);
		}
        foreach ($article->images as $image) {
            $client_article_image = Image::create([
                'hosting_url'                                => $image->hosting_url,
                'imageable_id'                                => $client_article->id,
                'imageable_type'                              => 'article',
            ]);
        }
		ArticleHelper::setFinalPrice($client_article, $this->client->comercio_city_user_id);
		return $client_article;
	}

	function check_created_image($article, $created_image) {

		$inventory_linkages = InventoryLinkage::where('user_id', $this->user_id)
												->get();
		if (count($inventory_linkages) >= 1) {
			foreach ($inventory_linkages as $inventory_linkage) {
				$client = $inventory_linkage->client;

				$client_article = Article::where('user_id', $client->comercio_city_user_id)
												->where('provider_article_id', $article->id)
												->first();

				if (!is_null($client_article)) {
		            $image = Image::create([
		                'hosting_url'                                => $created_image->hosting_url,
		                'imageable_id'                              => $client_article->id,
		                'imageable_type'                            => 'article',
		                'temporal_id'                               => null,
		            ]);

					$this->sendAddModelNotification('article', $client_article->id, false, $client->comercio_city_user_id);
				}
			}
		}
	}

	function delete_image($image_to_delete) {

		if ($image_to_delete->imageable_type == 'article') {
			$article = Article::find($image_to_delete->imageable_id);

			if (!is_null($article)) {

				$inventory_linkages = InventoryLinkage::where('user_id', $this->user_id)
														->get();
				if (count($inventory_linkages) >= 1) {
					foreach ($inventory_linkages as $inventory_linkage) {
						$client = $inventory_linkage->client;

						$client_article = Article::where('user_id', $client->comercio_city_user_id)
														->where('provider_article_id', $article->id)
														->first();

						if (!is_null($client_article)) {
							$image = Image::where('hosting_url', $image_to_delete->hosting_url)
											->where('imageable_id', $client_article->id)
											->first();

							if (!is_null($image)) {
								$image->delete();
								Log::info('Se eliminio imagen del cliente en linkage');
							}
						}
					}
				}
			}
		}

	}

	function check_is_agotado($article) {

		if (!is_null($article->stock)) {
			$inventory_linkages = InventoryLinkage::where('user_id', $this->user_id)
													->get();
			if (count($inventory_linkages) >= 1) {
				foreach ($inventory_linkages as $inventory_linkage) {
					$client = $inventory_linkage->client;

					$client_article = Article::where('user_id', $client->comercio_city_user_id)
													->where('provider_article_id', $article->id)
													->first();

					if (!is_null($client_article)) {

						$save = false;

						// Si el stock del proveedor es 0, se setea el del cliente como agotado
						if ($article->stock <= 0 && ($client_article->stock != 0 || $client_article->stock == '')) {
			            	$client_article->stock = 0;
							$save = true;

						// Si el stock del proveedor no es menor que 0, y del cliente ya estaba agotado, se setea el del cliente como disponible
						} else if ($article->stock > 0 && $client_article->stock <= 0) {
			            	$client_article->stock = null;
							$save = true;
						}

						if ($save) {
				            $client_article->save();
							// $this->sendAddModelNotification('article', $client_article->id, false, $client->comercio_city_user_id);
						}
					}
				}
			}
		}


	}

	function getClientCategoryId($article) {
		if (!is_null($article->category_id)) {
			$category = Category::where('user_id', $this->client->comercio_city_user_id)
								->where('provider_category_id', $article->category_id)
								->first();
			if (!is_null($category)) {
				return $category->id;
			} else {
				$provider_category = Category::find($article->category_id);
				if (!is_null($provider_category)) {
					$category = Category::create([
						'num'					=> $this->num('categories', $this->inventory_linkage->client->comercio_city_user_id),
						'name'					=> $provider_category->name,
						'image_url'				=> $provider_category->image_url,
						'user_id'				=> $this->inventory_linkage->client->comercio_city_user_id,
						'provider_category_id'	=> $provider_category->id,
					]);
					return $category->id;
				}
			}
		}
		return null;
	}

	function getClientSubCategoryId($article) {
		if (!is_null($article->sub_category_id)) {
			$sub_category = SubCategory::where('user_id', $this->client->comercio_city_user_id)
									->where('provider_sub_category_id', $article->sub_category_id)
									->first();
			if (!is_null($sub_category)) {
				return $sub_category->id;
			} else {
				$provider_sub_category = SubCategory::find($article->sub_category_id);
				if (!is_null($provider_sub_category)) {

					$client_category = Category::where('user_id', $this->client->comercio_city_user_id)
												->where('provider_category_id', $provider_sub_category->category_id)
												->first();
					$sub_category = SubCategory::create([
						'num'						=> $this->num('sub_categories', $this->inventory_linkage->client->comercio_city_user_id),
						'name'						=> $provider_sub_category->name,
						'category_id'				=> $client_category->id,
						'user_id'					=> $this->inventory_linkage->client->comercio_city_user_id,
						'provider_sub_category_id'	=> $provider_sub_category->id,
					]);
					return $sub_category->id;
				}
			}
		}
		return null;
	}

	function getClientArticlePrice($article) {
		$price = $article->final_price;
		// Log::info('El articulo N° '.$article->num.', nombre: '.$article->name.' con el precio: '.$article->final_price);
		Log::info($article);
		if (count($this->price_types) >= 1) {

			$client_price_type = $this->price_types[count($this->price_types)-1];  
			if (!is_null($this->client->price_type)) {
				$client_price_type = $this->client->price_type;
			}

			foreach ($this->price_types as $price_type) {
				if ($price_type->position <= $client_price_type->position) {
					$percentage = $price_type->percentage;
                    if (count($price_type->sub_categories) >= 1 && !is_null($article->sub_category)) {
                        foreach ($price_type->sub_categories as $price_type_sub_category) {
                            if ($price_type_sub_category->id == $article->sub_category_id) {
                                // Log::info('Usando el porcetaje de '.$price_type_sub_category->name.' de '.$price_type_sub_category->pivot->percentage);
                                $percentage = $price_type_sub_category->pivot->percentage;
                            }
                        }
                    }
					$price = $price + ($price * $percentage / 100);
					// Log::info('Sumando el '.$percentage.' de '.$price_type->name.'. Quedo en: '.$price);
				}
			}
		}

		foreach ($this->inventory_linkage->categories as $category) {
			if ($category->id == $article->category_id) {
				// Log::info('inventory_linkage con la category '.$category->name);
				// Log::info('El precio estaba en '.$price);
				$price -= $price * $category->pivot->percentage_discount / 100;
				// Log::info('Quedo en '.$price);
			}
		}
		// Log::info('return '.$price);
		return $price;
	}

}