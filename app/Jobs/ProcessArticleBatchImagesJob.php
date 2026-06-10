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
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;

class ProcessArticleBatchImagesJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

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
        $processed         = 0;
        $skipped           = 0;
        $skipped_names     = [];
        $needs_review      = 0;
        $needs_review_names = [];

        $counter = $this->get_or_create_counter();

        foreach ($this->article_ids as $article_id) {
            $article = Article::where('id', $article_id)
                ->where('user_id', $this->user_id)
                ->first();

            if (!$article) {
                continue;
            }

            /* Verificar cuota antes de cada búsqueda; terminar loop si se agotó. */
            if ($counter->counter >= $this->google_cuota) {
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::info('ProcessArticleBatchImagesJob: cuota agotada, deteniendo procesamiento.', [
                    'user_id' => $this->user_id,
                    'counter' => $counter->counter,
                    'cuota'   => $this->google_cuota,
                ]);
                break;
            }

            /* Determinar query de búsqueda: código GS1 válido o nombre del artículo. */
            $query = $this->get_search_query($article);
            if (!$query) {
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::info('ProcessArticleBatchImagesJob: artículo sin query de búsqueda.', ['article_id' => $article->id]);
                continue;
            }

            /* Llamar a Google Custom Search API. */
            $search_url = 'https://www.googleapis.com/customsearch/v1?'.http_build_query([
                'key'        => $this->google_api_key,
                'cx'         => $this->cx,
                'searchType' => 'image',
                'q'          => $query,
            ]);

            $search_context = stream_context_create([
                'http' => [
                    'timeout'       => 10,
                    'header'        => 'User-Agent: Mozilla/5.0',
                    'ignore_errors' => true,
                ],
            ]);

            $response_raw = @file_get_contents($search_url, false, $search_context);

            /* Incrementar el contador de búsquedas del día. */
            $counter->counter += 1;
            $counter->save();
            Log::info('ProcessArticleBatchImagesJob: búsqueda ejecutada.', ['counter' => $counter->counter]);

            if ($response_raw === false) {
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::warning('ProcessArticleBatchImagesJob: error al llamar a Google API.', ['article_id' => $article->id]);
                continue;
            }

            $response = json_decode($response_raw, true);
            $items    = $response['items'] ?? [];

            if (empty($items)) {
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::info('ProcessArticleBatchImagesJob: sin resultados de imágenes.', ['article_id' => $article->id]);
                continue;
            }

            /* Evaluar calidad de la primera imagen según su aspect ratio. */
            $quality    = $this->evaluate_image_quality($items[0]);
            $image_url  = $quality['url'];
            $confidence = $quality['confidence'];

            /* Descargar imagen con timeout y User-Agent estándar. */
            $download_context = stream_context_create([
                'http' => [
                    'timeout' => 8,
                    'header'  => 'User-Agent: Mozilla/5.0',
                ],
            ]);
            $image_data = @file_get_contents($image_url, false, $download_context);

            if ($image_data === false) {
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::warning('ProcessArticleBatchImagesJob: fallo al descargar imagen.', [
                    'article_id' => $article->id,
                    'url'        => $image_url,
                ]);
                continue;
            }

            /* Recortar a cuadrado 1:1 (el más grande posible, centrado) y guardar como .webp. */
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
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::error('ProcessArticleBatchImagesJob: error al procesar imagen.', [
                    'article_id' => $article->id,
                    'message'    => $e->getMessage(),
                ]);
                continue;
            }

            /* Construir URL pública respetando entorno (local / VPS / producción). */
            if (config('app.APP_ENV') == 'local') {
                $saved_url = 'http://empresa.local:8000/storage/'.$filename;
            } elseif (config('app.VPS')) {
                $saved_url = config('app.APP_URL').'/storage/'.$filename;
            } else {
                $saved_url = config('app.APP_URL').'/public/storage/'.$filename;
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
                $needs_review_names[] = $this->get_article_display_name($article);
            }

            Log::info('ProcessArticleBatchImagesJob: imagen asignada exitosamente.', [
                'article_id' => $article->id,
                'confidence' => $confidence,
            ]);
        }

        /* Emitir evento Pusher con el resumen del procesamiento batch. */
        event(new ArticleBatchImagesProcessed(
            $this->user_id,
            $processed,
            $skipped,
            $skipped_names,
            $needs_review,
            $needs_review_names
        ));

        Log::info('ProcessArticleBatchImagesJob: finalizado.', [
            'user_id'    => $this->user_id,
            'processed'  => $processed,
            'skipped'    => $skipped,
            'needs_review' => $needs_review,
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
     * Determina la query de búsqueda para un artículo.
     * Prioriza el código de barras GS1 válido; usa el nombre como fallback.
     *
     * @param Article $article
     * @return string|null
     */
    private function get_search_query(Article $article): ?string
    {
        if (!empty($article->bar_code) && $this->is_valid_gs1((string) $article->bar_code)) {
            return (string) $article->bar_code;
        }

        return $article->name ?: null;
    }

    /**
     * Valida si una cadena es un GTIN GS1 con dígito verificador módulo 10 correcto.
     * Solo acepta GTIN de 8, 12, 13 o 14 dígitos numéricos.
     *
     * @param string $barcode
     * @return bool
     */
    private function is_valid_gs1(string $barcode): bool
    {
        if (!preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $barcode)) {
            return false;
        }

        $digits      = array_map('intval', str_split($barcode));
        $check_digit = array_pop($digits);

        $sum        = 0;
        $multiplier = 3;
        foreach (array_reverse($digits) as $digit) {
            $sum       += $digit * $multiplier;
            $multiplier = $multiplier === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10 === $check_digit;
    }

    /**
     * Evalúa la calidad de una imagen según su aspect ratio.
     * Usa los campos `image.width` e `image.height` del resultado de Google Custom Search.
     *
     * @param array $item Primer resultado de Google Custom Search.
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
}
