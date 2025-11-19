<?php

namespace App\Services\TiendaNube;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Services\TiendaNube\BaseTiendaNubeService;
use Illuminate\Support\Facades\Log;

/**
 * Responsabilidad: Crear/actualizar productos y variantes en Tienda Nube.
 * - Depende de TiendaNubeCategoryService para resolver categorías.
 */
class TiendaNubeProductService extends BaseTiendaNubeService
{
    protected $price_types;
    protected TiendaNubeCategoryService $categoryService;

    public function __construct(?TiendaNubeCategoryService $categoryService = null)
    {
        parent::__construct();

        // cacheá las listas de precio del usuario, si tu lógica lo requiere
        $this->price_types = PriceType::where('user_id', UserHelper::userId())->get();

        // inyección opcional: si no viene, se crea
        $this->categoryService = $categoryService ?? new TiendaNubeCategoryService();
    }

    /** Crea o actualiza el producto en TN según si ya tiene tiendanube_product_id */
    public function crearOActualizarProducto(Article $article)
    {
        if ($article->tiendanube_product_id) {
            return $this->actualizar($article);
        }
        return $this->crear($article);
    }

    /** Crear producto en Tienda Nube */
    public function crear(Article $article)
    {
        $tnCategoryId = $this->categoryService->resolveTNCategoryIdForArticle($article);

        $payload = [
            'name'     => ['es' => $article->name],
            'variants' => [[
                'price'   => $this->get_price($article),
                'sku'     => $article->id,
                'barcode' => $article->bar_code,
                // Agregar medidas
            ]],
        ];

        if ($tnCategoryId) {
            $payload['categories'] = [(int) $tnCategoryId];
        }

        if (is_null($article->stock)) {
            $payload['variants'][0]['stock_management'] = false;
        } else {
            $payload['variants'][0]['stock'] = (int) $article->stock;
        }

        $endpoint = "/{$this->store_id}/products";
        $response = $this->http()->post($endpoint, $payload);

        if ($response->successful()) {
            $data = $response->json();

            $article->tiendanube_product_id = $data['id'] ?? null;
            $article->needs_sync_with_tn    = false;

            if (!empty($data['variants'][0]['id'])) {
                $article->tiendanube_variant_id = $data['variants'][0]['id'];
            }
            $article->save();
        }

        return $response;
    }

    /** Actualizar producto + variante en Tienda Nube */
    public function actualizar(Article $article)
    {
        $payload = [
            'name' => ['es' => $article->name],
        ];

        // Si hay categoría/subcategoría en tu artículo, la enviamos.
        // Si no hay ninguna, NO enviamos "categories" (para no borrar lo existente).
        $tnCategoryId = $this->categoryService->resolveTNCategoryIdForArticle($article);
        if ($tnCategoryId) {
            $payload['categories'] = [(int) $tnCategoryId];
        }

        $endpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}";
        $response = $this->http()->put($endpoint, $payload);

        // Actualizar variante si la tenemos
        if ($article->tiendanube_variant_id) {
            $variantPayload = [
                'price'   => $this->get_price($article),
                'barcode' => $article->bar_code,
            ];

            if (is_null($article->stock)) {
                $variantPayload['stock_management'] = false;
                Log::info('Sin stock - stock_management: false', [
                    'article_id' => $article->id,
                    'stock' => $article->stock,
                ]);
            } else {
                $variantPayload['stock_management'] = true;
                $variantPayload['stock'] = (int) $article->stock;
                Log::info('Con stock - actualizando Tienda Nube', [
                    'article_id' => $article->id,
                    'stock' => $article->stock,
                    'payload' => $variantPayload,
                ]);
            }

            $vEndpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}/variants/{$article->tiendanube_variant_id}";
            $response  = $this->http()->put($vEndpoint, $variantPayload);
        }

        if ($response->successful()) {
            $article->needs_sync_with_tn = false;
            $article->save();
        }

        return $response;
    }

    /** Estrategia de precio: usa lista especial de TN si existe, si no el final_price del artículo */
    protected function get_price(Article $article): float
    {
        if (count($this->price_types) >= 1) {
            $price_type_tn = $article->price_type_tienda_nube();
            if ($price_type_tn) {
                return (float) $price_type_tn->pivot->final_price;
            }
        }
        return (float) $article->final_price;
    }
}
