<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Imports\ArticleImport;
use App\Jobs\FinalizeArticleImport;
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
use Illuminate\Support\Facades\Bus;
use Throwable;

class ProcessArticleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $import_uuid;
    protected $archivo_excel;
    protected $columns;
    protected $create_and_edit;
    protected $no_actualizar_articulos_de_otro_proveedor;
    protected $start_row;
    protected $finish_row;
    protected $provider_id;
    protected $user;
    protected $auth_user_id;
    protected $archivo_excel_path;

    public $timeout = 3600;

    public function __construct($import_uuid, $archivo_excel, $columns, $create_and_edit, $no_actualizar_articulos_de_otro_proveedor, $start_row, $finish_row, $provider_id, $user, $auth_user_id, $archivo_excel_path) {
        $this->import_uuid = $import_uuid;
        $this->archivo_excel = $archivo_excel;
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->no_actualizar_articulos_de_otro_proveedor = $no_actualizar_articulos_de_otro_proveedor;        
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->provider_id = $provider_id;
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

        $chunkSize = env('ARTICLE_EXCEL_CHUNK_SIZE', 3500);

        if (env('APP_ENV') == 'local') {
            $chunkSize = 100;
        } 
        
        $start = $this->start_row;

        $chain = [];

        while ($start <= $this->finish_row) {
            $end = min($start + $chunkSize - 1, $this->finish_row);

            Log::info("Se mandó chunk desde $start hasta $end");

            $chain[] = new ProcessArticleChunk(
                $this->import_uuid,
                $this->archivo_excel_path,
                $this->columns,
                $this->create_and_edit,
                $this->no_actualizar_articulos_de_otro_proveedor,
                $start,
                $end,
                $this->provider_id,
                $this->user,
                $this->auth_user_id
            );

            $start = $end + 1;
        }

        Log::info("Terminaron chunck. Se va a llamar a FinalizeArticleImport");

        $chain[] = new FinalizeArticleImport(
            $this->import_uuid,
            'article',
            $this->columns,
            $this->user,
            $this->auth_user_id,
            $this->provider_id,
            $this->archivo_excel_path,
        );

        Bus::chain($chain)->dispatch();
    }

    public function failed(Throwable $exception)
    {
        Log::info('Hubo un error con la importacion, entro en el failed del job:');
        Log::info($exception->getTraceAsString());
        Log::info('Error previo:');
        Log::info($exception->getPrevious());
        Log::error('Mensaje: ' . $exception->getMessage());
        Log::error('Archivo: ' . $exception->getFile());
        Log::error('Línea: ' . $exception->getLine());
        Log::error('Trace: ' . $exception->getTraceAsString());
        
        ArticleImportHelper::error_notification($this->user, $exception->getLine(), $exception->getMessage());
    }
}
