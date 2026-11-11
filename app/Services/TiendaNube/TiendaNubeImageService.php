<?php

namespace App\Services\TiendaNube;

use App\Models\Article;
use App\Models\Image;

/** Entrada compatible para los jobs de carga posterior de fotos. */
class TiendaNubeImageService extends BaseTiendaNubeService
{
    public function subirImagenDeArticulo(Article $article, Image $image, ?int $position = null): array
    {
        $positions = $position === null ? [] : [$image->id => $position];
        if (!$article->tiendanube_product_id) {
            (new TiendaNubeProductService(null, $article->user_id))->crearOActualizarProducto($article, $positions);
        } else {
            (new TiendaNubeProductImageService($article->user_id))->reconcile($article, true, null, $positions, $image->id);
        }
        $image->refresh();
        if (!$image->tienda_nube_image_id) {
            throw new \RuntimeException('La imagen no quedó confirmada en Tienda Nube.');
        }
        $remote = (new TiendaNubeProductImageService($article->user_id))->remote_images($article);
        foreach ($remote as $row) {
            if ((string) $row['id'] === (string) $image->tienda_nube_image_id) {
                return $row;
            }
        }
        throw new \RuntimeException('La imagen confirmada ya no aparece en el producto remoto.');
    }
}
