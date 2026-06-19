<?php

namespace App\Console\Commands;

use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;
use Illuminate\Console\Command;

/**
 * Comando Artisan para poblar o refrescar los embeddings vectoriales del catálogo.
 *
 * Por defecto procesa solo los artículos activos que aún no tienen embedding
 * (columna embedding IS NULL). Con la opción --all regenera todos los artículos
 * activos, incluyendo los que ya tienen embedding (útil si se cambia el modelo
 * de embeddings o si se necesita un re-index completo).
 *
 * Los jobs se despachan a la cola de forma asincrónica para no saturar la API
 * de OpenAI; el procesamiento real ocurre cuando el worker de colas esté activo.
 *
 * Uso:
 *   php artisan articles:generate-embeddings          # solo los sin embedding
 *   php artisan articles:generate-embeddings --all    # todos los activos
 */
class GenerateArticleEmbeddings extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     * La opción --all permite forzar la regeneración de todos los embeddings.
     *
     * @var string
     */
    protected $signature = 'articles:generate-embeddings {--all : Regenerar embeddings incluso para artículos que ya los tienen}';

    /**
     * Descripción que aparece en php artisan list.
     *
     * @var string
     */
    protected $description = 'Despacha jobs para generar embeddings vectoriales de artículos activos sin embedding (o de todos con --all)';

    /**
     * Ejecuta el comando: itera artículos activos en chunks de 50 y despacha
     * GenerateArticleEmbeddingJob para cada uno.
     *
     * Se usa chunkById() para no cargar los 50.000 artículos en memoria de una sola vez.
     * Solo se selecciona el id para minimizar el payload de cada iteración.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        // Determinar si se deben procesar todos o solo los que no tienen embedding.
        $regenerate_all = (bool) $this->option('all');

        // Construir la query base: solo artículos activos y no eliminados.
        $query = Article::query()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->select('id');

        if (! $regenerate_all) {
            // Filtrar solo los artículos sin embedding para un backfill incremental.
            $query->whereNull('embedding');
        }

        // Contar total para informar al usuario antes de comenzar.
        $total = $query->count();

        if ($total === 0) {
            $this->info('No hay artículos para procesar'.($regenerate_all ? '.' : ' (sin embedding).'));
            $this->info('Podés usar --all para regenerar todos los embeddings existentes.');
            return 0;
        }

        $mode_label = $regenerate_all ? 'todos (--all)' : 'sin embedding';
        $this->info("Despachando jobs para {$total} artículos ({$mode_label})...");

        // Contador de jobs despachados para el progreso.
        $dispatched = 0;

        // Procesar en chunks de 50 para evitar cargar todo en memoria.
        $query->chunkById(50, function ($articles) use (&$dispatched) {
            foreach ($articles as $article) {
                // Despachar el job a la cola; el worker lo procesará de forma asincrónica.
                GenerateArticleEmbeddingJob::dispatch($article->id);
                $dispatched++;
            }

            // Mostrar progreso cada chunk para que el operador vea avance.
            $this->info("  {$dispatched} jobs despachados...");
        });

        $this->info("Listo. Se despacharon {$dispatched} jobs en la cola.");
        $this->info('Asegurate de tener un worker activo: php artisan queue:work');

        return 0;
    }
}
