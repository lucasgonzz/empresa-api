<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\ArticleEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que genera y persiste el embedding vectorial de un artículo.
 *
 * Recibe solo el ID del artículo (no la instancia Eloquent) para evitar
 * serializar el modelo completo con todas sus relaciones en la cola,
 * siguiendo el mismo patrón de ProcessSyncArticleToTiendaNube.
 *
 * El job carga las relaciones necesarias para armar el texto representativo
 * (category, brand, descriptions) y delega la generación y persistencia
 * al ArticleEmbeddingService.
 */
class GenerateArticleEmbeddingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

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
     * @param int $article_id ID del artículo cuyo embedding debe generarse.
     */
    public function __construct(int $article_id)
    {
        $this->article_id = $article_id;
    }

    /**
     * Ejecuta el job: carga el artículo, genera su embedding y lo persiste.
     *
     * Si el artículo no existe (fue eliminado antes de que el job se procesara)
     * retorna silenciosamente sin lanzar excepción ni marcar como fallido.
     *
     * @param ArticleEmbeddingService $service Inyectado por el container de Laravel.
     *
     * @return void
     */
    public function handle(ArticleEmbeddingService $service): void
    {
        // Cargar el artículo con las relaciones necesarias para armar el texto.
        // Si fue eliminado (soft delete o delete real), retornar silenciosamente.
        $article = Article::with(['category', 'brand', 'descriptions'])
            ->find($this->article_id);

        if (! $article) {
            Log::channel('daily')->info('GenerateArticleEmbeddingJob: artículo no encontrado, se omite.', [
                'article_id' => $this->article_id,
            ]);
            return;
        }

        try {
            // Generar y persistir el embedding via el servicio.
            $service->update_article_embedding($article);

            Log::channel('daily')->info('GenerateArticleEmbeddingJob: embedding generado correctamente.', [
                'article_id'   => $this->article_id,
                'article_name' => $article->name,
            ]);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('GenerateArticleEmbeddingJob: error al generar embedding.', [
                'article_id' => $this->article_id,
                'error'      => $exception->getMessage(),
            ]);

            // Re-lanzar para que el sistema de colas registre el fallo y reintente.
            throw $exception;
        }
    }
}
