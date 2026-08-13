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