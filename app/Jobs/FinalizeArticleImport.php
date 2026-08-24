<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\import\article\ImportFailureHandler;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\User;
use App\Services\DemoEventoEmitter;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class FinalizeArticleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // por el Artisan::call (puede tardar)
    public $tries = 120;   // por si el VPS está cargado
    public $backoff = 10;

    protected $user_id;
    protected $import_history_id;
    protected $import_status_id;

    public function __construct(int $user_id, int $import_history_id, int $import_status_id)
    {
        $this->user_id = $user_id;
        $this->import_history_id = $import_history_id;
        $this->import_status_id = $import_status_id;
    }

    public function handle()
    {
        $import_status = ImportStatus::select('id', 'processed_chunks', 'total_chunks', 'status')
            ->find($this->import_status_id);

        if ($import_status) {
            if ((int) $import_status->processed_chunks < (int) $import_status->total_chunks) {
                Log::warning('FinalizeArticleImport: aún faltan chunks, re-dispatch', [
                    'import_status_id' => $import_status->id,
                    'processed_chunks' => $import_status->processed_chunks,
                    'total_chunks' => $import_status->total_chunks,
                ]);

                // Re-dispatch con delay (NO consume attempts del job actual)
                self::dispatch($this->user_id, $this->import_history_id, $this->import_status_id)
                    ->delay(now()->addSeconds(10))
                    ->onConnection($this->connection)
                    ->onQueue($this->queue);

                return;
            }
        }

        $user = User::find($this->user_id);
        if (!$user) {
            return;
        }

        $import_history = ImportHistory::find($this->import_history_id);
        if (!$import_history) {
            return;
        }

        $import_history->status = 'terminado';
        $import_history->terminado_at = Carbon::now();

        /*
         * Suma el matching_counts_json de todos los chunks de este import (grupo 232,
         * prompt 03). Se hace acá, en el único punto donde se cierra la importación y ya
         * no quedan chunks corriendo, para no pisarse con otro worker.
         */
        ArticleImportHelper::calcular_matching_counts_total($import_history);

        $import_history->save();

        ArticleImportHelper::enviar_notificacion($user, $import_history);

        Log::info('Se envio notificacion');

        if (UserHelper::hasExtencion('article_variants', $user)) {
            Artisan::call('set_article_address_stock_from_variants', [
                'user_id' => $user->id
            ]);
        }

        ArticleIndexCache::limpiar_cache($user->id);
        Log::info('Se limpio cache');

        /**
         * Evento de la demo (misión 50). Se emite ACÁ y no en AiExcelImportController, que es
         * donde la misión lo daba por ubicado: ese controller sólo ARRANCA la importación
         * (InitExcelImport encola los chunks y responde 200 enseguida), así que emitir ahí
         * reportaría como completada una importación que todavía puede fallar entera.
         *
         * 🔴 La idempotencia va por CLAVE, no por el status del import.
         *
         * Este job se reintenta hasta 120 veces, así que emitir sin más lo duplicaba: el uuid
         * hace idempotente el reintento del PUSH, no el de la EMISIÓN. Pero condicionarlo a
         * "el import no estaba ya en terminado" no sirve, y se probó que no sirve: el último
         * chunk ya deja `status = 'terminado'` desde `ProcessArticleChunk::update_import_history()`
         * antes de que este job exista, así que esa condición es falsa **siempre** y el evento no
         * se emitía nunca. El status lo escriben otros tres lugares; no puede ser la marca.
         *
         * La clave es el id del import: el emisor deriva de ella un uuid estable y deja que el
         * índice único de `demo_eventos` decida. Emitir dos veces el mismo hecho deja una fila.
         *
         * Corre en el worker, sin sesión HTTP, así que el emisor resuelve la configuración
         * contra la base: una query por importación terminada, no por request.
         */
        DemoEventoEmitter::emitir(
            'importacion.completada',
            null,
            ['import_history_id' => $import_history->id],
            (string) $import_history->id
        );

        $this->generar_embeddings_de_lo_importado();
    }

    /**
     * Dispara la vectorización de los artículos que la importación acaba de crear o modificar.
     *
     * POR QUÉ ACÁ Y POR QUÉ NO ALCANZA CON ESPERAR AL SCHEDULER
     * --------------------------------------------------------
     * Mientras dura una importación, los dos observers de embeddings se saltean a propósito cada
     * artículo que se guarda (ArticleObserver::debe_generar_embedding() corta si hay un
     * ImportStatus en 'en_proceso'), porque encolar un job por cada fila de un Excel de 20.000
     * sería una tormenta sobre artículos que además siguen cambiando.
     *
     * La red que levantaba eso era el comando agendado cada 30 minutos. O sea que un lead que
     * importaba su catálogo y se iba derecho a probar el agente de WhatsApp podía preguntar por
     * un artículo recién importado y que el agente no lo encontrara — no porque estuviera mal
     * cargado, sino porque todavía no tenía vector. Hasta media hora de ventana, justo en el
     * momento en que está evaluando si compra.
     *
     * Este es el único punto donde engancharlo: es el último eslabón del Bus::chain que arma
     * InitExcelImport, corre una sola vez, y si todavía faltaban chunks ya se re-despachó solo
     * mucho antes de llegar hasta acá.
     *
     * Sobre `--ignorar-importacion-en-curso`: la justificación completa está en el gate del
     * propio comando. En una línea, el gate mira TODOS los ImportStatus del usuario y no el de
     * esta importación, así que un registro huérfano de una corrida muerta dejaría al comando
     * mudo justo cuando más falta hace.
     *
     * No se chequea el código de salida a propósito: si no hay nada pendiente que vectorizar, o
     * el dueño no tiene la extensión whatsapp_ia, el comando sale en 0 sin hacer nada y eso es
     * lo correcto, no una falla que haya que propagar y que voltearía la finalización de una
     * importación que ya terminó bien.
     *
     * @return void
     */
    protected function generar_embeddings_de_lo_importado()
    {
        Artisan::call('articles:generate-embeddings', [
            '--origen'                        => 'importacion',
            '--ignorar-importacion-en-curso'  => true,
        ]);

        Log::info('FinalizeArticleImport: se disparó la generación de embeddings post-importación.', [
            'import_history_id' => $this->import_history_id,
        ]);
    }

    /**
     * Marca el import como fallido si el job de finalización muere en forma definitiva (tras agotar sus
     * reintentos, timeout, o worker reiniciado). Idempotente vía ImportFailureHandler.
     *
     * @param  \Throwable  $e
     * @return void
     */
    public function failed($e)
    {
        ImportFailureHandler::desde_excepcion(
            $this->import_history_id,
            $this->import_status_id,
            $this->user_id,
            $e,
            null,
            array()
        );
    }
}