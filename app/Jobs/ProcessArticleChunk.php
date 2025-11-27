<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Imports\ArticleImport;
use App\Models\ImportStatus;
use App\Notifications\ImportStatusNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ProcessArticleChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $import_uuid, $archivo_excel_path, $columns, $create_and_edit, $start_row, $finish_row,
              $provider_id, $user, $auth_user_id, $no_actualizar_articulos_de_otro_proveedor, $import_status_id;

    public $timeout = 1200; // 20 minutos por chunk, ajustable

    public function __construct($import_uuid, $archivo_excel_path, $columns, $create_and_edit, $no_actualizar_articulos_de_otro_proveedor, $start_row, $finish_row, $provider_id, $user, $auth_user_id, $import_status_id)
    {
        $this->import_uuid = $import_uuid;
        $this->archivo_excel_path = $archivo_excel_path;
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->no_actualizar_articulos_de_otro_proveedor = $no_actualizar_articulos_de_otro_proveedor;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->provider_id = $provider_id;
        $this->user = $user;
        $this->auth_user_id = $auth_user_id;
        $this->import_status_id = $import_status_id;
    }

    public function handle()
    {
        try {

            if (env('APP_ENV') == 'local') {
                sleep(1);
            }

            $extension = pathinfo($this->archivo_excel_path, PATHINFO_EXTENSION);

            $ext = strtolower($extension);

            if ($ext == 'xls') {
                $reader_type = ExcelFormat::XLS;
            } else if ($ext == 'xlsx') {
                $reader_type = ExcelFormat::XLSX; 
            } else {
                $reader_type = ExcelFormat::XLSX; // fallback
            }

            Excel::import(new ArticleImport(
                $this->import_uuid,
                $this->columns, $this->create_and_edit,
                $this->no_actualizar_articulos_de_otro_proveedor,
                $this->start_row, $this->finish_row,
                $this->provider_id, $this->user,
                $this->auth_user_id, $this->archivo_excel_path
            ), $this->archivo_excel_path, null, $reader_type);

            $this->update_import_status();

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

    function update_import_status() {

        $import_status = ImportStatus::find($this->import_status_id);
        $import_status->processed_chunks++;
        $import_status->save();

        $this->user->notify(new ImportStatusNotification($import_status, $this->user->id));
    }
}
