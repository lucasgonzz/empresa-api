<?php

namespace App\Services\TiendaNube;

use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Support\Facades\Log;

class TiendaNubeCategoryImageService extends BaseTiendaNubeService
{
    /**
     * Sube o actualiza la imagen de una categoría en Tienda Nube.
     *
     * @param Category|SubCategory $cat
     * @return void
     */
    public function upload_category_image($cat): void
    {
        Log::info('upload_category_image para');
        Log::info($cat->toArray());
        if (empty($cat->tiendanube_category_id)) {
            return;
            throw new \InvalidArgumentException("La categoría no tiene ID de Tienda Nube.");
        }

        if (empty($cat->image_url)) {
            return;
            throw new \InvalidArgumentException("La categoría no tiene image_url definida.");
        }

        $payload = [
            'image' => [
                'src' => $cat->image_url,
            ],
        ];

        $endpoint = "/{$this->store_id}/categories/{$cat->tiendanube_category_id}";
        $response = $this->http()->put($endpoint, $payload);

        if ($response->failed()) {
            throw new \RuntimeException("Error al subir imagen a Tienda Nube: " . $response->body());
        }
    }
}
