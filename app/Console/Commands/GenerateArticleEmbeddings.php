<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\FinalizeEmbeddingRun;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ImportStatus;
use App\Models\User;
use Carbon\Carbon;
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
                            {--all : Regenerar embeddings incluso para artículos que ya los tienen}
                            {--origen=scheduler : De dónde salió esta corrida (scheduler|importacion|manual)}
                            {--ignorar-importacion-en-curso : Ver FinalizeArticleImport; NO usar a mano}';

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

        /*
         * Saltearse si hay una importación en proceso para este usuario.
         * Evita generar embeddings de artículos que pueden estar cambiando en este momento.
         *
         * 🔴 LA OPCIÓN QUE SALTEA ESTE GATE EXISTE PARA UN SOLO LLAMADOR: FinalizeArticleImport.
         * No la uses a mano y no la agregues a la llamada del scheduler.
         *
         * El motivo es que este gate mira TODOS los ImportStatus del usuario, no el de la
         * importación que acaba de terminar. Y eso lo vuelve peligroso justo en el momento en
         * que más falta hace generar embeddings:
         *
         * 1. FinalizeArticleImport corre, por construcción, cuando
         *    `processed_chunks >= total_chunks`; el ImportStatus de ESA importación ya quedó en
         *    'completado' desde ProcessArticleChunk::update_import_status().
         * 2. InitExcelImport::tiene_importacion_en_curso() impide que exista otra importación
         *    concurrente del mismo usuario.
         * 3. Por (1) y (2), cualquier ImportStatus en 'en_proceso' que este gate llegue a ver en
         *    ese instante es basura de una corrida que murió sin pasar por ImportFailureHandler
         *    (worker matado, OOM crudo). Nadie limpia esas filas: DetectarImportacionesColgadas
         *    filtra por ImportHistory, no por ImportStatus.
         *
         * O sea que sin la opción, un ImportStatus huérfano deja este comando mudo PARA SIEMPRE
         * -- tanto la corrida agendada como la post-importación --, y el agente de WhatsApp nunca
         * vuelve a encontrar un artículo nuevo. El test 17 fija las dos mitades: con la opción
         * corre igual, sin la opción no hace nada.
         */
        if (!$this->option('ignorar-importacion-en-curso')) {
            $importacion_activa = ImportStatus::where('user_id', $user_id)
                ->where('status', 'en_proceso')
                ->exists();

            if ($importacion_activa) {
                $this->info('articles:generate-embeddings: hay una importación en proceso. Se omite hasta el próximo ciclo.');
                return 0;
            }
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

        /*
         * Guarda anti-solapamiento. El scheduler corre esto cada 30 minutos y una importación
         * grande puede dispararlo en cualquier momento: si se abrieran dos tandas del mismo
         * usuario a la vez, el comerciante recibiría dos avisos pisándose y los mismos artículos
         * tendrían jobs duplicados en la cola.
         *
         * Una tanda vieja que quedó abierta NO bloquea para siempre: se la marca vencida y se
         * sigue. La ventana es la misma que usa FinalizeEmbeddingRun para darse por vencido, así
         * que las dos puntas coinciden en cuándo una tanda dejó de estar viva.
         */
        $run_abierta = EmbeddingRun::where('user_id', $user_id)
            ->whereIn('status', ['despachando', 'en_proceso'])
            ->orderBy('id', 'desc')
            ->first();

        if (!is_null($run_abierta)) {
            /** Momento a partir del cual una tanda abierta se considera abandonada. */
            $vence_at = Carbon::parse($run_abierta->created_at)
                ->addMinutes(FinalizeEmbeddingRun::MINUTOS_PARA_VENCER);

            if (Carbon::now()->lessThan($vence_at)) {
                $this->info('articles:generate-embeddings: ya hay una tanda en curso (id '.$run_abierta->id.'). Se omite.');
                return 0;
            }

            $run_abierta->status = 'vencida';
            $run_abierta->save();
        }

        /*
         * 🔴 LA TANDA NACE EN 'despachando' Y RECIÉN DESPUÉS PASA A 'en_proceso'. Los dos estados
         * no son cosmética: son lo único que impide que la tanda se cierre antes de existir.
         *
         * Con QUEUE_CONNECTION=database el worker empieza a levantar los jobs del primer chunk
         * mientras este comando todavía está despachando el segundo. Si la tanda naciera
         * directamente en 'en_proceso' con `total_jobs = 0`, FinalizeEmbeddingRun podría correr
         * en ese hueco, ver `terminados (0) >= total_jobs (0)` y dar la tanda por terminada sin
         * que se hubiera despachado un solo job: aviso fantasma, y la tanda real quedando
         * huérfana sin que nadie la cierre nunca.
         */
        $run = EmbeddingRun::create([
            'user_id'    => $user_id,
            'origen'     => (string) $this->option('origen'),
            'status'     => 'despachando',
            'total_jobs' => 0,
        ]);

        // Contador de jobs despachados para mostrar progreso.
        $dispatched = 0;

        // Procesar en chunks de 50 para evitar cargar todo en memoria.
        $query->chunkById(50, function ($articles) use (&$dispatched, $run) {
            foreach ($articles as $article) {
                GenerateArticleEmbeddingJob::dispatch($article->id, $run->id);
                $dispatched++;
            }
            $this->info("  {$dispatched} jobs despachados...");
        });

        // Cierre del despacho: recién ahora `total_jobs` es definitivo y el finalizador tiene
        // permitido comparar contadores.
        $run->total_jobs = $dispatched;
        $run->status     = 'en_proceso';
        $run->save();

        /*
         * El finalizador es el que cuenta cuántos vectores se escribieron DE VERDAD y avisa. No
         * puede hacerlo este comando: los jobs corren en el worker, en otro proceso, y muchos van
         * a cortar por huella sin generar nada.
         */
        FinalizeEmbeddingRun::dispatch($run->id)
            ->delay(Carbon::now()->addSeconds(10));

        // El id va al output para poder ir a mirar la fila cuando el aviso no aparezca.
        $this->info("articles:generate-embeddings: listo. {$dispatched} jobs en cola (tanda {$run->id}).");

        return 0;
    }
}
