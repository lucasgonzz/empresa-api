<?php

namespace App\Jobs;

use App\Services\ArticleEmbeddingBatchProcessor;
use App\Services\ArticleEmbeddingDispatcher;
use App\Services\ArticleEmbeddingService;
use App\Services\ColaDeEmbeddings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que genera y persiste el embedding vectorial de UN artículo.
 *
 * Recibe solo el ID del artículo (no la instancia Eloquent) para evitar
 * serializar el modelo completo con todas sus relaciones en la cola,
 * siguiendo el mismo patrón de ProcessSyncArticleToTiendaNube.
 *
 * Desde la misión embeddings-lote-cola este job NO tiene lógica propia: delega en
 * ArticleEmbeddingBatchProcessor con una lista de un solo artículo, que es donde viven (una sola vez)
 * el corte por huella, el reparto de resultados en la tanda y el manejo de errores. Se CONSERVA porque
 * es lo que despachan ArticleObserver y DescriptionObserver cuando alguien edita un artículo (una
 * edición suelta tiene que vectorizar ya, sin esperar a que se arme un lote) y porque los jobs viejos
 * que quedaron serializados en la cola tienen que seguir funcionando. Las propiedades serializadas
 * (`article_id`, `embedding_run_id`) no cambiaron por la misma razón.
 *
 * Si el job pertenece a una tanda (`embedding_run_id`), suma su resultado a los contadores de
 * `embedding_runs`: cada camino de salida cae en exactamente uno de generados / salteados / fallados.
 * Eso es lo que después le permite a FinalizeEmbeddingRun saber cuántos vectores se escribieron DE
 * VERDAD, que no es lo mismo que cuántos jobs se despacharon.
 */
class GenerateArticleEmbeddingJob implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de reintentos automáticos en caso de fallo.
     * Útil si la API de OpenAI responde con error transitorio (429, 5xx).
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Segundos a esperar entre reintentos (backoff lineal).
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * ID del artículo a embeddear.
     * Se guarda el ID para evitar serialización del modelo completo.
     *
     * @var int
     */
    protected $article_id;

    /**
     * ID de la tanda (embedding_runs) a la que este job le tiene que rendir cuentas, o null
     * si el job no pertenece a ninguna.
     *
     * @var int|null
     */
    protected $embedding_run_id;

    /**
     * El segundo parámetro es nullable con default A PROPÓSITO, no por comodidad de firma:
     * ArticleObserver y DescriptionObserver siguen despachando este job con un solo argumento
     * y NO se tocan. Un artículo que alguien edita a mano vectoriza al instante y no genera
     * ningún toast, porque el aviso al comerciante es por LOTE — avisarle "se actualizó 1
     * artículo" justo después de que él mismo lo guardó no le informa nada y le tapa la
     * pantalla. Solo las tandas del scheduler y las post-importación llevan run_id.
     *
     * @param int      $article_id       ID del artículo cuyo embedding debe generarse.
     * @param int|null $embedding_run_id ID de la tanda a la que reportar, o null si no hay tanda.
     */
    public function __construct(int $article_id, ?int $embedding_run_id = null)
    {
        // Nunca publicar efectos de una tanda cuya transacción todavía puede revertirse.
        $this->afterCommit = true;

        // 🔴 La cola propia de embeddings NO se fija acá sino en ArticleEmbeddingDispatcher (único punto
        // que despacha este job: lo garantiza el test 34 de CA18). Fijarla en el constructor pisaba el
        // `onConnection()` de quien despacha a una conexión explícita (p. ej. EfectosDeTandasTest, que
        // observa la cola de su propia conexión): el job iría a `default_embeddings` y no a la cola de esa
        // conexión. Un job construido directo (tests) o ya serializado antes de esta versión queda con
        // `queue` null.

        $this->article_id       = $article_id;
        $this->embedding_run_id = $embedding_run_id;
    }

    /**
     * Ejecuta el job: delega en el procesador común con una lista de un solo artículo.
     *
     * Si el artículo no existe (fue eliminado antes de que el job se procesara) el procesador lo cuenta
     * como salteado sin lanzar excepción ni marcar como fallido.
     *
     * 🔴 JOBS VIEJOS EN `default` (misión embeddings-lote-cola). Los jobs que ya estaban serializados en
     * la cola `default` antes de esta versión (el backlog de una importación grande: decenas de miles)
     * llevan `queue = null`. Si los procesara un worker de `default` seguirían tapando esa cola durante
     * horas. Entonces, si la cola propia está prendida y este job está corriendo en la cola base, NO
     * llama a OpenAI: libera la marca y se vuelve a despachar por el ArticleEmbeddingDispatcher (que cae
     * en la cola propia, con la misma tanda). Es una operación de milisegundos por job, así que `default`
     * se vacía en minutos. Sin bucle posible: con la cola apagada, llamado directo (`$this->job` es null,
     * como en los tests) o corriendo ya en la cola propia, procesa normal.
     *
     * @param ArticleEmbeddingService $service Inyectado por el container de Laravel.
     *
     * @return void
     */
    public function handle(ArticleEmbeddingService $service): void
    {
        if ($this->debe_mudarse_a_la_cola_propia()) {
            // Se suelta la marca de ESTE job y se reclama una nueva para el job de la cola propia.
            ArticleEmbeddingDispatcher::release($this->article_id);

            if (ArticleEmbeddingDispatcher::dispatch_if_not_queued($this->article_id, $this->embedding_run_id)) {
                Log::channel('daily')->info('GenerateArticleEmbeddingJob: job viejo de la cola base, se muda a la cola de embeddings.', [
                    'article_id' => $this->article_id,
                ]);

                return;
            }

            // No se pudo re-despachar (corte de credencial abierto, o otro job ya reclamó al artículo):
            // se procesa acá mismo para que la tanda lo cuente y no quede esperando hasta vencer.
        }

        (new ArticleEmbeddingBatchProcessor($service))->procesar(
            [$this->article_id],
            $this->embedding_run_id,
            $this->attempts(),
            $this->tries
        );
    }

    /**
     * ¿Este job es uno viejo que corre en la cola base y hay que mudarlo a la cola de embeddings?
     *
     * Sólo si el job quedó serializado SIN cola (`queue` null), la cola propia está prendida, hay un job
     * de cola real (no es una llamada directa) y ese job está corriendo en la cola BASE de la instancia.
     * Con driver `sync` la cola del job es 'sync', distinta de la base, y no se muda.
     *
     * @return bool
     */
    protected function debe_mudarse_a_la_cola_propia(): bool
    {
        if (! is_null($this->queue) || ! ColaDeEmbeddings::habilitada() || is_null($this->job)) {
            return false;
        }

        try {
            return (string) $this->job->getQueue() === ColaDeEmbeddings::cola_base();
        } catch (\Throwable $exception) {
            // Un job de cola que no sabe decir su cola (p. ej. un doble de prueba): no se muda, se procesa.
            return false;
        }
    }

    /**
     * Cuenta el job como fallado en la tanda cuando muere en forma definitiva.
     *
     * 🔴 failed() CORRE UNA SOLA VEZ, AL AGOTAR LOS 3 `tries` — no en cada reintento: mientras el job siga
     * reintentando todavía puede terminar generando el vector. La lógica (liberar la marca y sumar
     * `fallados`) vive en ArticleEmbeddingBatchProcessor::fallo_definitivo().
     *
     * @param \Throwable $exception
     *
     * @return void
     */
    public function failed($exception)
    {
        app(ArticleEmbeddingBatchProcessor::class)->fallo_definitivo([$this->article_id], $this->embedding_run_id, $exception);
    }

    /**
     * ID del artículo al que apunta este job (lo usan los tests con Queue::assertPushed).
     *
     * @return int
     */
    public function get_article_id(): int
    {
        return $this->article_id;
    }

    /**
     * ID de la tanda a la que reporta este job, o null si no pertenece a ninguna.
     *
     * @return int|null
     */
    public function get_embedding_run_id(): ?int
    {
        return $this->embedding_run_id;
    }
}
