<?php

namespace App\Services;

use App\Jobs\GenerateArticleEmbeddingJob;
use App\Jobs\GenerateArticleEmbeddingsBatchJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de entrada para encolar GenerateArticleEmbeddingJob (misión embedding-un-solo-job) y, desde la
 * misión embeddings-lote-cola, también GenerateArticleEmbeddingsBatchJob (el lote que arma el comando).
 *
 * Garantiza que cada artículo tenga, como mucho, UN job de embedding pendiente. Lo hace con una
 * marca en la base (`articles.embedding_queued_at`) que se reclama con un UPDATE condicional
 * atómico: sólo quien logra afectar la fila despacha el job.
 *
 * Ciclo de vida de la marca:
 * - dispatch_if_not_queued(): la pone y despacha.
 * - el job la libera apenas empieza a correr (release()), así toma el texto vigente y un cambio
 *   posterior sí puede encolar otro job.
 * - si el job va a reintentar la vuelve a poner (mark_as_queued()); si muere la libera.
 * - si el job se perdió (worker caído, cola vaciada) la marca vence sola a las
 *   QUEUED_MARK_TTL_MINUTES y el artículo se puede volver a encolar.
 *
 * Todo se escribe por query builder y no por Eloquent: no toca `updated_at` ni dispara observers.
 */
class ArticleEmbeddingDispatcher
{
    /**
     * Vigencia de la marca, en minutos (12 horas, decidido por Lucas). Pasado ese tiempo se
     * da por perdido el job que nunca corrió y se permite encolar otro.
     *
     * @var int
     */
    const QUEUED_MARK_TTL_MINUTES = 720;

    /**
     * Huella (sha1) del texto vacío. Un artículo sin texto que vectorizar queda con esta huella,
     * sin vector, y no debe volver a encolarse para siempre.
     *
     * @var string
     */
    const EMPTY_TEXT_HASH = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

    /**
     * Encola la generación del embedding de un artículo si no tiene ya un job vigente.
     *
     * Primero reclama la marca (UPDATE atómico) y recién después despacha: con cola `sync` el
     * job corre dentro del despacho y libera la marca; al revés quedaría marcado sin job.
     * Si el despacho lanza, se libera la marca y se re-lanza la excepción.
     *
     * 🔴 Con el corte por credencial rechazada abierto (EmbeddingsCredencialCorte) NO despacha, y lo
     * consulta ANTES de reclamar la marca: así un artículo editado durante el corte queda con
     * `embedding_queued_at` nulo y la primera pasada del comando tras el corte lo toma. Si reclamara
     * primero, la marca quedaría puesta sin ningún job y el artículo trabado hasta el vencimiento de
     * la marca (12 h).
     *
     * @param int      $article_id       ID del artículo.
     * @param int|null $embedding_run_id ID de la tanda a la que reportar, o null si no hay tanda.
     *
     * @return bool true si despachó un job; false si ya había uno vigente o hay un corte abierto.
     */
    public static function dispatch_if_not_queued(int $article_id, ?int $embedding_run_id = null): bool
    {
        if (EmbeddingsCredencialCorte::abierto()) {
            return false;
        }

        // Momento a partir del cual una marca se considera vencida.
        $expired_before = Carbon::now()->subMinutes(self::QUEUED_MARK_TTL_MINUTES);

        // Reclamo atómico: sólo una de varias llamadas simultáneas afecta la fila.
        $claimed = DB::table('articles')
            ->where('id', $article_id)
            ->where(function ($query) use ($expired_before) {
                $query->whereNull('embedding_queued_at')
                    ->orWhere('embedding_queued_at', '<', $expired_before);
            })
            ->update(['embedding_queued_at' => Carbon::now()]);

        if ($claimed < 1) {
            return false;
        }

        try {
            // Cola propia de embeddings (o null = cola por defecto si el interruptor está apagado).
            $job = new GenerateArticleEmbeddingJob($article_id, $embedding_run_id);
            $job->onQueue(ColaDeEmbeddings::cola_para_despachar());

            Bus::dispatch($job);
        } catch (\Throwable $exception) {
            // No quedó ningún job: se libera para no dejar el artículo trabado hasta el vencimiento.
            self::release($article_id);

            throw $exception;
        }

        return true;
    }

    /**
     * Encola UN job de lote con los artículos que todavía no tienen un job vigente (misión
     * embeddings-lote-cola). Es lo que usa el comando `articles:generate-embeddings`: arma bloques de
     * `ArticleEmbeddingBatchProcessor::TAMANO_LOTE` y los despacha por acá.
     *
     * Misma garantía que `dispatch_if_not_queued()`, con la misma regla de orden: consulta el corte por
     * credencial ANTES de reclamar ninguna marca (con el corte abierto no marca ni despacha nada), y
     * reclama la marca de CADA artículo con el mismo UPDATE atómico de siempre —uno por id: es lo que da
     * la idempotencia exacta, un artículo con job vigente simplemente no entra al lote—. Con los
     * reclamados despacha UN `GenerateArticleEmbeddingsBatchJob`. Si el despacho lanza, libera los que
     * reclamó (los que ya tenían marca de otro job no se tocan) y re-lanza la excepción.
     *
     * @param int[]    $article_ids      IDs candidatos, en el orden en que deben viajar.
     * @param int|null $embedding_run_id ID de la tanda a la que reportar, o null si no hay tanda.
     *
     * @return int Cantidad de artículos reclamados (los que quedaron en el lote); 0 si no se despachó nada.
     */
    public static function dispatch_batch_if_not_queued(array $article_ids, ?int $embedding_run_id = null): int
    {
        // Con el corte abierto no se reclama ni se despacha nada (ver dispatch_if_not_queued).
        if (EmbeddingsCredencialCorte::abierto()) {
            return 0;
        }

        // Momento a partir del cual una marca se considera vencida.
        $expired_before = Carbon::now()->subMinutes(self::QUEUED_MARK_TTL_MINUTES);

        // IDs cuya marca se reclamó con éxito (los que efectivamente viajan en el lote).
        $reclamados = [];

        foreach ($article_ids as $article_id) {
            $article_id = (int) $article_id;

            // Reclamo atómico por artículo: sólo una de varias llamadas simultáneas afecta la fila.
            $claimed = DB::table('articles')
                ->where('id', $article_id)
                ->where(function ($query) use ($expired_before) {
                    $query->whereNull('embedding_queued_at')
                        ->orWhere('embedding_queued_at', '<', $expired_before);
                })
                ->update(['embedding_queued_at' => Carbon::now()]);

            if ($claimed > 0) {
                $reclamados[] = $article_id;
            }
        }

        if (empty($reclamados)) {
            return 0;
        }

        try {
            Bus::dispatch(new GenerateArticleEmbeddingsBatchJob($reclamados, $embedding_run_id));
        } catch (\Throwable $exception) {
            // No quedó ningún job: se liberan SÓLO los que se reclamaron acá.
            self::release_batch($reclamados);

            throw $exception;
        }

        return count($reclamados);
    }

    /**
     * Libera la marca de "encolado" de un artículo.
     *
     * @param int $article_id ID del artículo.
     *
     * @return void
     */
    public static function release(int $article_id): void
    {
        DB::table('articles')
            ->where('id', $article_id)
            ->update(['embedding_queued_at' => null]);
    }

    /**
     * Libera la marca de "encolado" de varios artículos de una (el job de lote lo hace al empezar).
     * Un solo UPDATE; sin ids no hace nada.
     *
     * @param int[] $article_ids IDs de los artículos.
     *
     * @return void
     */
    public static function release_batch(array $article_ids): void
    {
        if (empty($article_ids)) {
            return;
        }

        DB::table('articles')
            ->whereIn('id', array_map('intval', $article_ids))
            ->update(['embedding_queued_at' => null]);
    }

    /**
     * Pone la marca de "encolado" a varios artículos de una, sin condiciones (el job de lote lo usa
     * cuando va a reintentar: el reintento pendiente cubre a todos los del lote).
     *
     * @param int[] $article_ids IDs de los artículos.
     *
     * @return void
     */
    public static function mark_batch_as_queued(array $article_ids): void
    {
        if (empty($article_ids)) {
            return;
        }

        DB::table('articles')
            ->whereIn('id', array_map('intval', $article_ids))
            ->update(['embedding_queued_at' => Carbon::now()]);
    }

    /**
     * Pone la marca de "encolado" sin condiciones (el job la usa cuando va a reintentar).
     *
     * @param int $article_id ID del artículo.
     *
     * @return void
     */
    public static function mark_as_queued(int $article_id): void
    {
        DB::table('articles')
            ->where('id', $article_id)
            ->update(['embedding_queued_at' => Carbon::now()]);
    }

    /**
     * Indica si el embedding guardado de un artículo ya refleja su texto actual.
     *
     * Está al día si la huella guardada coincide con la del texto y, o bien hay vector, o bien
     * el texto es vacío (en ese caso el vector queda NULL a propósito).
     *
     * @param string|null $stored_hash Huella guardada en `embedding_source_hash`.
     * @param bool        $has_vector  Si el artículo tiene vector.
     * @param string      $text        Texto actual a vectorizar.
     *
     * @return bool
     */
    public static function is_text_up_to_date(?string $stored_hash, bool $has_vector, string $text): bool
    {
        return sha1($text) === (string) $stored_hash && ($has_vector || $text === '');
    }
}
