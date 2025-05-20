<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Imports\ArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Notifications\GlobalNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ProcessArticleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $archivo_excel;
    protected $columns;
    protected $create_and_edit;
    protected $start_row;
    protected $finish_row;
    protected $provider_id;
    protected $import_history_id;
    protected $pre_import_id;
    protected $user;
    protected $auth_user_id;
    protected $archivo_excel_path;

    public $timeout = 3600;

    public function __construct($archivo_excel, $columns, $create_and_edit, $start_row, $finish_row, $provider_id, $import_history_id, $pre_import_id, $user, $auth_user_id, $archivo_excel_path) {
        $this->archivo_excel = $archivo_excel;
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->provider_id = $provider_id;
        $this->import_history_id = $import_history_id;
        $this->pre_import_id = $pre_import_id;
        $this->user = $user;
        $this->auth_user_id = $auth_user_id;
        $this->archivo_excel_path = $archivo_excel_path;
    }


    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        
        Log::info('cacheando articulos');
        ArticleIndexCache::build($this->user->id);
        Log::info('articulos cacheados');

        // $chunkSize = 500;
        $chunkSize = 1000;
        $start = $this->start_row; // por ejemplo, 2

        while ($start <= $this->finish_row) {
            $end = min($start + $chunkSize - 1, $this->finish_row);

            Log::info("Se mandó chunk desde $start hasta $end");

            ProcessArticleChunk::dispatch(
                $this->archivo_excel_path,
                $this->columns,
                $this->create_and_edit,
                $start,
                $end,
                $this->provider_id,
                $this->import_history_id,
                $this->pre_import_id,
                $this->user,
                $this->auth_user_id
            );

            $start = $end + 1;
        }
        return;

    }

    public function failed(Throwable $exception)
    {
        Log::info('Hubo un error con la importacion');
        ArticleImportHelper::error_notification($this->user);
    }
}
