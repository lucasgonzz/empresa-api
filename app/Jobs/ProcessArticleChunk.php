<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Imports\ArticleImport;
use App\Models\ImportStatus;
use App\Notifications\ImportStatusNotification;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

class ProcessArticleChunk implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $import_uuid, $csv_path, $columns, $create_and_edit, $start_row, $finish_row,
              $provider_id, $user, $auth_user_id, $no_actualizar_articulos_de_otro_proveedor, $import_status_id, $chunk_number;

    public $timeout = 1800; // 30 minutos por chunk, ajustable
    public $tries = 1;
    
	public function __construct($import_uuid, $csv_path, $columns, $create_and_edit, $no_actualizar_articulos_de_otro_proveedor, $start_row, $finish_row, $provider_id, $user, $auth_user_id, $import_status_id, $chunk_number)
    {

        $this->import_uuid = $import_uuid;
        $this->csv_path = $csv_path;
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->no_actualizar_articulos_de_otro_proveedor = $no_actualizar_articulos_de_otro_proveedor;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->provider_id = $provider_id;
        $this->user = $user;
        $this->auth_user_id = $auth_user_id;
        $this->import_status_id = $import_status_id;
        $this->chunk_number = $chunk_number;
    }

    public function batchId() {
        return optional($this->batch())->id ?? 'NO_BATCH';
    }

    public function handle()
    {
        Log::warning("INICIO Job Chunk #{$this->chunk_number} del lote {$this->batchId()}. PID: " . getmypid());

        $inicio = microtime(true);

        try {

            $this->import_status = ImportStatus::find($this->import_status_id);

            if ($this->import_status->status == 'fallo') {
                return;
            }

            $importer = new ArticleImport(
                $this->import_uuid,
                $this->columns,
                $this->create_and_edit,
                $this->no_actualizar_articulos_de_otro_proveedor,
                $this->start_row,
                $this->finish_row,
                $this->provider_id,
                $this->user,
                $this->auth_user_id,
                $this->csv_path,
                $this->chunk_number
            );
    
            $chunkRows = [];
            $file_path = storage_path('app/' . $this->csv_path);
    
            Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Abriendo y leyendo archivo CSV.");
            if (($handle = fopen($file_path, "r")) !== FALSE) {
                $currentRow = 1;
                while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                    if ($currentRow >= $this->start_row && $currentRow <= $this->finish_row) {
                        $chunkRows[] = $data;
                    }
                    if ($currentRow > $this->finish_row) {
                        break;
                    }
                    $currentRow++;
                }
                fclose($handle);
            }
            Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Archivo CSV leído y cerrado.");
    
            Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Invocando lógica de importación (importer->collection).");
            $collection = new \Illuminate\Support\Collection($chunkRows);
            $importer->collection($collection);

            unset($collection, $chunkRows); // Liberar memoria explícitamente

            Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Lógica de importación completada.");

            $this->update_import_status();

            $fin = microtime(true);

            $duracion = $fin - $inicio;
            Log::warning("FIN Job Chunk #{$this->chunk_number} del lote {$this->batchId()}. PID: " . getmypid() . " - Tardó en procesarse: " . number_format($duracion, 3) . " segundos");

        } catch (\Throwable $e) {


            Log::error('Error al importar, desde ProcessArticleChunk handle');
            Log::error('Mensaje: ' . $e->getMessage());
            Log::error('Archivo: ' . $e->getFile());
            Log::error('Línea: ' . $e->getLine());
            Log::error('Trace: ' . $e->getTraceAsString());


            $fin = microtime(true);

            $duracion = $fin - $inicio;
            Log::warning("FIN Job Chunk #{$this->chunk_number} del lote {$this->batchId()}. PID: " . getmypid() . " - Tardó en procesarse: " . number_format($duracion, 3) . " segundos");

            // Registra el progreso y errores en Import History
            // ArticleImportHelper::create_import_history($this->user, $this->auth_user_id, $this->provider_id, $this->created_models, $this->updated_models, $this->columns, $this->archivo_excel_path, $error_message, $this->articulos_creados, $this->articulos_actualizados, $this->updated_props);

            ArticleImportHelper::error_notification($this->user, null, $e->getMessage());

            $this->notificar_error_input_status($e->getMessage());

            throw $e; // ✅ Esto detiene la chain
        }
    }

    function notificar_error_input_status($error) {

        // $import_status = ImportStatus::find($this->import_status_id);
        $this->import_status->error_message = $error;
        $this->import_status->status = 'fallo';
        $this->import_status->save();

        $this->user->notify(new ImportStatusNotification($this->import_status, $this->user->id));
		
    }

    function update_import_status() {

        // Atomically increment the counter to prevent race conditions
        ImportStatus::where('id', $this->import_status_id)->increment('processed_chunks');

        // Retrieve the updated status to check if it's complete
        $import_status = ImportStatus::find($this->import_status_id);
        
        // Use >= as a safeguard in case of concurrent increments
        if ($import_status && $import_status->processed_chunks >= $import_status->total_chunks) {
            $import_status->status = 'completado';
            $import_status->save();
        }
        
        // Notify the user with the fresh status
        $notification = new ImportStatusNotification($import_status, $this->user->id);
        Log::warning("Job [{$this->batchId()}] Dispatching notification for chunk #{$this->chunk_number}.");
        $this->user->notify($notification);
        Log::warning("Job [{$this->batchId()}] Dispatched notification for chunk #{$this->chunk_number}.");
		// fuerza a PHP a soltar buffers y sockets
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}

		// paranoia sana
		flush();
		gc_collect_cycles();
    }
}
