<?php

namespace App\Jobs;

use App\Events\ArticleBatchDescriptionsProcessed;
use App\Models\Article;
use App\Models\Description;
use App\Models\GeocoderCounter;
use App\Services\ArticleDescriptionAiService;
use App\Services\ProductInfoLookupService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job masivo de generacion de descripciones inteligentes. Replica la estructura de
 * ProcessArticleBatchImagesJob, con dos diferencias de fondo respecto al flujo de imagenes:
 *
 * 1. Nunca pisa trabajo humano: un articulo con descripciones cargadas a mano se saltea
 *    (skipped_existing), salvo que se pida overwrite explicito (que solo borra las
 *    descripciones generadas por IA, nunca las humanas).
 * 2. La baja confianza no se publica sola: una descripcion con confianza 'low' se guarda
 *    pero NO se sincroniza a TiendaNube hasta que una persona la revise (needs_review, ver
 *    ArticleDescriptionAiController::approve()).
 *
 * IMPORTANTE (bloqueo conocido, ver resumen del prompt 369): este job usa
 * Description::human()/scope ai_generated/ai_confidence/ai_sources, que dependen de la
 * migracion del prompt 366. Si esa migracion no se aplico, este job falla en tiempo de
 * ejecucion.
 */
class ProcessArticleBatchDescriptionsJob implements ShouldQueue
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

    /** @var bool Si es true, se borran (y regeneran) las descripciones ya generadas por IA. Nunca afecta a las humanas. */
    protected $overwrite;

    /**
     * @param array  $article_ids    IDs de los artículos a procesar.
     * @param int    $user_id        ID del usuario dueño.
     * @param string $google_api_key Clave de Google Custom Search API.
     * @param string $cx             ID del motor de búsqueda personalizado.
     * @param int    $google_cuota   Cuota diaria máxima del owner.
     * @param bool   $overwrite      Si se debe sobrescribir descripciones ya generadas por IA.
     */
    public function __construct(
        array $article_ids,
        $user_id,
        $google_api_key,
        $cx,
        $google_cuota,
        $overwrite = false
    ) {
        $this->article_ids    = $article_ids;
        $this->user_id        = (int) $user_id;
        $this->google_api_key = (string) $google_api_key;
        $this->cx             = (string) $cx;
        $this->google_cuota   = (int) $google_cuota;
        $this->overwrite      = (bool) $overwrite;
    }

    /**
     * Procesa cada artículo: recolecta evidencia, sintetiza la descripción con IA, y la
     * guarda respetando las dos reglas de fondo (no pisar trabajo humano, no publicar sola
     * la baja confianza). Emite ArticleBatchDescriptionsProcessed al finalizar con el resumen.
     *
     * @return void
     */
    public function handle()
    {
        // Contadores del resumen final (mismo patrón que ProcessArticleBatchImagesJob).
        $processed              = 0;
        $skipped                = 0;
        $skipped_names          = [];
        $skipped_existing       = 0;
        $skipped_existing_names = [];
        $needs_review           = 0;
        $needs_review_items     = [];
        $quota_reached          = false;
        $skipped_by_quota       = 0;
        $skipped_by_quota_names = [];
        // IDs efectivamente intentados (para poder calcular, al final, los que quedaron sin tocar por cuota).
        $attempted_article_ids  = [];

        $counter        = $this->get_or_create_counter();
        $lookup_service = new ProductInfoLookupService(
            $this->user_id,
            $this->google_api_key,
            $this->cx,
            $this->google_cuota
        );
        $ai_service = new ArticleDescriptionAiService();

        Log::info(sprintf(
            '[DescripcionesIA] Inicio batch (%d artículos), overwrite=%s',
            count($this->article_ids),
            $this->overwrite ? 'true' : 'false'
        ));

        foreach ($this->article_ids as $article_id) {
            $attempted_article_ids[] = $article_id;

            $article = Article::where('id', $article_id)
                ->where('user_id', $this->user_id)
                ->with('brand')
                ->first();

            if (!$article) {
                continue;
            }

            /*
             * 1. Nunca pisar trabajo humano. Si el artículo ya tiene alguna descripción cargada
             * a mano (no generada por IA) y no se pidió overwrite, se saltea por completo.
             */
            if (!$this->overwrite && $article->descriptions()->human()->exists()) {
                $skipped_existing++;
                $skipped_existing_names[] = $this->get_article_display_name($article);
                Log::info('[DescripcionesIA] Artículo con descripciones humanas, se saltea.', ['article_id' => $article->id]);
                continue;
            }

            /* 2. Cuota de Google agotada antes de intentar: se corta el batch acá. */
            if ($counter->counter >= $this->google_cuota) {
                $quota_reached = true;
                $skipped_by_quota++;
                $skipped_by_quota_names[] = $this->get_article_display_name($article);
                Log::info('[DescripcionesIA] Cuota agotada, deteniendo procesamiento.', [
                    'user_id' => $this->user_id,
                    'counter' => $counter->counter,
                    'cuota'   => $this->google_cuota,
                ]);
                break;
            }

            /* 3. Recolección de evidencia verificable (UPCitemdb / Google Custom Search). */
            $lookup = $lookup_service->lookup($article, $counter);

            if ($lookup['quota_exceeded']) {
                $quota_reached = true;
                $skipped_by_quota++;
                $skipped_by_quota_names[] = $this->get_article_display_name($article);
                Log::info('[DescripcionesIA] Cuota agotada durante la búsqueda, deteniendo procesamiento.', [
                    'user_id' => $this->user_id,
                    'counter' => $counter->counter,
                    'cuota'   => $this->google_cuota,
                ]);
                break;
            }

            if (!$lookup['found']) {
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::info('[DescripcionesIA] Sin evidencia para el artículo.', ['article_id' => $article->id]);
                continue;
            }

            /* 4. Síntesis: Claude arma la ficha SOLO a partir de la evidencia recolectada. */
            $result = $ai_service->generate($article, $lookup);

            if (!$result['found']) {
                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                Log::info('[DescripcionesIA] Sin descripción.', [
                    'article_id' => $article->id,
                    'reason'     => $result['reason'],
                ]);
                continue;
            }

            /*
             * 5. Si se pidió overwrite, se borran SOLO las descripciones generadas por IA
             * previas de este artículo. Las cargadas a mano por una persona nunca se tocan.
             */
            if ($this->overwrite) {
                Description::where('article_id', $article->id)
                    ->where('ai_generated', true)
                    ->delete();
            }

            $description_ids = [];
            $first_content    = '';

            foreach ($result['sections'] as $section) {
                $description = Description::create([
                    'title'         => $section['title'],
                    'content'       => $section['content'],
                    'article_id'    => $article->id,
                    'ai_generated'  => true,
                    'ai_confidence' => $result['confidence'],
                    'ai_sources'    => $result['sources'],
                    // ai_reviewed_at queda NULL: nadie la revisó todavía.
                ]);

                $description_ids[] = $description->id;

                if ($first_content === '') {
                    $first_content = $section['content'];
                }
            }

            /*
             * needs_review y processed son EXCLUYENTES: acá la baja confianza significa "no
             * publicado todavía", no "publicado con dudas" (a diferencia del batch de imágenes).
             */
            if ($result['confidence'] === 'low') {
                $needs_review++;
                $needs_review_items[] = [
                    'article_id'      => $article->id,
                    'name'            => $this->get_article_display_name($article),
                    'description_ids' => $description_ids,
                    'preview'         => mb_substr($first_content, 0, 140),
                ];
                // NO se sincroniza a TiendaNube: no se publica sin revisión humana.
            } else {
                $processed++;
                if (env('USA_TIENDA_NUBE', false)) {
                    dispatch(new ProcessSyncArticleDescriptionTiendaNube($article));
                }
            }

            Log::info('[DescripcionesIA] Descripción generada.', [
                'article_id' => $article->id,
                'confidence' => $result['confidence'],
            ]);
        }

        /*
         * Si el corte fue por cuota agotada, sumar también los artículos que quedaron sin
         * siquiera intentarse (el break del foreach corta antes de llegar a ellos).
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
        event(new ArticleBatchDescriptionsProcessed(
            $this->user_id,
            $processed,
            $skipped,
            $skipped_names,
            $skipped_existing,
            $skipped_existing_names,
            $needs_review,
            $needs_review_items,
            $quota_reached,
            $skipped_by_quota,
            $skipped_by_quota_names
        ));

        Log::info('[DescripcionesIA] Batch finalizado.', [
            'user_id'          => $this->user_id,
            'processed'        => $processed,
            'skipped'          => $skipped,
            'skipped_existing' => $skipped_existing,
            'needs_review'     => $needs_review,
            'skipped_by_quota' => $skipped_by_quota,
            'quota_reached'    => $quota_reached,
        ]);
    }

    /**
     * Obtiene o crea el GeocoderCounter del día para el usuario. Mismo contador diario que
     * comparte el flujo de imágenes inteligentes.
     *
     * @return GeocoderCounter
     */
    private function get_or_create_counter()
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
     * Obtiene el nombre legible del artículo para el resumen de procesamiento.
     *
     * @param Article $article
     * @return string
     */
    private function get_article_display_name(Article $article)
    {
        return $article->name ?: 'Artículo #'.$article->id;
    }
}
