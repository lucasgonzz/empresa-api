<?php

namespace App\Jobs;

use App\Imports\ArticleImport;
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

    public $timeout = 9999999;

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
        Log::info('Llamando a ExcelImport');
        Excel::import(new ArticleImport($this->columns, $this->create_and_edit, $this->start_row, $this->finish_row, $this->provider_id, $this->import_history_id, $this->pre_import_id, $this->user, $this->auth_user_id, $this->archivo_excel_path), $this->archivo_excel);
    }

    public function failed(Throwable $exception)
    {
        Log::info('Hubo un error con la importacion');

        $functions_to_execute = [
            [
                'btn_text'      => 'Entendido',
                // 'function_name' => 'close_notification_modal',
                'btn_variant'   => 'primary',
            ],
        ];

        $this->user->notify(new GlobalNotification(
            'Hubo un error durante la importacion de articulos',
            'danger',
            $functions_to_execute,
            $this->user->id,
            true,
        ));
    }
}