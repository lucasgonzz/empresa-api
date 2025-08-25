<?php

namespace App\Services;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\Image;
use App\Models\PriceType;
use App\Services\BaseTiendaNubeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TiendaNubeService extends BaseTiendaNubeService
{

    function __construct() {
        parent::__construct();
        $this->price_types = PriceType::where('user_id', UserHelper::userId())
                                        ->get();
    }

    public function crearOActualizarProducto(Article $article)
    {
        Log::info('crearOActualizarProducto');
        if ($article->tiendanube_product_id) {
            return $this->actualizar($article);
        }

        return $this->crear($article);
    }

    protected function crear(Article $article)
    {

        $payload = [
            'name' => ['es' => $article->name],
            'variants' => [[
                'price' => $this->get_price($article),
                'barcode'   => $article->bar_code,
                // opcional: 'sku' => $article->sku,
                // opcional: 'barcode' => $article->barcode,
            ]],
        ];

        if (is_null($article->stock)) {
            $payload['variants'][0]['stock_management'] = false;
        } else {
            $payload['variants'][0]['stock'] = (int)$article->stock;
        }

        $endpoint = "/{$this->store_id}/products";

        $response = $this->http()->post($endpoint, $payload);

        Log::info('Se creo articulo en tienda nube, response:');
        Log::info($response->json());
        Log::info('con el token: '.$this->access_token);

        if ($response->successful()) {
            $data = $response->json();
            $article->tiendanube_product_id = $data['id'];
            $article->needs_sync_with_tn = false;

            if (!empty($data['variants'][0]['id'])) {
                $article->tiendanube_variant_id = $data['variants'][0]['id'];
            }

            $article->save();
        }

        return $response;
    }

    protected function actualizar(Article $article)
    {

        $payload = [
            'name'  => ['es' => $article->name],
        ];

        $endpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}";

        $response = $this->http()->put($endpoint, $payload);

        Log::info('Se actualizo articulo en tienda nube, response:');
        Log::info($response->json());

        if ($article->tiendanube_variant_id) {

            $payload = [
                'price' => $this->get_price($article),
                'barcode'   => $article->bar_code,
            ];

            if (is_null($article->stock)) {
                $payload['stock_management'] = false;
            } else {
                $payload['stock'] = (int)$article->stock;
            }

            $endpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}/variants/{$article->tiendanube_variant_id}";

            Log::info('Se va a actualizar variante en tienda nube:');
            Log::info('endpoint: '.$endpoint);
            Log::info('payload: ');
            Log::info($payload);

            $response = $this->http()->put($endpoint, $payload);

            Log::info('Se actualizo variante en tienda nube, response:');
            Log::info($response->json());
        }

        if ($response->successful()) {
            $article->needs_sync_with_tn = false;
            $article->save();
        }

        return $response;
    }

    /**
     * Sube una imagen del artículo a Tienda Nube.
     *
     * @param  Article $article  Tu modelo de artículo (debe tener tiendanube_product_id si ya existe).
     * @param  Image   $image    Tu modelo de imagen (debe tener ->url o ->path/->filename).
     * @param  int|null $position (opcional) Posición deseada; 1 = principal. Si es null, TN la agrega al final.
     * @return array             Respuesta JSON de Tienda Nube (o lanza excepción si falla).
     */
    public function subir_imagen_de_articulo(Article $article, Image $image, ?int $position = null): array
    {
        // Asegurarnos de que el producto existe en Tienda Nube
        if (!$article->tiendanube_product_id) {
            $this->crearOActualizarProducto($article);
        }

        $endpoint = "/{$this->store_id}/products/{$article->tiendanube_product_id}/images";

        // Armar body: por URL pública (src) o adjunto base64 (attachment)
        $payload = [];

        // 1) Si viene URL pública
        if (!empty($image->hosting_url)) {
            $payload['src'] = $image->hosting_url;
        }

        if (env('APP_ENV') == 'local') {
            $payload['src'] = 'https://api-golonorte.comerciocity.com/public/storage/174222051315387.webp';
        }

        if (!is_null($position)) {
            $payload['position'] = (int)$position;
        } else {
            $payload['position'] = count($article->images);
        }

        Log::info('payload:');
        Log::info($payload);

        $response = $this->http()->post($endpoint, $payload);

        if ($response->failed()) {
            // Podés loguear más info acá
            throw new \RuntimeException('Error subiendo imagen a TN: '.$response->body());
        }

        $data = $response->json();

        Log::info('Se guardo imagen en tienda nube, response:');
        Log::info($data);

        // (Opcional) guardar el ID de imagen de TN en tu base
        // Si tu modelo Image tiene esta columna:
        if (property_exists($image, 'tiendanube_image_id') || $image->getAttribute('tiendanube_image_id') !== null) {
            $image->tiendanube_image_id = $data['id'] ?? null;
            $image->save();
        }

        // Si seteaste position=1 y querés que quede como principal, listo.
        // Si luego necesitás reordenar, podés hacer un PUT al endpoint /images/{image_id}.

        return $data;
    }

    function get_price($article) {

        if (count($this->price_types) >= 1) {

            Log::info('Tiene price_types');

            $price_type_tn = $article->price_type_tienda_nube();

            if ($price_type_tn) {
                Log::info('Tiene price_type_tn');
                return (float) $price_type_tn->pivot->final_price;
            }
        }

        Log::info('Retornando final_price');

        return (float) $article->final_price;
    }
}
