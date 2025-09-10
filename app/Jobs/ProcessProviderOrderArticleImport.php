<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Imports\ProviderOrderArticleImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

class ProcessProviderOrderArticleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    protected $columns, $start_row, $finish_row, $user, $provider_order, $archivo_excel_path;

    public $timeout = 1200; // 20 minutos por chunk, ajustable

    public function __construct($columns, $start_row, $finish_row, $user, $provider_order, $archivo_excel_path)
    {
        $this->columns = $columns;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->user = $user;
        $this->provider_order = $provider_order;
        $this->archivo_excel_path = $archivo_excel_path;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {

            Excel::import(new ProviderOrderArticleImport(
                $this->columns
                $this->start_row, 
                $this->finish_row,
                $this->user,
                $this->provider_order,
            ), $this->archivo_excel_path);

        } catch (\Throwable $e) {


            Log::error('Error al importar, desde ProcessArticleChunk handle');
            Log::error('Mensaje: ' . $e->getMessage());
            Log::error('Archivo: ' . $e->getFile());
            Log::error('Línea: ' . $e->getLine());
            Log::error('Trace: ' . $e->getTraceAsString());


            ArticleImportHelper::error_notification($this->user, null, $e->getMessage());
        }
    }

    public function failed(Throwable $exception)
    {
        Log::info('Hubo un error con la importacion');
        ArticleImportHelper::error_notification($this->user);
    }
}
