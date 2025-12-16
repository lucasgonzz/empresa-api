<?php

namespace App\Services\TiendaNube;

use App\Models\Article;
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
        if (empty($article->tiendanube_product_id)) {
            throw new \InvalidArgumentException("El artículo no tiene tiendanube_product_id.");
        }

        if (empty($image->image_url)) {
            throw new \InvalidArgumentException("La imagen no tiene image_url.");
        }

        $payload = [
            'src' => $image->image_url,
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
        if (empty($article->tiendanube_product_id) || empty($image->tiendanube_image_id)) {
            return; // Nada que eliminar
        }

        $endpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}/images/{$image->tiendanube_image_id}";
        $response = $this->http()->delete($endpoint);

        if ($response->failed()) {
            throw new \RuntimeException("Error al eliminar imagen de Tienda Nube: " . $response->body());
        }

        $image->tiendanube_image_id = null;
        $image->save();
    }
}
