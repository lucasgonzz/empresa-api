<?php

namespace App\Services\TiendaNube;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Services\TiendaNube\BaseTiendaNubeService;
use App\Services\TiendaNube\TiendaNubeImageService;
use App\Services\TiendaNube\TiendaNubeProductDescriptionService;
use App\Services\TiendaNube\TiendaNubeProductImageService;
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
            $article = $this->actualizar($article);
        } else {
            $article = $this->crear($article);
        }

        $service = new TiendaNubeProductDescriptionService();
        $service->update_descriptions($article);

        $service = new TiendaNubeImageService();

        if (env('CARGAR_IMAGENES_DE_ARTICULOS_A_TIENDA_NUBE', false)) {
            foreach ($article->images as $image) {
                $service->subirImagenDeArticulo($article, $image);
            }
        }

    }

    /** Crear producto en Tienda Nube */
    public function crear(Article $article)
    {
        $tnCategoryId = $this->categoryService->resolveTNCategoryIdForArticle($article);


        $payload = $this->get_article_data($article);
        $payload['variants'] = [];


        if ($tnCategoryId) {
            $payload['categories'] = [(int) $tnCategoryId];
        }


        // Creamos la info de la variante
        $variantPayload = $this->get_variant_data($article);
        $payload['variants'][0] = $variantPayload;

        Log::info('TiendaNube payload:');
        Log::info($payload);

        $endpoint = "/{$this->store_id}/products";
        $response = $this->http()->post($endpoint, $payload);

        if ($response->successful()) {
            $data = $response->json();

            $article->tiendanube_product_id = $data['id'] ?? null;
            $article->handle = isset($data['handle']) ? $data['handle']['es'] : null;

            $article->needs_sync_with_tn    = false;

            if (!empty($data['variants'][0]['id'])) {
                $article->tiendanube_variant_id = $data['variants'][0]['id'];
            }
            $article->save();
        }

        return $article;
    }

    /** Actualizar producto + variante en Tienda Nube */
    public function actualizar(Article $article)
    {

        $payload = $this->get_article_data($article);


        // Si hay categoría/subcategoría en tu artículo, la enviamos.
        // Si no hay ninguna, NO enviamos "categories" (para no borrar lo existente).
        $tnCategoryId = $this->categoryService->resolveTNCategoryIdForArticle($article);
        if ($tnCategoryId) {
            $payload['categories'] = [(int) $tnCategoryId];
        }

        $endpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}";


        Log::info('TiendaNube product payload:');
        Log::info($payload);

        $response = $this->http()->put($endpoint, $payload);

        if ($response->successful()) {
            $data = $response->json();

            $article->handle = isset($data['handle']) ? $data['handle']['es'] : null;
            $article->save();
        }

        // Actualizar variante si la tenemos
        if ($article->tiendanube_variant_id) {
            
            // Creamos la info de la variante
            $variantPayload = $this->get_variant_data($article);

            Log::info('TiendaNube variant payload:');
            Log::info($variantPayload);

            $vEndpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}/variants/{$article->tiendanube_variant_id}";
            $response  = $this->http()->put($vEndpoint, $variantPayload);
        }

        if ($response->successful()) {
            $article->needs_sync_with_tn = false;
            $article->save();
        }

        $this->update_images($article);
        
        return $article;
    }

    function update_images($article) {

        $tn_image = new TiendaNubeProductImageService();
        $tn_image->sync_images_for_article($article);
    }

    function get_variant_data($article) {

        $variantPayload = [
            'price'     => $this->get_price($article),
            'sku'       => $article->id,
            'barcode'   => $article->bar_code,
            'weight'    => $article->peso,
            'depth'     => $article->profundidad,
            'width'     => $article->ancho,
            'height'    => $article->alto,
        ];

        if (
            !is_null($article->precio_promocional)
            && $article->precio_promocional > 0
        ) {
           $variantPayload['promotional_price'] = $article->precio_promocional;
        }

        if (is_null($article->stock)) {
            $variantPayload['stock_management'] = false;
        } else {
            $variantPayload['stock_management'] = true;
            
            $stock = (int)$article->stock;

            if ($stock < 0) {
                $stock = 0;
            }
            $variantPayload['stock'] = $stock;
        }

        return $variantPayload;
    }

    function get_article_data($article) {
        $article_data = [
            'name'                      => ['es' => $article->name],
            'seo_title'                 => !is_null($article->seo_title) ? $article->seo_title : $article->name,
            'seo_description'           => $article->seo_description,
            'video_url'                 => $article->video_url,
            'published'                 => $article->disponible_tienda_nube ? true : false,
            'free_shipping'             => $article->free_shipping ? true : false,
            'requires_shipping'         => $article->requires_shipping ? true : false,
            'requires_shipping'         => $article->requires_shipping ? true : false,
        ];

        // Agrego TAGS
        $tags = '';
        foreach ($article->tags as $tag) {
            $tags .= $tag->name.',';
        }

        if ($tags != '') {

            $tags = substr($tags, 0, -1);

            $article_data['tags'] = $tags;
        }

        

        // if ($article->images && count($article->images) > 0) {
        //     $imagenes_payload = [];

        //     $position = 1;
        //     foreach ($article->images as $img) {
        //         if (
        //             $img->hosting_url
        //             && $img->tienda_nube_image_id
        //         ) {

        //             $src = $img->hosting_url;

        //             if (
        //                 env('APP_ENV') === 'local'
        //             ) {
        //                 $src = 'https://api-golonorte.comerciocity.com/public/storage/174337850923485.webp';
        //             }

        //             $image_data = [];
        //             $image_data = [
        //                 'src' => $src,
        //             ];

        //             if ($img->tienda_nube_image_id) {
        //                 $image_data['id']  = $img->tienda_nube_image_id;
        //                 $image_data['product_id']  = $article->tiendanube_product_id;
        //                 $image_data['position']  = $position;
        //             }
        //             $imagenes_payload[] = $image_data;
        //             $position++;
        //         }
        //     }

        //     if (count($imagenes_payload) > 0) {
        //         $article_data['images'] = $imagenes_payload;
        //     } else {
        //         Log::info('La imagen no tiene image_url.');
        //     }
        // } else {
        //     Log::info('Artículo sin imágenes al crear producto en Tienda Nube.');
        // }

        return $article_data;
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

        $price = (float) $article->final_price;

        if (
            $article->cost_in_dollars
            && !$article->user->cotizar_precios_en_dolares
        ) {
            Log::info('Cotizando '.$price.' x '.$article->user->dollar);
            $price *= $article->user->dollar;
            $price = round($price, 0);
            Log::info('Queda en: '.$price);
        }

        return $price;
    }
}
