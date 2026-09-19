<?php

namespace App\Services\Traits;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Models\GeocoderCounter;
use Carbon\Carbon;
use Intervention\Image\ImageManager;

/**
 * La búsqueda de imágenes en Google Custom Search con su cuota diaria, y la descarga/recorte
 * de la imagen encontrada (misión asistente-masivas-imagenes-y-remito, 19/9/2026).
 *
 * Los cuatro métodos se MOVIERON textualmente desde ProcessArticleBatchImagesJob, que los tenía
 * como `private`, para que ProcessCategoryImagesJob use exactamente la misma búsqueda, el mismo
 * contador del día y la misma descarga. Se extrajo en vez de copiarlo a propósito: el contador
 * de cuota tiene una regla contraintuitiva (se descuenta SOLO cuando hubo búsqueda de verdad,
 * también cuando vino vacía) y una copia era la forma segura de que las dos quedaran distintas
 * en el próximo arreglo.
 *
 * 🔴 Depende de la clase que lo usa. Necesita:
 *   - `$this->user_id`         dueño del comercio (contador del día y escrituras);
 *   - `$this->google_api_key`  key de Custom Search (la del owner o la de config);
 *   - `$this->cx`              id del motor de búsqueda personalizado;
 *   - el trait GoogleSearchHelpers, por `google_api_http()` (Referer para la API) y
 *     `google_http()` (sin Referer, para la descarga: ver el docblock de google_api_http()).
 *
 * `$parametros_extra` de fetch_google_image_results() se mezcla en la query del GET: sin extras el
 * request es byte a byte el de siempre, y el job de categorías lo usa para pedir fondo blanco
 * (`imgDominantColor => 'white'`).
 */
trait BusquedaDeImagenesEnGoogle
{
    /**
     * Obtiene o crea el GeocoderCounter del día para el usuario.
     *
     * @return GeocoderCounter
     */
    protected function get_or_create_counter(): GeocoderCounter
    {
        $counter = GeocoderCounter::where('user_id', $this->user_id)
            ->whereDate('created_at', Carbon::today())
            ->first();

        if (!$counter) {
            $counter = GeocoderCounter::create([
                'counter' => 0,
                'user_id' => $this->user_id,
            ]);
        }

        return $counter;
    }

    /**
     * Ejecuta una búsqueda de imágenes en Google Custom Search e incrementa el contador diario.
     * Usa el cliente HTTP de Laravel (Guzzle) en lugar de file_get_contents para mayor
     * compatibilidad con HTTPS en entornos Windows/WAMP.
     *
     * @param string          $query            Término de búsqueda.
     * @param GeocoderCounter $counter          Contador de búsquedas del día.
     * @param array           $parametros_extra Parámetros adicionales de la API de Custom Search
     *                                          (por ejemplo `imgDominantColor`). Vacío = el
     *                                          request de siempre.
     * @return array Estructura con items, api_error y total_results.
     */
    protected function fetch_google_image_results(string $query, GeocoderCounter $counter, array $parametros_extra = []): array
    {
        // El contador NO se incrementa aca arriba, que es donde estaba y donde parece natural
        // ponerlo: un error de la API le descontaba busquedas al cliente sin haber consultado nada.
        // El 4/8/2026, con el bug del referrer (prompt 01), un solo batch le quemo 4 busquedas de 10
        // sin traer una sola imagen, y cada reintento le comia otras tantas. Google tampoco cobra
        // una request que rechaza por restriccion de referrer ni una que falla por conexion.
        // El consumo se registra abajo, en el unico camino que llego a buscar de verdad.
        try {
            // google_api_http() y no google_http(): la key de Custom Search tiene restriccion por
            // referrer HTTP y sin el header Google responde "Requests from referer <empty> are
            // blocked". El User-Agent de aca no lo pisa: son claves distintas del mismo array.
            $http_response = $this->google_api_http()
                ->timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get('https://www.googleapis.com/customsearch/v1', array_merge([
                    'key'        => $this->google_api_key,
                    'cx'         => $this->cx,
                    'searchType' => 'image',
                    'q'          => $query,
                ], $parametros_extra));
        } catch (\Exception $e) {
            return [
                'items'         => null,
                'api_error'     => 'Error de conexión: '.$e->getMessage(),
                'total_results' => null,
            ];
        }

        $body = $http_response->json();

        if (!$http_response->successful()) {
            $error_message = isset($body['error']['message'])
                ? $body['error']['message']
                : 'HTTP '.$http_response->status();

            return [
                'items'         => null,
                'api_error'     => $error_message,
                'total_results' => null,
            ];
        }

        if (isset($body['error'])) {
            return [
                'items'         => $body['items'] ?? [],
                'api_error'     => $body['error']['message'] ?? json_encode($body['error']),
                'total_results' => isset($body['searchInformation']['totalResults'])
                    ? (int) $body['searchInformation']['totalResults']
                    : 0,
            ];
        }

        $this->consumir_cuota($counter);

        return [
            'items'         => $body['items'] ?? [],
            'api_error'     => null,
            'total_results' => isset($body['searchInformation']['totalResults'])
                ? (int) $body['searchInformation']['totalResults']
                : 0,
        ];
    }

    /**
     * Descuenta una busqueda de la cuota diaria del usuario.
     *
     * Se llama SOLO desde el camino exitoso de `fetch_google_image_results`, y a proposito tambien
     * cuando la busqueda no encontro ninguna imagen: Google la cobro igual, porque la query se
     * ejecuto y devolvio cero resultados. El criterio es "¿llego a haber busqueda?", no "¿sirvio de
     * algo?" — es la parte contraintuitiva y es justo donde alguien va a querer "corregirlo".
     *
     * @param GeocoderCounter $counter Contador de busquedas del dia.
     * @return void
     */
    protected function consumir_cuota(GeocoderCounter $counter)
    {
        $counter->counter += 1;
        $counter->save();
    }

    /**
     * Descarga una imagen por URL, la recorta a cuadrado 1:1 centrado y la guarda como .webp.
     *
     * Antes devolvía directamente la URL pública o null (grupo 201, prompt 03: pasa a devolver
     * un array para distinguir un fallo de descarga HTTP de un fallo de procesamiento de
     * Intervention, y para poder reportar el http_status en el diagnóstico de intentos).
     *
     * @param string $image_url URL de la imagen a descargar.
     * @return array {
     *     url:         string|null,       URL pública del archivo guardado, o null si falló.
     *     failure:     'http'|'format'|null,  motivo del fallo (null si tuvo éxito).
     *     http_status: int|null,          status HTTP de la descarga, si se llegó a tener respuesta.
     * }
     */
    protected function download_crop_and_save(string $image_url): array
    {
        $http_status = null;

        try {
            $http_response = $this->google_http()
                ->timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($image_url);

            $http_status = $http_response->status();

            if (!$http_response->successful()) {
                return ['url' => null, 'failure' => 'http', 'http_status' => $http_status];
            }

            $image_data = $http_response->body();
        } catch (\Exception $e) {
            return ['url' => null, 'failure' => 'http', 'http_status' => null];
        }

        if ($image_data === '' || $image_data === null) {
            return ['url' => null, 'failure' => 'http', 'http_status' => $http_status];
        }

        try {
            $manager = new ImageManager();
            $img     = $manager->make($image_data);

            $w    = $img->width();
            $h    = $img->height();
            $size = min($w, $h);
            $x    = (int)(($w - $size) / 2);
            $y    = (int)(($h - $size) / 2);
            $img->crop($size, $size, $x, $y);

            $filename = time().rand(1, 100000).'.webp';
            $img->save(storage_path().'/app/public/'.$filename);
        } catch (\Exception $e) {
            return ['url' => null, 'failure' => 'format', 'http_status' => $http_status];
        }

        // URL publica del archivo, centralizada en ApiUrlHelper (unico lugar que sabe si
        // corresponde agregar "/public" segun VPS/APP_ENV; ver grupo 230, prompt 01).
        return ['url' => ApiUrlHelper::storage($filename), 'failure' => null, 'http_status' => $http_status];
    }
}
