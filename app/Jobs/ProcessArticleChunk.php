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

class ProcessArticleChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $archivo_excel_path;
    protected $columns, $create_and_edit, $start_row, $finish_row,
              $provider_id, $import_history_id, $pre_import_id,
              $user, $auth_user_id;

    public $timeout = 1200; // 20 minutos por chunk, ajustable

    public function __construct($archivo_excel_path, $columns, $create_and_edit, $start_row, $finish_row, $provider_id, $import_history_id, $pre_import_id, $user, $auth_user_id)
    {
        $this->archivo_excel_path = $archivo_excel_path;
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->provider_id = $provider_id;
        $this->import_history_id = $import_history_id;
        $this->pre_import_id = $pre_import_id;
        $this->user = $user;
        $this->auth_user_id = $auth_user_id;
    }

    public function handle()
    {
        Excel::import(new ArticleImport(
            $this->columns, $this->create_and_edit,
            $this->start_row, $this->finish_row,
            $this->provider_id, $this->import_history_id,
            $this->pre_import_id, $this->user,
            $this->auth_user_id, $this->archivo_excel_path
        ), $this->archivo_excel_path);
    }
}
