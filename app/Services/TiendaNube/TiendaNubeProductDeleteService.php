<?php

namespace App\Services\TiendaNube;

use App\Models\Article;
use App\Services\TiendaNube\BaseTiendaNubeService;
use Illuminate\Support\Facades\Http;

/**
 * Elimina productos de Tienda Nube.
 */
class TiendaNubeProductDeleteService extends BaseTiendaNubeService
{
    /**
     * Elimina un producto de Tienda Nube usando su ID remoto.
     */
    public function eliminar_producto(Article $article): bool
    {
        if (!$article->tiendanube_product_id) {
            return false; // No hay producto asociado a Tienda Nube
        }

        $url = "/{$this->store_id}/products/{$article->tiendanube_product_id}";

        $response = $this->http()->delete($url);

        if ($response->successful()) {
            $article->tiendanube_product_id = null;
            $article->save();
            return true;
        }

        return false;
    }
}
