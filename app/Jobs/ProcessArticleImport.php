<?php

namespace App\Jobs;

use App\Imports\ArticleImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;
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

    public $timeout = 5000;

    public function __construct($archivo_excel, $columns, $create_and_edit, $start_row, $finish_row, $provider_id, $import_history_id, $pre_import_id, $user) {
        $this->archivo_excel = $archivo_excel;
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->provider_id = $provider_id;
        $this->import_history_id = $import_history_id;
        $this->pre_import_id = $pre_import_id;
        $this->user = $user;
    }


    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('Llamando a ExcelImport');
        Excel::import(new ArticleImport($this->columns, $this->create_and_edit, $this->start_row, $this->finish_row, $this->provider_id, $this->import_history_id, $this->pre_import_id, $this->user), $this->archivo_excel);
    }

    public function failed(Throwable $exception)
    {
        Log::info('Hubo un error con la importacion');
    }
}
