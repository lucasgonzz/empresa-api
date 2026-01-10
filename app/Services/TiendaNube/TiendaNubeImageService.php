<?php

namespace App\Services\TiendaNube;

use App\Models\Article;
use App\Models\Image;
use App\Services\TiendaNube\BaseTiendaNubeService;
use Illuminate\Support\Facades\Log;

/**
 * Responsabilidad: Subida y manejo de imágenes de productos en Tienda Nube.
 */
class TiendaNubeImageService extends BaseTiendaNubeService
{
    /**
     * Sube una imagen del artículo a Tienda Nube.
     * - Si el producto no existe aún en TN, lo crea/actualiza antes de subir la imagen
     * - position: 1 => principal; si es null, la API la agrega al final
     */
    public function subirImagenDeArticulo(Article $article, Image $image, ?int $position = null): array
    {

        Log::info('subirImagenDeArticulo para '.$article->name);
        // Garantizar que el producto exista
        if (!$article->tiendanube_product_id) {
            // Podés inyectar el ProductService desde afuera si preferís;
            // acá lo instanciamos directo para simplificar.
            $productService = new TiendaNubeProductService();
            $productService->crearOActualizarProducto($article);
        }

        $endpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}/images";

        $payload = [];

        // Usar URL pública (src) o attachment base64 (si implementás esa variante)
        if (!empty($image->hosting_url)) {
            $payload['src'] = $image->hosting_url;
        }

        // Atajo para probar en local (opcional)
        if (env('APP_ENV') === 'local' && empty($payload['src'])) {
            $payload['src'] = 'https://api-golonorte.comerciocity.com/public/storage/174222051315387.webp';
        }

        if (!is_null($position)) {
            $payload['position'] = (int) $position;
        } else {
            // Si no enviás position, TN la coloca al final;
            // si preferís forzar orden localmente:
            $payload['position'] = max(1, count($article->images));
        }

        $response = $this->http()->post($endpoint, $payload);
        if ($response->failed()) {
            Log::error('Error subiendo imagen a TN: '.$response->body());
            throw new \RuntimeException('Error subiendo imagen a TN: '.$response->body());
        }

        $data = $response->json();

        Log::info('respuesta de imagen tienda nube');
        Log::info($data);

        // Guardar ID de imagen de TN si tu modelo Image tiene esa columna
        if ($image->getAttribute('tiendanube_image_id') !== null || property_exists($image, 'tiendanube_image_id')) {
            $image->tiendanube_image_id = $data['id'] ?? null;
            $image->save();

            $article->needs_sync_with_tn = false;
            $article->timestamps = false;
            $article->save();
        }

        return $data;
    }
}
