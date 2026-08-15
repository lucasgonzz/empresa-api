<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\ArticleEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
 *
 * Antes de salir a la red compara la huella (sha1) del texto a vectorizar contra la
 * que quedó guardada en articles.embedding_source_hash la última vez. Si coinciden, el
 * vector que devolvería OpenAI sería idéntico al que ya está en la base, así que el job
 * corta ahí y solo refresca la marca de tiempo. Es lo que evita que una importación que
 * únicamente movió precios o stock termine pagando una llamada por artículo.
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
            // El texto que efectivamente se vectoriza y su huella. Se calculan ACÁ, antes
            // de cualquier llamada a OpenAI, porque son lo único que permite saber si el
            // artículo cambió en algo que al embedding le mueva la aguja.
            $texto = $service->embedding_for_article($article);
            $hash  = sha1($texto);

            if ($texto !== ''
                && ! is_null($article->embedding)
                && (string) $article->embedding_source_hash === $hash) {
                // El texto relevante no cambió. Caso típico: una importación que solo movió
                // final_price o stock, y el comando agendado lo reencola igual porque su
                // filtro mira updated_at > embedding_generated_at.
                //
                // Se refresca solo la marca de tiempo, para que el scheduler deje de
                // re-encolarlo, y se corta acá SIN gastar la llamada paga a OpenAI: el
                // vector que devolvería es exactamente el que ya está guardado. El filtro
                // SQL del comando queda como está (es barato); el corte real es este.
                DB::table('articles')
                    ->where('id', $article->id)
                    ->update(['embedding_generated_at' => now()]);

                Log::channel('daily')->info('GenerateArticleEmbeddingJob: el texto del artículo no cambió, se omite la llamada a OpenAI.', [
                    'article_id' => $this->article_id,
                ]);

                return;
            }

            // Generar y persistir el embedding via el servicio.
            $service->update_article_embedding($article);

            // Registrar cuándo se generó el embedding para que el scheduler detecte
            // si el artículo se modifica después de esta fecha (updated_at > embedding_generated_at),
            // y la huella del texto usado, que es lo que permite cortar la próxima vez si
            // el artículo se toca pero el texto queda igual.
            DB::table('articles')
                ->where('id', $article->id)
                ->update([
                    'embedding_generated_at' => now(),
                    'embedding_source_hash'  => $hash,
                ]);

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
