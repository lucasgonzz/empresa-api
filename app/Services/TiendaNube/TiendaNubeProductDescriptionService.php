<?php

namespace App\Services\TiendaNube;

use App\Services\TiendaNube\BaseTiendaNubeService;
use Illuminate\Support\Facades\Log;

class TiendaNubeProductDescriptionService extends BaseTiendaNubeService
{
    /**
     * Actualiza la descripción y descripción_html de un producto.
     *
     * @param int   $product_id
     * @param array $descriptions      // Ej: ['es' => '...', 'en' => '...']
     * @param array $descriptions_html // Ej: ['es' => '<p>...</p>']
     * @return array
     */
    public function update_descriptions($article)
    {

        $description = $article->descripcion;

        if (count($article->descriptions) > 0) {
            $description = $article->descriptions[0]->content;
        }

        if (
            is_null($description)
            || $description == ''
        ) {
            return;
        }
        
        $payload = [
            'description' => ['es' => $description],
        ];

        $endpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}";

        Log::info('Se llamo a '.$endpoint.' con payload:');
        Log::info($payload);

        $response = $this->http()->put($endpoint, $payload);

        if ($response->failed()) {
            Log::error('error_actualizar_descripciones', [
                'product_id' => $product_id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            throw new \RuntimeException("Error actualizando descripciones (status {$response->status()})");
        } else {
            Log::info('Se actualizo description:');
            Log::info($response->json());
        }

        return $response->json();
    }
}
