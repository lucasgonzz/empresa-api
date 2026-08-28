<?php

namespace App\Jobs;

use App\Events\ArticleBatchImagesProcessed;
use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Models\Article;
use App\Models\ArticleImageSearchAttempt;
use App\Models\GeocoderCounter;
use App\Models\Image;
use App\Services\ArticleImageValidationService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use App\Services\Traits\GoogleSearchHelpers;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class ProcessArticleBatchImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, GoogleSearchHelpers;

    /** @var int Intentos máximos antes de marcar el job como fallido. */
    public $tries = 1;

    /**
     * @var int Tiempo máximo de ejecución en segundos (30 minutos). Subido de 600 a 1800
     * (grupo 201, prompt 03): con validación por visión IA de cada candidata, una corrida de
     * 100 artículos puede superar los 10 minutos previos.
     */
    public $timeout = 1800;

    /**
     * @var int Máximo de imágenes candidatas de Google que se evalúan por query. El resto
     * queda registrado en `candidates` con outcome `not_evaluated`, sin gastar descarga ni
     * llamada a la IA (evita corridas de media hora con muchos artículos).
     */
    const MAX_CANDIDATES_PER_QUERY = 3;

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
     * @var string UUID de la corrida, cuando lo impone quien despacha el job. Vacío significa
     * "generalo vos", que es como se comportaba antes de que el controlador lo devolviera.
     *
     * 🔴 El default `''` no es decorativo: un job que quedó ENCOLADO antes de este deploy se
     * deserializa sin esta property, y sin default PHP la dejaría en null. Ver el guard de
     * handle(), que por el mismo motivo pregunta por veracidad y no por `!== ''`.
     */
    protected $batch_uuid = '';

    /**
     * @param array  $article_ids    IDs de los artículos a procesar.
     * @param int    $user_id        ID del usuario dueño.
     * @param string $google_api_key Clave de Google Custom Search API.
     * @param string $cx             ID del motor de búsqueda personalizado.
     * @param int    $google_cuota   Cuota diaria máxima del owner.
     * @param string $batch_uuid     UUID de la corrida. Opcional: vacío lo genera handle().
     */
    public function __construct(
        array $article_ids,
        int $user_id,
        string $google_api_key,
        string $cx,
        int $google_cuota,
        $batch_uuid = ''
    ) {
        $this->article_ids   = $article_ids;
        $this->user_id       = $user_id;
        $this->google_api_key = $google_api_key;
        $this->cx            = $cx;
        $this->google_cuota  = $google_cuota;
        $this->batch_uuid    = (string) $batch_uuid;
    }

    /**
     * Procesa cada artículo: busca imagen en Google, valida cada candidata (prefiltro de texto +
     * visión IA vía ArticleImageValidationService), descarga (con fallback al thumbnail de
     * Google si el link directo falla), recorta y guarda. Registra un diagnóstico completo por
     * artículo + criterio en `article_image_search_attempts` (ArticleImageSearchAttempt) y emite
     * el evento ArticleBatchImagesProcessed al finalizar con el resumen.
     *
     * @return void
     */
    public function handle()
    {
        /*
         * UUID que agrupa todas las filas de diagnóstico de esta corrida del job, y que además
         * viaja en el payload del broadcast.
         *
         * 🔴 Se respeta el que impone quien despacha, y solo se genera uno si no vino. El motivo
         * no es de estilo: el canal de Pusher se llama `article_batch_images.{owner_id}` y es
         * PÚBLICO, así que dos instancias con el mismo id de owner sobre la misma app de Pusher
         * comparten canal literalmente. Sin un uuid que el frontend conozca de antemano, cada
         * pestaña acepta el PRIMER evento que llega —sea suyo o no— y se da de baja del canal,
         * con lo que pierde el propio. Medido el 28/8/2026 entre dos instancias de demo.
         * Que el controlador lo genere y lo devuelva en el POST es lo que le permite al frontend
         * filtrar por corrida. Vacío = comportamiento viejo, para cualquier despacho que no lo pase.
         */
        /*
         * 🔴 Se pregunta por VERACIDAD y no por `!== ''`. Un job encolado antes de este deploy se
         * deserializa sin la property `batch_uuid`: con `!== ''` un null pasaba el guard, las
         * escrituras de diagnóstico quedaban sin agrupar y al final `new ArticleBatchImagesProcessed(
         * ..., string $batch_uuid, ...)` reventaba con TypeError en PHP 7.4 — después de haber
         * quemado la cuota de Google y validado cada imagen con IA, que es lo caro de la corrida.
         */
        $batch_uuid = $this->batch_uuid ? (string) $this->batch_uuid : (string) Str::uuid();

        // Instancia única del validador por IA: el contador de llamadas (max_calls_batch) tiene
        // que ser por corrida completa, no por artículo.
        $validation_service = new ArticleImageValidationService();

        $processed              = 0;
        $skipped                = 0;
        $skipped_names          = [];
        $skipped_items          = [];
        $skipped_by_quota       = 0;
        $skipped_by_quota_names = [];
        $quota_reached          = false;
        $attempted_article_ids  = [];
        $needs_review           = 0;
        $needs_review_items     = [];

        $counter = $this->get_or_create_counter();

        // Purga los intentos viejos del usuario para que la tabla de diagnóstico no crezca sin techo.
        ArticleImageSearchAttempt::purge_old($this->user_id, 30);

        Log::info(sprintf(
            '[BatchImages] Inicio batch (%d artículos), api_key ...%s, batch_uuid %s',
            count($this->article_ids),
            substr($this->google_api_key, -8),
            $batch_uuid
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

                // Fila de diagnóstico: este artículo no llegó ni a armar las queries de búsqueda
                // porque la cuota diaria ya estaba agotada (grupo 217, prompt 02).
                $this->write_attempt(
                    $batch_uuid,
                    $article,
                    'none',
                    1,
                    '',
                    null,
                    null,
                    null,
                    ArticleImageSearchAttempt::OUTCOME_QUOTA,
                    'No se llegó a buscar: se había agotado la cuota diaria de búsquedas de Google.',
                    null
                );

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
                $detail = 'El artículo no tiene código de barras válido ni nombre, así que no había con qué buscar.';

                $this->write_attempt(
                    $batch_uuid,
                    $article,
                    'none',
                    1,
                    '',
                    null,
                    null,
                    null,
                    ArticleImageSearchAttempt::OUTCOME_NO_QUERY,
                    $detail,
                    null
                );

                $skipped++;
                $skipped_names[] = $this->get_article_display_name($article);
                $skipped_items[] = [
                    'article_id' => $article->id,
                    'name'       => $this->get_article_display_name($article),
                    'summary'    => $detail,
                ];
                Log::info('ProcessArticleBatchImagesJob: artículo sin query de búsqueda.', ['article_id' => $article->id]);
                continue;
            }

            $saved_url          = null;
            // Modelo de ArticleImageSearchAttempt creado al asignar la imagen (outcome ASSIGNED),
            // para poder actualizarlo más abajo con needs_review una vez calculado el veredicto
            // de revisión manual (grupo 217, prompt 02).
            $assigned_attempt   = null;
            $ratio_confidence   = null;
            $ai_confidence      = null;
            $ai_evaluated       = null;
            $quota_exceeded_mid = false;
            $article_details    = [];

            foreach ($search_queries as $query_index => $search_query) {
                $criterion       = $search_query['criterion'];
                $query           = $search_query['query'];
                $criterion_order = $query_index + 1;
                $criterion_label = $this->get_criterion_label($criterion);
                $query_suffix    = $this->get_query_display_suffix($criterion, $query);

                if ($counter->counter >= $this->google_cuota) {
                    $detail = 'No se llegó a buscar: se agotó la cuota diaria de búsquedas de Google.';

                    $this->write_attempt(
                        $batch_uuid,
                        $article,
                        $criterion,
                        $criterion_order,
                        $query,
                        null,
                        null,
                        null,
                        ArticleImageSearchAttempt::OUTCOME_QUOTA,
                        $detail,
                        null
                    );
                    $article_details[] = $detail;

                    $quota_exceeded_mid = true;
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

                $items                = $search_result['items'];
                $api_error            = $search_result['api_error'];
                $google_total_results = $search_result['total_results'];
                $google_items_count   = $items !== null ? count($items) : null;

                if ($items === null) {
                    $detail = sprintf(
                        'Se buscó por %s%s y Google devolvió un error: %s.',
                        $criterion_label,
                        $query_suffix,
                        $api_error ?: 'desconocido'
                    );

                    $this->write_attempt(
                        $batch_uuid,
                        $article,
                        $criterion,
                        $criterion_order,
                        $query,
                        $google_items_count,
                        $google_total_results,
                        $api_error,
                        ArticleImageSearchAttempt::OUTCOME_API_ERROR,
                        $detail,
                        null
                    );
                    $article_details[] = $detail;
                    continue;
                }

                if (empty($items)) {
                    $detail = sprintf(
                        'Se buscó por %s%s y Google no devolvió ninguna imagen.',
                        $criterion_label,
                        $query_suffix
                    );

                    $this->write_attempt(
                        $batch_uuid,
                        $article,
                        $criterion,
                        $criterion_order,
                        $query,
                        $google_items_count,
                        $google_total_results,
                        $api_error,
                        ArticleImageSearchAttempt::OUTCOME_NO_RESULTS,
                        $detail,
                        null
                    );
                    $article_details[] = $detail;
                    continue;
                }

                /*
                 * Pipeline por candidata (máximo MAX_CANDIDATES_PER_QUERY, en orden):
                 * 1) prefiltro de texto gratuito, 2) descarga con fallback a thumbnail,
                 * 3) validación por visión IA. Se registra el resultado de cada paso.
                 */
                $candidates_log = [];
                $resolved       = false;
                $position       = 0;

                foreach ($items as $item) {
                    $position++;

                    /* Ya se resolvió el artículo con una candidata anterior, o se alcanzó el tope. */
                    if ($resolved || $position > self::MAX_CANDIDATES_PER_QUERY) {
                        $candidates_log[] = $this->build_candidate_entry(
                            $position,
                            $item,
                            ArticleImageSearchAttempt::CANDIDATE_OUTCOME_NOT_EVALUATED,
                            'No se llegó a evaluar: ya se había resuelto el artículo o se alcanzó el tope de candidatas.'
                        );
                        continue;
                    }

                    /* Paso 1: prefiltro de texto, gratuito (sin descarga ni llamada a IA). */
                    $prefilter = $validation_service->prefilter($item);
                    if ($prefilter['rejected']) {
                        $candidates_log[] = $this->build_candidate_entry(
                            $position,
                            $item,
                            ArticleImageSearchAttempt::CANDIDATE_OUTCOME_REJECTED_BY_PREFILTER,
                            $prefilter['reason']
                        );
                        continue;
                    }

                    /* Paso 2: descarga del link directo; si falla, reintentar con el thumbnail de Google. */
                    $quality        = $this->evaluate_image_quality($item);
                    $download       = $this->download_crop_and_save($quality['url']);
                    $used_thumbnail = false;

                    if ($download['url'] === null && !empty($item['image']['thumbnailLink'])) {
                        $download       = $this->download_crop_and_save($item['image']['thumbnailLink']);
                        $used_thumbnail = true;
                    }

                    if ($download['url'] === null) {
                        $candidate_extra = ['used_thumbnail' => $used_thumbnail];

                        if ($download['failure'] === 'format') {
                            $candidates_log[] = $this->build_candidate_entry(
                                $position,
                                $item,
                                ArticleImageSearchAttempt::CANDIDATE_OUTCOME_NOT_PROCESSABLE,
                                'Se descargó el archivo pero no es una imagen que el sistema pueda procesar.',
                                $candidate_extra
                            );
                            continue;
                        }

                        if ($download['http_status'] !== null) {
                            $candidate_extra['http_status'] = $download['http_status'];
                        }

                        $candidates_log[] = $this->build_candidate_entry(
                            $position,
                            $item,
                            ArticleImageSearchAttempt::CANDIDATE_OUTCOME_DOWNLOAD_FAILED,
                            'La imagen existe pero el sitio no permitió descargarla desde el servidor.',
                            $candidate_extra
                        );
                        continue;
                    }

                    /* Paso 3: validación por visión IA sobre el binario recién guardado en disco. */
                    $saved_file_path = storage_path('app/public/'.basename($download['url']));
                    $image_binary    = file_exists($saved_file_path) ? file_get_contents($saved_file_path) : false;

                    if ($image_binary === false || $image_binary === '') {
                        if (file_exists($saved_file_path)) {
                            unlink($saved_file_path);
                        }

                        $candidates_log[] = $this->build_candidate_entry(
                            $position,
                            $item,
                            ArticleImageSearchAttempt::CANDIDATE_OUTCOME_NOT_PROCESSABLE,
                            'Se descargó el archivo pero no es una imagen que el sistema pueda procesar.',
                            ['used_thumbnail' => $used_thumbnail]
                        );
                        continue;
                    }

                    $validation = $validation_service->validate($image_binary, $article);

                    if (!$validation['accepted']) {
                        /* La imagen se descartó por IA: borrar el archivo ya guardado para no llenar storage. */
                        if (file_exists($saved_file_path)) {
                            unlink($saved_file_path);
                        }

                        $candidates_log[] = $this->build_candidate_entry(
                            $position,
                            $item,
                            ArticleImageSearchAttempt::CANDIDATE_OUTCOME_REJECTED_BY_AI,
                            $validation['reason'],
                            [
                                'used_thumbnail' => $used_thumbnail,
                                'ai_type'        => $validation['type'],
                                'ai_confidence'  => $validation['confidence'],
                            ]
                        );
                        continue;
                    }

                    /* Candidata aceptada: se asigna. Las restantes quedan registradas como not_evaluated. */
                    $candidates_log[] = $this->build_candidate_entry(
                        $position,
                        $item,
                        ArticleImageSearchAttempt::CANDIDATE_OUTCOME_ASSIGNED,
                        $validation['reason'],
                        [
                            'used_thumbnail' => $used_thumbnail,
                            'ai_type'        => $validation['type'],
                            'ai_confidence'  => $validation['confidence'],
                        ]
                    );

                    $saved_url        = $download['url'];
                    $ratio_confidence = $quality['confidence'];
                    $ai_confidence    = $validation['confidence'];
                    $ai_evaluated     = $validation['evaluated'];
                    $resolved         = true;
                }

                if ($resolved) {
                    $detail = sprintf('Se encontró y asignó una imagen buscando por %s.', $criterion_label);

                    // Se guarda la referencia al modelo creado: $saved_url ya está disponible acá,
                    // pero needs_review todavía no (se calcula después, fuera de este foreach), así
                    // que esta fila se actualiza más adelante (ver tarea 05 / grupo 217, prompt 02).
                    $assigned_attempt = $this->write_attempt(
                        $batch_uuid,
                        $article,
                        $criterion,
                        $criterion_order,
                        $query,
                        $google_items_count,
                        $google_total_results,
                        $api_error,
                        ArticleImageSearchAttempt::OUTCOME_ASSIGNED,
                        $detail,
                        $candidates_log,
                        $saved_url
                    );
                    $article_details[] = $detail;
                    break;
                }

                $detail = sprintf(
                    'Se buscó por %s%s y Google devolvió %d imágenes, pero ninguna se pudo usar.',
                    $criterion_label,
                    $query_suffix,
                    count($items)
                );

                $this->write_attempt(
                    $batch_uuid,
                    $article,
                    $criterion,
                    $criterion_order,
                    $query,
                    $google_items_count,
                    $google_total_results,
                    $api_error,
                    ArticleImageSearchAttempt::OUTCOME_ALL_CANDIDATES_REJECTED,
                    $detail,
                    $candidates_log
                );
                $article_details[] = $detail;
            }

            if ($quota_exceeded_mid && $saved_url === null) {
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
                $skipped_items[] = [
                    'article_id' => $article->id,
                    'name'       => $this->get_article_display_name($article),
                    'summary'    => implode(' ', $article_details),
                ];
                Log::info('ProcessArticleBatchImagesJob: ninguna query ni imagen de Google pudo utilizarse.', [
                    'article_id' => $article->id,
                    'queries'    => $search_queries,
                ]);
                continue;
            }

            /* Crear el registro de imagen asociado al artículo. */
            Image::create([
                'hosting_url'     => $saved_url,
                'imageable_id'    => $article->id,
                'imageable_type'  => 'article',
            ]);

            /* Marcar artículo para sincronización con TiendaNube. */
            $article->needs_sync_with_tn = true;
            $article->timestamps         = false;
            $article->save();
            TiendaNubeSyncArticleService::add_article_to_sync($article);

            $processed++;

            /*
             * Confianza final = la menor entre la del aspect ratio y la de la IA (low < medium <
             * high). Si la IA no pudo evaluar la imagen, el artículo va siempre a revisión
             * manual, sea cual sea el aspect ratio.
             */
            if ($ai_evaluated === false) {
                $goes_to_review = true;
            } else {
                $final_confidence = $this->confidence_rank($ai_confidence) <= $this->confidence_rank($ratio_confidence)
                    ? $ai_confidence
                    : $ratio_confidence;
                $goes_to_review = ($final_confidence === 'medium' || $final_confidence === 'low');
            }

            if ($goes_to_review) {
                $needs_review++;
                $needs_review_items[] = [
                    'article_id' => $article->id,
                    'name'       => $this->get_article_display_name($article),
                    'image_url'  => $saved_url,
                ];

                // Update posterior (no valor del create()): el veredicto de revisión manual recién
                // se conoce acá, después de cerrar el foreach de queries, así que la fila de
                // diagnóstico ya creada con outcome ASSIGNED se actualiza ahora (grupo 217, prompt 02).
                if ($assigned_attempt !== null) {
                    $assigned_attempt->needs_review = true;
                    $assigned_attempt->save();
                }
            }

            Log::info('ProcessArticleBatchImagesJob: imagen asignada exitosamente.', [
                'article_id'       => $article->id,
                'ratio_confidence' => $ratio_confidence,
                'ai_confidence'    => $ai_confidence,
                'ai_evaluated'     => $ai_evaluated,
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

                // Tope de filas de diagnóstico para los artículos que ni se llegaron a tocar
                // (grupo 217, prompt 02): escribir un attempt por artículo acá puede ser cientos
                // de inserts en corridas grandes, así que se limita a los primeros 500. No es un
                // bug: los contadores de abajo (skipped_by_quota / skipped_by_quota_names) se
                // siguen sumando para TODOS los artículos restantes, el tope es solo para no
                // saturar la tabla de diagnóstico con filas redundantes.
                $diagnostic_rows_limit = 500;
                $diagnostic_rows_written = 0;

                foreach ($remaining_articles as $remaining_article) {
                    $skipped_by_quota++;
                    $skipped_by_quota_names[] = $this->get_article_display_name($remaining_article);

                    if ($diagnostic_rows_written < $diagnostic_rows_limit) {
                        $this->write_attempt(
                            $batch_uuid,
                            $remaining_article,
                            'none',
                            1,
                            '',
                            null,
                            null,
                            null,
                            ArticleImageSearchAttempt::OUTCOME_QUOTA,
                            'No se llegó a buscar: se había agotado la cuota diaria de búsquedas de Google.',
                            null
                        );
                        $diagnostic_rows_written++;
                    }
                }

                if ($remaining_articles->count() > $diagnostic_rows_limit) {
                    Log::info('ProcessArticleBatchImagesJob: se alcanzó el tope de filas de diagnóstico para artículos sin tocar por cuota.', [
                        'user_id'                  => $this->user_id,
                        'batch_uuid'                => $batch_uuid,
                        'remaining_total'           => $remaining_articles->count(),
                        'diagnostic_rows_written'   => $diagnostic_rows_written,
                        'diagnostic_rows_sin_fila'  => $remaining_articles->count() - $diagnostic_rows_written,
                    ]);
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
            $skipped_by_quota_names,
            $batch_uuid,
            $skipped_items
        ));

        Log::info('ProcessArticleBatchImagesJob: finalizado.', [
            'user_id'             => $this->user_id,
            'batch_uuid'          => $batch_uuid,
            'processed'           => $processed,
            'skipped'             => $skipped,
            'skipped_by_quota'    => $skipped_by_quota,
            'quota_reached'       => $quota_reached,
            'needs_review'        => $needs_review,
            // Cantidad real de filas de diagnóstico escritas para esta corrida: sirve para
            // verificar de una que el diagnóstico quedó completo sin tener que abrir la base
            // (grupo 217, prompt 02).
            'attempts_registrados' => ArticleImageSearchAttempt::where('batch_uuid', $batch_uuid)->count(),
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
    private function consumir_cuota(GeocoderCounter $counter)
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
    private function download_crop_and_save(string $image_url): array
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

    /**
     * Evalúa la calidad de una imagen según su aspect ratio.
     * Usa los campos `image.width` e `image.height` del resultado de Google Custom Search.
     *
     * Se conserva tal cual (grupo 201, prompt 03: no se cambia esta lógica), pero deja de ser
     * la única señal que decide si una imagen se asigna: ahora convive con el prefiltro de texto
     * y la validación por visión IA de ArticleImageValidationService.
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
     * Convierte una confianza ('high'|'medium'|'low') en un ranking numérico para poder
     * quedarse con la menor entre dos confianzas (regla 06 del prompt 03: la confianza final
     * de una imagen asignada es la menor entre el aspect ratio y la de la IA).
     *
     * @param string $confidence 'high'|'medium'|'low'.
     * @return int 3 para high, 2 para medium, 1 para low (o cualquier otro valor inesperado).
     */
    private function confidence_rank(string $confidence): int
    {
        if ($confidence === 'high') {
            return 3;
        }

        if ($confidence === 'medium') {
            return 2;
        }

        return 1;
    }

    /**
     * Arma una fila del array `candidates` que se guarda en ArticleImageSearchAttempt para una
     * imagen candidata de Google Custom Search, con los datos comunes (posición, urls, título,
     * dimensiones) más el outcome/motivo del paso del pipeline en el que se descartó o asignó.
     *
     * @param int    $position Posición (1-based) de la candidata dentro de los items de Google.
     * @param array  $item     Item individual de Google Custom Search.
     * @param string $outcome  Uno de ArticleImageSearchAttempt::CANDIDATE_OUTCOME_*.
     * @param string $reason   Frase corta en castellano, lista para mostrar en pantalla.
     * @param array  $extra    Claves opcionales adicionales (http_status, used_thumbnail, ai_type, ai_confidence).
     * @return array
     */
    private function build_candidate_entry(int $position, array $item, string $outcome, string $reason, array $extra = []): array
    {
        $entry = [
            'position'      => $position,
            'image_url'     => isset($item['link']) ? $item['link'] : null,
            'thumbnail_url' => isset($item['image']['thumbnailLink']) ? $item['image']['thumbnailLink'] : null,
            'context_url'   => isset($item['image']['contextLink']) ? $item['image']['contextLink'] : null,
            'title'         => isset($item['title']) ? $item['title'] : null,
            'width'         => isset($item['image']['width']) ? (int) $item['image']['width'] : null,
            'height'        => isset($item['image']['height']) ? (int) $item['image']['height'] : null,
            'outcome'       => $outcome,
            'reason'        => $reason,
        ];

        return array_merge($entry, $extra);
    }

    /**
     * Sufijo con el valor de la query para mostrar en el `outcome_detail`, solo cuando el
     * criterio es código de barras (el número no es obvio de por sí). Por nombre no se agrega,
     * porque el nombre del artículo ya es legible en el mensaje.
     *
     * @param string $criterion 'bar_code' o 'name'.
     * @param string $query     Valor de la query.
     * @return string
     */
    private function get_query_display_suffix(string $criterion, string $query): string
    {
        if ($criterion === 'bar_code') {
            return ' ('.$query.')';
        }

        return '';
    }

    /**
     * Crea una fila de diagnóstico en `article_image_search_attempts` para un artículo +
     * criterio de búsqueda probado en esta corrida del job.
     *
     * @param string      $batch_uuid           UUID de la corrida.
     * @param Article     $article              Artículo procesado.
     * @param string      $criterion             'bar_code', 'name' o 'none' (sin query posible).
     * @param int         $criterion_order       1 = primer criterio probado, 2 = segundo.
     * @param string      $query                 Texto enviado a Google (vacío si no hubo query).
     * @param int|null    $google_items_count    Cantidad de items devueltos por Google.
     * @param int|null    $google_total_results  totalResults de la respuesta de Google.
     * @param string|null $api_error             Mensaje de error de Google, si lo hubo.
     * @param string      $outcome               Uno de ArticleImageSearchAttempt::OUTCOME_*.
     * @param string      $outcome_detail        Frase en castellano lista para mostrar en pantalla.
     * @param array|null  $candidates            Detalle por candidata (ver build_candidate_entry).
     * @param string|null $assigned_image_url    URL de la imagen guardada en storage, solo cuando outcome es ASSIGNED (grupo 217, prompt 02).
     * @param bool        $needs_review          Si la asignación quedó marcada para revisión manual (grupo 217, prompt 02; normalmente se actualiza después, ver tarea 05).
     * @return ArticleImageSearchAttempt Modelo recién creado, para poder actualizarlo después (ej. marcar needs_review una vez calculado el veredicto).
     */
    private function write_attempt(
        string $batch_uuid,
        Article $article,
        string $criterion,
        int $criterion_order,
        string $query,
        ?int $google_items_count,
        ?int $google_total_results,
        ?string $api_error,
        string $outcome,
        string $outcome_detail,
        ?array $candidates,
        ?string $assigned_image_url = null,
        bool $needs_review = false
    ): ArticleImageSearchAttempt {
        return ArticleImageSearchAttempt::create([
            'user_id'              => $this->user_id,
            'batch_uuid'           => $batch_uuid,
            'article_id'           => $article->id,
            'article_name'         => $article->name,
            'article_bar_code'     => $article->bar_code,
            'criterion'            => $criterion,
            'criterion_order'      => $criterion_order,
            'query'                => $query,
            'google_items_count'   => $google_items_count,
            'google_total_results' => $google_total_results,
            'api_error'            => $api_error,
            'outcome'              => $outcome,
            'outcome_detail'       => $outcome_detail,
            'candidates'           => $candidates,
            'assigned_image_url'   => $assigned_image_url,
            'needs_review'         => $needs_review,
        ]);
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
