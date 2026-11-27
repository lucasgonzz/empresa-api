<?php

namespace App\Jobs;

use App\Services\ArticleEmbeddingBatchProcessor;
use App\Services\ColaDeEmbeddings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job que genera y persiste los embeddings vectoriales de un LOTE de artículos con UNA sola llamada a
 * OpenAI (misión embeddings-lote-cola).
 *
 * Lo despacha ArticleEmbeddingDispatcher::dispatch_batch_if_not_queued() desde el comando
 * `articles:generate-embeddings`, con hasta ArticleEmbeddingBatchProcessor::TAMANO_LOTE artículos cuya
 * marca `embedding_queued_at` ya reclamó. Toda la lógica vive en ArticleEmbeddingBatchProcessor; este
 * job sólo guarda los ids y delega, igual que GenerateArticleEmbeddingJob lo hace para un artículo.
 *
 * Recibe ids y no instancias Eloquent para no serializar los modelos (ni sus relaciones) en la cola.
 * Va a la cola propia de embeddings (ColaDeEmbeddings) para no tapar a `default`.
 */
class GenerateArticleEmbeddingsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Reintentos automáticos ante un error transitorio de OpenAI (429 de rate limit, 5xx, timeout).
     * Se reintenta el lote ENTERO; lo que ya quedó guardado se cuenta como al día en el reintento.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Segundos a esperar entre reintentos.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * Segundos máximos de ejecución: un pedido de hasta 100 textos (60 s de timeout HTTP) y, si OpenAI
     * rechaza el lote, el reintento de cada artículo por separado.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * IDs de los artículos del lote, en el orden en que viajan a OpenAI.
     *
     * @var int[]
     */
    protected $article_ids;

    /**
     * ID de la tanda (embedding_runs) a la que este job le rinde cuentas, o null si no pertenece a ninguna.
     *
     * @var int|null
     */
    protected $embedding_run_id;

    /**
     * @param int[]    $article_ids      Artículos del lote (ya con su marca reclamada).
     * @param int|null $embedding_run_id Tanda a la que reportar, o null si no hay tanda.
     */
    public function __construct(array $article_ids, ?int $embedding_run_id = null)
    {
        // Nunca publicar efectos de una tanda cuya transacción todavía puede revertirse.
        $this->afterCommit = true;

        // Cola propia de embeddings (o null = `default` si el interruptor está apagado).
        $this->queue = ColaDeEmbeddings::cola_para_despachar();

        $this->article_ids      = array_values(array_map('intval', $article_ids));
        $this->embedding_run_id = $embedding_run_id;
    }

    /**
     * Ejecuta el lote: delega en el procesador común.
     *
     * @param ArticleEmbeddingBatchProcessor $processor Inyectado por el contenedor.
     *
     * @return void
     */
    public function handle(ArticleEmbeddingBatchProcessor $processor): void
    {
        $processor->procesar($this->article_ids, $this->embedding_run_id, $this->attempts(), $this->tries);
    }

    /**
     * Cuenta el lote como fallado en la tanda cuando muere en forma definitiva (agotó los `tries`):
     * libera las marcas y suma `fallados` y `terminados` por cada artículo.
     *
     * @param \Throwable $exception
     *
     * @return void
     */
    public function failed($exception)
    {
        app(ArticleEmbeddingBatchProcessor::class)->fallo_definitivo($this->article_ids, $this->embedding_run_id, $exception);
    }

    /**
     * IDs de los artículos del lote (lo usan los tests con Queue::pushed).
     *
     * @return int[]
     */
    public function get_article_ids(): array
    {
        return $this->article_ids;
    }

    /**
     * ID de la tanda a la que reporta este lote, o null si no pertenece a ninguna.
     *
     * @return int|null
     */
    public function get_embedding_run_id(): ?int
    {
        return $this->embedding_run_id;
    }
}
