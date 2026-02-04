<?php

namespace App\Services\TiendaNube;

use App\Models\Article;
use Illuminate\Support\Facades\Log;
use App\Models\Image;

class TiendaNubeProductImageService extends BaseTiendaNubeService
{
    /**
     * Sube a Tienda Nube todas las imágenes del artículo que aún no tengan tienda_nube_image_id.
     *
     * @param Article $article
     * @return void
     */
    public function sync_images_for_article(Article $article): void
    {
        $images = $article->images()->whereNull('tienda_nube_image_id')->get();

        Log::info(count($images). ' imagenes sin tienda_nube_image_id para subir a tienda nube');

        foreach ($images as $image) {
            $this->upload_image_for_article($article, $image);
        }
    }

    /**
     * Sube una imagen específica a Tienda Nube y guarda el ID devuelto.
     *
     * @param Article $article
     * @param Image $image
     * @return void
     */
    public function upload_image_for_article(Article $article, Image $image): void
    {
        // if (empty($article->tienda_nube_image_id)) {
        //     throw new \InvalidArgumentException("El artículo no tiene tienda_nube_image_id.");
        // }

        if (empty($image->hosting_url)) {
            throw new \InvalidArgumentException("La imagen no tiene hosting_url.");
        }

        $src = $image->hosting_url;

        if (config('app.APP_ENV') === 'local') {
            $src = 'https://api-golonorte.comerciocity.com/public/storage/174337850923485.webp';
        }

        $payload = [
            'src' => $src,
        ];

        $endpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}/images";
        $response = $this->http()->post($endpoint, $payload);

        if ($response->failed()) {
            throw new \RuntimeException("Error al subir imagen a Tienda Nube: " . $response->body());
        }

        $data = $response->json();
        $image->tienda_nube_image_id = $data['id'] ?? null;
        $image->save();
    }

    public function delete_image_from_article(Article $article, Image $image): void
    {
        if (empty($article->tienda_nube_image_id) || empty($image->tiendanube_image_id)) {
            return; // Nada que eliminar
        }

        $endpoint = "/{$this->store_id}/products/{$article->tienda_nube_image_id}/images/{$image->tiendanube_image_id}";
        $response = $this->http()->delete($endpoint);

        if ($response->failed()) {
            throw new \RuntimeException("Error al eliminar imagen de Tienda Nube: " . $response->body());
        }

        $image->tiendanube_image_id = null;
        $image->save();
    }
}
