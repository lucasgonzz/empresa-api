<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;
use App\Models\ImportStatus;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Comando Artisan para mantener los embeddings vectoriales del catálogo actualizados.
 *
 * Se ejecuta periódicamente desde el scheduler (cada 30 minutos) y procesa:
 * - Artículos sin embedding todavía (embedding IS NULL).
 * - Artículos modificados desde la última generación (updated_at > embedding_generated_at).
 *
 * Solo se ejecuta si el usuario dueño de la instancia tiene activa la extensión
 * "whatsapp_ia". Si hay una importación en proceso para ese usuario, el comando
 * se saltea para evitar generar embeddings de artículos que pueden estar cambiando.
 *
 * Uso manual:
 *   php artisan articles:generate-embeddings
 *   php artisan articles:generate-embeddings --all    # regenera todos (re-index completo)
 */
class GenerateArticleEmbeddings extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     * La opción --all fuerza regenerar embeddings incluso para artículos que ya los tienen.
     *
     * @var string
     */
    protected $signature = 'articles:generate-embeddings
                            {--all : Regenerar embeddings incluso para artículos que ya los tienen}';

    /**
     * Descripción que aparece en php artisan list.
     *
     * @var string
     */
    protected $description = 'Despacha jobs para generar embeddings vectoriales de artículos pendientes (requiere extensión whatsapp_ia)';

    /**
     * Ejecuta el comando: verifica extensión activa, importaciones en curso y despacha
     * GenerateArticleEmbeddingJob para cada artículo pendiente en chunks de 50.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        // Resolver el usuario dueño de la instancia (config app.USER_ID).
        $user_id = config('app.USER_ID');

        if (empty($user_id)) {
            $this->warn('articles:generate-embeddings: USER_ID no configurado en .env. Se omite.');
            return 0;
        }

        // Cargar el usuario con sus extensiones para verificar acceso.
        $user = User::with('extencions')->find($user_id);

        if (is_null($user)) {
            $this->warn("articles:generate-embeddings: usuario ID {$user_id} no encontrado. Se omite.");
            return 0;
        }

        // Solo procesar si el usuario tiene la extensión whatsapp_ia activa.
        if (! UserHelper::hasExtencion('whatsapp_ia', $user)) {
            // Silencioso en scheduler; el usuario simplemente no tiene la extensión.
            return 0;
        }

        // Saltearse si hay una importación en proceso para este usuario.
        // Evita generar embeddings de artículos que pueden estar cambiando en este momento.
        $importacion_activa = ImportStatus::where('user_id', $user_id)
            ->where('status', 'en_proceso')
            ->exists();

        if ($importacion_activa) {
            $this->info('articles:generate-embeddings: hay una importación en proceso. Se omite hasta el próximo ciclo.');
            return 0;
        }

        // Determinar si se deben regenerar todos o solo los pendientes.
        $regenerate_all = (bool) $this->option('all');

        // Query base: artículos activos del usuario, sin soft-delete.
        $query = Article::query()
            ->where('user_id', $user_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->select('id');

        if (! $regenerate_all) {
            // Procesar solo artículos pendientes:
            // - Sin embedding todavía (primera vez).
            // - O con embedding desactualizado (artículo modificado después de la última generación).
            $query->where(function ($q) {
                $q->whereNull('embedding')
                  ->orWhereNull('embedding_generated_at')
                  ->orWhereColumn('updated_at', '>', 'embedding_generated_at');
            });
        }

        // Contar total para informar al usuario antes de comenzar.
        $total = $query->count();

        if ($total === 0) {
            $this->info('articles:generate-embeddings: todos los embeddings están actualizados.');
            return 0;
        }

        $mode_label = $regenerate_all ? 'todos (--all)' : 'pendientes';
        $this->info("articles:generate-embeddings: despachando jobs para {$total} artículos ({$mode_label})...");

        // Contador de jobs despachados para mostrar progreso.
        $dispatched = 0;

        // Procesar en chunks de 50 para evitar cargar todo en memoria.
        $query->chunkById(50, function ($articles) use (&$dispatched) {
            foreach ($articles as $article) {
                GenerateArticleEmbeddingJob::dispatch($article->id);
                $dispatched++;
            }
            $this->info("  {$dispatched} jobs despachados...");
        });

        $this->info("articles:generate-embeddings: listo. {$dispatched} jobs en cola.");

        return 0;
    }
}
