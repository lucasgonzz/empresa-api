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

        /**
         * Se lee ANTES de pisarlo porque lo usa el evento de la demo del final del método:
         * si ya estaba en 'terminado', esta corrida es un reintento y el import ya se cerró
         * una vez (misión 50).
         */
        $ya_estaba_terminado = $import_history->status === 'terminado';

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
         * Y va al FINAL del handle(), con dos guardas, porque este job se reintenta: tiene
         * `$tries = 120`, y todo lo que hay arriba —la notificación, el Artisan::call— puede
         * tirar. Emitiendo en el medio, una notificación fallida reintentaba el job entero y
         * emitía el evento otra vez con un uuid nuevo, que del lado del admin son dos
         * importaciones distintas: el uuid hace idempotente el reintento del PUSH, no el de la
         * emisión. `$ya_estaba_terminado` cubre el reintento que igual llega hasta acá.
         *
         * Corre en el worker, sin sesión HTTP, así que el emisor resuelve la configuración
         * contra la base: una query por importación terminada, no por request.
         */
        if (!$ya_estaba_terminado) {
            DemoEventoEmitter::emitir('importacion.completada', null, [
                'import_history_id' => $import_history->id,
            ]);
        }
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