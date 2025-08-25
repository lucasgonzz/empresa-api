<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Imports\ArticleImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class ProcessArticleChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $import_uuid, $archivo_excel_path, $columns, $create_and_edit, $start_row, $finish_row,
              $provider_id, $import_history_id, $pre_import_id,
              $user, $auth_user_id, $no_actualizar_articulos_de_otro_proveedor;

    public $timeout = 1200; // 20 minutos por chunk, ajustable

    public function __construct($import_uuid, $archivo_excel_path, $columns, $create_and_edit, $no_actualizar_articulos_de_otro_proveedor, $start_row, $finish_row, $provider_id, $import_history_id, $pre_import_id, $user, $auth_user_id)
    {
        $this->import_uuid = $import_uuid;
        $this->archivo_excel_path = $archivo_excel_path;
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->no_actualizar_articulos_de_otro_proveedor = $no_actualizar_articulos_de_otro_proveedor;
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
        try {

            Excel::import(new ArticleImport(
                $this->import_uuid,
                $this->columns, $this->create_and_edit,
                $this->no_actualizar_articulos_de_otro_proveedor,
                $this->start_row, $this->finish_row,
                $this->provider_id, $this->import_history_id,
                $this->pre_import_id, $this->user,
                $this->auth_user_id, $this->archivo_excel_path
            ), $this->archivo_excel_path);

        } catch (\Throwable $e) {


            Log::error('Error al importar, desde ProcessArticleChunk handle');
            Log::error('Mensaje: ' . $e->getMessage());
            Log::error('Archivo: ' . $e->getFile());
            Log::error('Línea: ' . $e->getLine());
            Log::error('Trace: ' . $e->getTraceAsString());


            // Registra el progreso y errores en Import History
            // ArticleImportHelper::create_import_history($this->user, $this->auth_user_id, $this->provider_id, $this->created_models, $this->updated_models, $this->columns, $this->archivo_excel_path, $error_message, $this->articulos_creados, $this->articulos_actualizados, $this->updated_props);

            ArticleImportHelper::error_notification($this->user, null, $e->getMessage());
        }
    }
}
