<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\User;
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
    }
}