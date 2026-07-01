<?php

namespace App\Jobs;

use App\Events\ArticleBatchImagesProcessed;
use App\Models\Article;
use App\Models\GeocoderCounter;
use App\Models\Image;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;

class ProcessArticleBatchImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Intentos máximos antes de marcar el job como fallido. */
    public $tries = 1;

    /** @var int Tiempo máximo de ejecución en segundos (10 minutos). */
    public $timeout = 600;

    /** @var array IDs de los artículos a procesar. */
    protected $article_ids;

    /** @var int ID del usuario dueño de los artículos. */
    protected $user_id;

    /** @var string Clave de Google Custom Search API a utilizar. */
    protected $google_api_key;

    /** @var string ID del motor de búsqueda personalizado (cx). */
    protected $cx;

    /** @var int Cuota diaria máxima de búsquedas del owner. */
    protected $google_cuota;

    /**
     * @param array  $article_ids    IDs de los artículos a procesar.
     * @param int    $user_id        ID del usuario dueño.
     * @param string $google_api_key Clave de Google Custom Search API.
     * @param string $cx             ID del motor de búsqueda personalizado.
     * @param int    $google_cuota   Cuota diaria máxima del owner.
     */
    public function __construct(
        array $article_ids,
        int $user_id,
        string $google_api_key,
        string $cx,
        int $google_cuota
    ) {
        $this->article_ids   = $article_ids;
        $this->user_id       = $user_id;
        $this->google_api_key = $google_api_key;
        $this->cx            = $cx;
        $this->google_cuota  = $google_cuota;
    }

    /**
     * Procesa cada artículo: busca imagen en Google, descarga, recorta y guarda.
     * Emite el evento ArticleBatchImagesProcessed al finalizar con el resumen.
     *
     * @return void
     */
    public function handle()
    {
        $processed              = 0;
        $skipped                = 0;
        $skipped_names          = [];
        $skipped_by_quota       = 0;
        $skipped_by_quota_names = [];
        $quota_reached          = false;
        $attempted_article_ids  = [];
        $needs_review           = 0;
        $needs_review_items     = [];

        $counter = $this->get_or_create_counter();

        Log::info(sprintf(
            '[BatchImages] Inicio batch (%d artículos), api_key ...%s',
            count($this->article_ids),
            substr($this->google_api_key, -8)
        ));

        foreach ($this->article_ids as $article_id) {
            $attempted_article_ids[] = $article_id;

            $article = Article::where('id', $article_id)
                ->where('user_id', $this->user_id)
                ->first();

            if (!$article) {
                continue;
            }

            /* Verificar cuota antes de cada búsqueda; terminar loop si se agotó. */
            if ($counter->counter >= $this->google_cuota) {
                $quota_reached = true;
                $skipped_by_quota++;
                $skipped_by_quota_names[] = $this->get_article_display_name($article);
                Log::info('ProcessArticleBatchImagesJob: cuota agotada, deteniendo procesamiento.', [
                    'user_id' => $this->user_id,
                    'counter' => $counter->counter,
                    'cuota'   => $this->google_cuota,
                ]);
                break;
            }

            /*
             * Secuencia de búsqueda igual que prepare_auto_flow_queries en SearchImage.vue:
             * 1) código de barras GS1 válido (normalizado), 2) nombre del artículo como fallback.
             */
            $search_queries = $this->get_search_queries($article);
            if (empty($search_queries)) {
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::info('ProcessArticleBatchImagesJob: artículo sin query de búsqueda.', ['article_id' => $article->id]);
                continue;
            }

            $saved_url      = null;
            $confidence     = null;
            $quota_exceeded = false;

            foreach ($search_queries as $query_index => $search_query) {
                $criterion = $search_query['criterion'];
                $query     = $search_query['query'];

                if ($counter->counter >= $this->google_cuota) {
                    $quota_exceeded = true;
                    break;
                }

                /*
                 * Si el primer criterio fue código de barras y ahora se prueba nombre,
                 * dejar un log explícito de fallback antes de la búsqueda por nombre.
                 */
                if (
                    $query_index > 0
                    && $criterion === 'name'
                    && $search_queries[0]['criterion'] === 'bar_code'
                ) {
                    $this->log_fallback_to_name_search($article, $query);
                }

                $search_result = $this->fetch_google_image_results($query, $counter);
                $this->log_article_search_result($article, $criterion, $query, $search_result);

                $items = $search_result['items'];
                if ($items === null || empty($items)) {
                    continue;
                }

                /*
                 * Probar cada resultado en orden (igual que select_first_available_image en el SPA).
                 * Si ninguna imagen de esta query sirve, se intenta la siguiente query (p. ej. nombre).
                 */
                foreach ($items as $item) {
                    $quality   = $this->evaluate_image_quality($item);
                    $saved_url = $this->download_crop_and_save($quality['url']);

                    if ($saved_url === null) {
                        continue;
                    }

                    $confidence = $quality['confidence'];
                    break;
                }

                if ($saved_url !== null) {
                    break;
                }
            }

            if ($quota_exceeded && $saved_url === null) {
                $quota_reached = true;
                $skipped_by_quota++;
                $skipped_by_quota_names[] = $this->get_article_display_name($article);
                Log::info('ProcessArticleBatchImagesJob: cuota agotada, deteniendo procesamiento.', [
                    'user_id' => $this->user_id,
                    'counter' => $counter->counter,
                    'cuota'   => $this->google_cuota,
                ]);
                break;
            }

            if ($saved_url === null) {
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::info('ProcessArticleBatchImagesJob: ninguna query ni imagen de Google pudo utilizarse.', [
                    'article_id' => $article->id,
                    'queries'    => $search_queries,
                ]);
                continue;
            }

            /* Crear el registro de imagen asociado al artículo. */
            Image::create([
                env('IMAGE_URL_PROP_NAME', 'image_url') => $saved_url,
                'imageable_id'                          => $article->id,
                'imageable_type'                        => 'article',
            ]);

            /* Marcar artículo para sincronización con TiendaNube. */
            $article->needs_sync_with_tn = true;
            $article->timestamps         = false;
            $article->save();
            TiendaNubeSyncArticleService::add_article_to_sync($article);

            $processed++;

            if ($confidence === 'medium' || $confidence === 'low') {
                $needs_review++;
                $needs_review_items[] = [
                    'article_id' => $article->id,
                    'name'       => $this->get_article_display_name($article),
                    'image_url'  => $saved_url,
                ];
            }

            Log::info('ProcessArticleBatchImagesJob: imagen asignada exitosamente.', [
                'article_id' => $article->id,
                'confidence' => $confidence,
            ]);
        }

        /*
         * Si el corte fue por cuota agotada, sumar también los artículos que quedaron
         * sin siquiera intentarse (el break del foreach corta antes de llegar a ellos).
         */
        if ($quota_reached) {
            $remaining_ids = array_diff($this->article_ids, $attempted_article_ids);

            if (!empty($remaining_ids)) {
                $remaining_articles = Article::whereIn('id', $remaining_ids)
                    ->where('user_id', $this->user_id)
                    ->get();

                foreach ($remaining_articles as $remaining_article) {
                    $skipped_by_quota++;
                    $skipped_by_quota_names[] = $this->get_article_display_name($remaining_article);
                }
            }
        }

        /* Emitir evento Pusher con el resumen del procesamiento batch. */
        event(new ArticleBatchImagesProcessed(
            $this->user_id,
            $processed,
            $skipped,
            $skipped_names,
            $needs_review,
            $needs_review_items,
            $quota_reached,
            $skipped_by_quota,
            $skipped_by_quota_names
        ));

        Log::info('ProcessArticleBatchImagesJob: finalizado.', [
            'user_id'          => $this->user_id,
            'processed'        => $processed,
            'skipped'          => $skipped,
            'skipped_by_quota' => $skipped_by_quota,
            'quota_reached'    => $quota_reached,
            'needs_review'     => $needs_review,
        ]);
    }

    /**
     * Obtiene o crea el GeocoderCounter del día para el usuario.
     *
     * @return GeocoderCounter
     */
    private function get_or_create_counter(): GeocoderCounter
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
     * Arma la secuencia de queries de búsqueda para un artículo.
     * Réplica de prepare_auto_flow_queries en SearchImage.vue: bar_code GS1 válido, luego nombre.
     *
     * @param Article $article
     * @return array
     */
    private function get_search_queries(Article $article): array
    {
        $queries = [];

        $normalized_bar_code = '';
        if ($article->bar_code !== null && $article->bar_code !== '') {
            $normalized_bar_code = $this->normalize_bar_code((string) $article->bar_code);
        }

        if ($normalized_bar_code !== '' && $this->is_valid_product_bar_code($normalized_bar_code)) {
            $queries[] = [
                'criterion' => 'bar_code',
                'query'     => $normalized_bar_code,
            ];
        }

        if ($article->name) {
            $normalized_name = trim((string) $article->name);
            $existing_queries = array_column($queries, 'query');
            if ($normalized_name !== '' && !in_array($normalized_name, $existing_queries, true)) {
                $queries[] = [
                    'criterion' => 'name',
                    'query'     => $normalized_name,
                ];
            }
        }

        return $queries;
    }

    /**
     * Normaliza el código de barras eliminando espacios (igual que getBarCode en el SPA).
     *
     * @param string $bar_code
     * @return string
     */
    private function normalize_bar_code(string $bar_code): string
    {
        return preg_replace('/\s+/', '', $bar_code);
    }

    /**
     * Determina si el valor puede usarse como código de barras de producto en búsqueda automática.
     * Solo acepta GTIN numérico (EAN-8, UPC/EAN-12, EAN-13, GTIN-14) con dígito verificador válido.
     * Réplica de is_valid_product_bar_code en SearchImage.vue.
     *
     * @param string $normalized Valor ya normalizado sin espacios.
     * @return bool
     */
    private function is_valid_product_bar_code(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        if (!preg_match('/^\d+$/', $normalized)) {
            return false;
        }

        return $this->validate_gs1_check_digit($normalized);
    }

    /**
     * Valida el dígito verificador GS1 (módulo 10) para códigos de 8, 12, 13 o 14 dígitos.
     * Réplica de validate_gs1_check_digit en SearchImage.vue.
     *
     * @param string $code Cadena numérica incluyendo el dígito de control al final.
     * @return bool
     */
    private function validate_gs1_check_digit(string $code): bool
    {
        $len = strlen($code);
        if (!in_array($len, [8, 12, 13, 14], true)) {
            return false;
        }

        $digits      = array_map('intval', str_split($code));
        $check_digit = array_pop($digits);

        $sum = 0;
        for ($i = $len - 2; $i >= 0; $i--) {
            $position_from_right = $len - 1 - $i;
            $weight              = $position_from_right % 2 === 1 ? 3 : 1;
            $sum                += $digits[$i] * $weight;
        }

        $calculated = (10 - ($sum % 10)) % 10;

        return $calculated === $check_digit;
    }

    /**
     * Ejecuta una búsqueda de imágenes en Google Custom Search e incrementa el contador diario.
     * Usa el cliente HTTP de Laravel (Guzzle) en lugar de file_get_contents para mayor
     * compatibilidad con HTTPS en entornos Windows/WAMP.
     *
     * @param string          $query   Término de búsqueda.
     * @param GeocoderCounter $counter Contador de búsquedas del día.
     * @return array Estructura con items, api_error y total_results.
     */
    private function fetch_google_image_results(string $query, GeocoderCounter $counter): array
    {
        $counter->counter += 1;
        $counter->save();

        try {
            $http_response = $this->google_http()
                ->timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get('https://www.googleapis.com/customsearch/v1', [
                    'key'        => $this->google_api_key,
                    'cx'         => $this->cx,
                    'searchType' => 'image',
                    'q'          => $query,
                ]);
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

        return [
            'items'         => $body['items'] ?? [],
            'api_error'     => null,
            'total_results' => isset($body['searchInformation']['totalResults'])
                ? (int) $body['searchInformation']['totalResults']
                : 0,
        ];
    }

    /**
     * Descarga una imagen por URL, la recorta a cuadrado 1:1 centrado y la guarda como .webp.
     * Retorna la URL pública del archivo guardado, o null si la descarga o el procesamiento fallan.
     *
     * @param string $image_url URL de la imagen a descargar.
     * @return string|null
     */
    private function download_crop_and_save(string $image_url): ?string
    {
        try {
            $http_response = $this->google_http()
                ->timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($image_url);

            if (!$http_response->successful()) {
                return null;
            }

            $image_data = $http_response->body();
        } catch (\Exception $e) {
            return null;
        }

        if ($image_data === '' || $image_data === null) {
            return null;
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
            return null;
        }

        if (config('app.APP_ENV') == 'local') {
            return 'http://empresa.local:8000/storage/'.$filename;
        }

        if (config('app.VPS')) {
            return config('app.APP_URL').'/storage/'.$filename;
        }

        return config('app.APP_URL').'/public/storage/'.$filename;
    }

    /**
     * Evalúa la calidad de una imagen según su aspect ratio.
     * Usa los campos `image.width` e `image.height` del resultado de Google Custom Search.
     *
     * @param array $item Resultado individual de Google Custom Search.
     * @return array Con claves `url` (string) y `confidence` ('high'|'medium'|'low').
     */
    private function evaluate_image_quality(array $item): array
    {
        $width  = isset($item['image']['width'])  ? (int) $item['image']['width']  : 0;
        $height = isset($item['image']['height']) ? (int) $item['image']['height'] : 0;

        if ($width <= 0 || $height <= 0) {
            return ['url' => $item['link'], 'confidence' => 'low'];
        }

        $aspect_ratio = $width / $height;

        if ($aspect_ratio >= 0.5 && $aspect_ratio <= 2.0) {
            $confidence = 'high';
        } elseif ($aspect_ratio >= 0.3 && $aspect_ratio <= 3.0) {
            $confidence = 'medium';
        } else {
            $confidence = 'low';
        }

        return ['url' => $item['link'], 'confidence' => $confidence];
    }

    /**
     * Obtiene el nombre legible del artículo para el resumen de procesamiento.
     *
     * @param Article $article
     * @return string
     */
    private function get_article_display_name(Article $article): string
    {
        return $article->name ?: 'Artículo #'.$article->id;
    }

    /**
     * Opciones Guzzle/cURL para peticiones a Google (ver config/services.php google_custom_search).
     * En WAMP/local sin CA bundle evita cURL error 60.
     *
     * @return array<string, mixed>
     */
    private function google_http_options(): array
    {
        $ca_bundle = (string) config('services.google_custom_search.guzzle_ca_bundle', '');
        if ($ca_bundle !== '') {
            return ['verify' => $ca_bundle];
        }

        return [
            'verify' => (bool) config('services.google_custom_search.guzzle_verify', true),
        ];
    }

    /**
     * Cliente HTTP con opciones SSL del entorno para Google Custom Search y descarga de imágenes.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private function google_http()
    {
        return Http::withOptions($this->google_http_options());
    }

    /**
     * Etiqueta legible en español para el criterio de búsqueda usado en los logs.
     *
     * @param string $criterion 'bar_code' o 'name'.
     * @return string
     */
    private function get_criterion_label(string $criterion): string
    {
        if ($criterion === 'bar_code') {
            return 'código de barras';
        }

        return 'nombre';
    }

    /**
     * Registra por artículo qué criterio se usó, cuántos resultados devolvió Google
     * y el mensaje de error de la API si la petición falló.
     *
     * @param Article $article        Artículo en procesamiento.
     * @param string  $criterion      Criterio de búsqueda ('bar_code' o 'name').
     * @param string  $query          Valor enviado a Google.
     * @param array   $search_result  Respuesta estructurada de fetch_google_image_results.
     * @return void
     */
    private function log_article_search_result(Article $article, string $criterion, string $query, array $search_result): void
    {
        $display_name    = $this->get_article_display_name($article);
        $criterion_label = $this->get_criterion_label($criterion);
        $items           = $search_result['items'];
        $api_error       = $search_result['api_error'] ?? null;
        $total_results   = $search_result['total_results'];

        if ($items === null) {
            Log::info(sprintf(
                '[BatchImages] Artículo #%d "%s": búsqueda por %s ("%s") → error Google: %s',
                $article->id,
                $display_name,
                $criterion_label,
                $query,
                $api_error ?: 'desconocido'
            ));
            return;
        }

        if ($api_error) {
            Log::info(sprintf(
                '[BatchImages] Artículo #%d "%s": búsqueda por %s ("%s") → %d items, error Google: %s (totalResults: %s)',
                $article->id,
                $display_name,
                $criterion_label,
                $query,
                count($items),
                $api_error,
                $total_results !== null ? (string) $total_results : '?'
            ));
            return;
        }

        Log::info(sprintf(
            '[BatchImages] Artículo #%d "%s": búsqueda por %s ("%s") → %d resultados (totalResults Google: %d)',
            $article->id,
            $display_name,
            $criterion_label,
            $query,
            count($items),
            $total_results !== null ? $total_results : 0
        ));
    }

    /**
     * Registra el fallback de código de barras a nombre antes de ejecutar la segunda búsqueda.
     *
     * @param Article $article Artículo en procesamiento.
     * @param string  $query   Nombre del artículo que se usará como query.
     * @return void
     */
    private function log_fallback_to_name_search(Article $article, string $query): void
    {
        Log::info(sprintf(
            '[BatchImages] Artículo #%d "%s": sin éxito con código de barras, ahora se busca por nombre ("%s")',
            $article->id,
            $this->get_article_display_name($article),
            $query
        ));
    }
}
