<?php

namespace App\Services;

use App\Jobs\GenerateArticleEmbeddingJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de entrada para encolar GenerateArticleEmbeddingJob (misión embedding-un-solo-job).
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
            Bus::dispatch(new GenerateArticleEmbeddingJob($article_id, $embedding_run_id));
        } catch (\Throwable $exception) {
            // No quedó ningún job: se libera para no dejar el artículo trabado hasta el vencimiento.
            self::release($article_id);

            throw $exception;
        }

        return true;
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
