<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Models\ImportHistory;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeArticleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user_id, $import_history_id, $user;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user_id, $import_history_id)
    {
        $this->user_id = $user_id;
        $this->import_history_id = $import_history_id;

        $this->user = User::find($user_id);
    }


    public function handle()
    {
      
        $import_history = ImportHistory::find($this->import_history_id);
        $import_history->status = 'terminado';
        $import_history->save();

        ArticleImportHelper::enviar_notificacion($this->user, $import_history);

        Log::info('Se envio notificacion');

        Artisan::call('set_article_address_stock_from_variants', [
            'user_id' => $this->user->id
        ]);


        ArticleIndexCache::limpiar_cache($this->user->id);

            
    }

}
