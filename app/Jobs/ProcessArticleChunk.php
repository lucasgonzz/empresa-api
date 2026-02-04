<?php

namespace App\Jobs;

use App\Events\ImportStatusUpdated;
use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Imports\ArticleImport;
use App\Models\ArticleImportResult;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Notifications\ImportStatusNotification;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ProcessArticleChunk implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $import_uuid, $csv_path, $columns, $create_and_edit, $start_row, $finish_row,
              $provider_id, $user, $auth_user_id, $no_actualizar_articulos_de_otro_proveedor, $actualizar_proveedor, $import_status_id, $import_history_id, $chunk_number, $observations, $start_offset;

    // public $timeout = 5; // 30 minutos por chunk, ajustable
    public $timeout = 1800; // 30 minutos por chunk, ajustable
    public $tries = 1;
    
	public function __construct($import_uuid, $csv_path, $columns, $create_and_edit, $no_actualizar_articulos_de_otro_proveedor, $actualizar_proveedor, $start_row, $finish_row, $provider_id, $user, $auth_user_id, $import_status_id, $import_history_id, $chunk_number, $start_offset = null)
    {

        $this->import_uuid = $import_uuid;
        $this->csv_path = $csv_path;
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->no_actualizar_articulos_de_otro_proveedor = $no_actualizar_articulos_de_otro_proveedor;
        $this->actualizar_proveedor = $actualizar_proveedor;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->provider_id = $provider_id;
        $this->user = $user;
        $this->auth_user_id = $auth_user_id;
        $this->import_status_id = $import_status_id;
        $this->import_history_id = $import_history_id;
        $this->chunk_number = $chunk_number;
        $this->start_offset = $start_offset;

        $this->observations = '';
    }

    public function batchId() {
        return optional($this->batch())->id ?? 'NO_BATCH';
    }

    public function handle()
    {
        Log::warning("INICIO Job Chunk #{$this->chunk_number}");
        // Log::warning("INICIO Job Chunk #{$this->chunk_number} del lote {$this->batchId()}. PID: " . getmypid());

        $inicio = microtime(true);


        try {

            $this->import_status = ImportStatus::find($this->import_status_id);
            $this->import_history = ImportHistory::find($this->import_history_id);

            if ($this->import_status->status == 'fallo') {
                return;
            }
            


            $inicio_excel = microtime(true);
            $this->crear_article_import();
    
            $chunkRows = $this->get_row_from_csv();

            $collection = new Collection($chunkRows);
            
            // En observations traigo el detalle del tiempo tardado para cada paso de ArticleImport
            $observations = $this->importer->collection($collection);

            unset($collection, $chunkRows); // Liberar memoria explícitamente
            $fin_excel = microtime(true);
            $dur = $fin_excel - $inicio_excel;
            $this->add_observation('ArticleImport procesado desde chunk en '.number_format($dur, 2, '.', '').' seg');


            $this->get_article_import_result();

            $this->update_import_status();

            $inicio_noti = microtime(true);
            $this->notificar_import_status();
            $fin = microtime(true);
            $dur = $fin - $inicio_noti;
            $this->add_observation('notificar_import_status en '.number_format($dur, 2, '.', '').' seg');

            $this->update_import_history();

            $this->guardar_tiempos_de_ejecucion($inicio, $observations);


        } catch (\Throwable $e) {


            Log::error('Error al importar, desde ProcessArticleChunk handle');
            Log::error('Mensaje: ' . $e->getMessage());
            Log::error('Archivo: ' . $e->getFile());
            Log::error('Línea: ' . $e->getLine());
            // Log::error('Trace: ' . $e->getTraceAsString());


            $fin = microtime(true);

            $duracion = $fin - $inicio;
            Log::warning("FIN Job Chunk #{$this->chunk_number} del lote {$this->batchId()}. PID: " . getmypid() . " - Tardó en procesarse: " . number_format($duracion, 3) . " segundos");

            Log::info('Tardo en procesarce: '.number_format($duracion, 3).' segundos');

            $this->set_import_history_error($e);

            $this->notificar_error_input_status($e->getMessage());

            $error_message = $this->get_full_error($e);
            ArticleImportHelper::error_notification($this->user, null, $error_message);


            throw $e; // ✅ Esto detiene la chain
        }
    }

    function add_observation($text) {
        $this->observations .= $text .' - ';
    }

    function get_row_from_csv() {

        $chunkRows = [];
        $file_path = $this->csv_path;

        Log::warning("Abriendo y leyendo archivo CSV.");

        if (($handle = fopen($file_path, "r")) !== false) {

            // Si tenemos offset, arrancamos directo en la fila del chunk (mucho más rápido)
            if (!is_null($this->start_offset) && is_numeric($this->start_offset) && $this->start_offset >= 0) {
                fseek($handle, (int)$this->start_offset);
                $currentRow = $this->start_row;
            } else {
                // Fallback: comportamiento anterior
                $currentRow = 1;
            }

            while (($data = fgetcsv($handle, 0, ",")) !== false) {

                if ($currentRow >= $this->start_row && $currentRow <= $this->finish_row) {
                    $chunkRows[] = $data;
                }

                if ($currentRow >= $this->finish_row) {
                    break;
                }

                $currentRow++;
            }

            fclose($handle);
        }

        Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Archivo CSV leído y cerrado.");
        Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Invocando lógica de importación (importer->collection).");

        return $chunkRows;
    }

    // function get_row_from_csv() {
        
    //     $chunkRows = [];

    //     $file_path = $this->csv_path;
    //     // $file_path = storage_path('app/' . $this->csv_path);

    //     Log::warning("Abriendo y leyendo archivo CSV.");
    //     // Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Abriendo y leyendo archivo CSV.");
    //     if (($handle = fopen($file_path, "r")) !== FALSE) {
    //         $currentRow = 1;
    //         while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
    //             if ($currentRow >= $this->start_row && $currentRow <= $this->finish_row) {
    //                 $chunkRows[] = $data;
    //             }
    //             if ($currentRow > $this->finish_row) {
    //                 break;
    //             }
    //             $currentRow++;
    //         }
    //         fclose($handle);
    //     }
    //     Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Archivo CSV leído y cerrado.");

    //     Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Invocando lógica de importación (importer->collection).");

    //     return $chunkRows;
    // }

    function set_import_history_error($e) {
        $this->import_history->status = 'error';

        $error_message = $this->get_full_error($e);

        $this->import_history->error_message = $error_message;
        $this->import_history->save();
    }

    function get_full_error($e) {

        $error_message = '';
        $error_message .= ' | Mensaje: ' . $e->getMessage();
        $error_message .= ' | Archivo: ' . $e->getFile();
        $error_message .= ' | Línea: ' . $e->getLine();
        // $error_message .= ' | Trace: ' . $e->getTraceAsString();

        return $error_message;
    }

    function crear_article_import() {

        try {

            $this->importer = new ArticleImport(
                $this->import_uuid,
                $this->columns,
                $this->create_and_edit,
                $this->no_actualizar_articulos_de_otro_proveedor,
                $this->actualizar_proveedor,
                $this->start_row,
                $this->finish_row,
                $this->provider_id,
                $this->user,
                $this->auth_user_id,
                $this->csv_path,
                $this->chunk_number,
                $this->import_history->registrar_art_cre,
                $this->import_history->registrar_art_act,
            );

            // Excel::import(new ArticleImport(
            //     $this->import_uuid,
            //     $this->columns, $this->create_and_edit,
            //     $this->no_actualizar_articulos_de_otro_proveedor,
            //     $this->start_row, $this->finish_row,
            //     $this->provider_id, $this->user,
            //     $this->auth_user_id, $this->archivo_excel_path,
            //     $this->chunk_number,
            // ), $this->archivo_excel_path, null, $this->reader_type);

        } catch (\Throwable $e) {
            
            Log::error('Error al importar, desde ProcessArticleChunk crear_article_import');

            throw $e;
        }
    }

    function guardar_error_en_import_history($e) {

        $this->import_history->status = 'error';

            Log::error('Mensaje: ' . $e->getMessage());
            Log::error('Archivo: ' . $e->getFile());
            Log::error('Línea: ' . $e->getLine());
    }


    function repasar_variantes() {

        Artisan::call('set_article_address_stock_from_variants', [
            'user_id' => $this->user->id
        ]);
    }

    function limpiar_import_result() {
        ArticleImportResult::where('import_uuid', $this->import_uuid)->delete();
    }

    function notificar_error_input_status($error) {

        $this->import_status->error_message = $error;
        $this->import_status->status = 'fallo';
        $this->import_status->save();

        $this->user->notify(new ImportStatusNotification($this->import_status, $this->user->id));
		
    }

    function update_import_status() {
        $inicio = microtime(true);
        $this->import_status->processed_chunks++;
        $this->import_status->articles_match += $this->import_result->articles_match;
        $this->import_status->created_models += $this->import_result->created_count;
        $this->import_status->updated_models += $this->import_result->updated_count;
        $this->import_status->filas_procesadas += $this->import_result->filas_procesadas;
        Log::info('import_result->articles_match: '.$this->import_result->articles_match);
        
        // Use >= as a safeguard in case of concurrent increments
        if ($this->import_status && $this->import_status->processed_chunks >= $this->import_status->total_chunks) {
            $this->import_status->status = 'completado';
        } else if ($this->import_status && $this->import_status->processed_chunks >= 1) {
            $this->import_status->status = 'en_proceso';
        }
        $this->import_status->save();
        
        // Notify the user with the fresh status
        // $notification = new ImportStatusNotification($this->import_status, $this->user->id);
        // Log::warning("Job [{$this->batchId()}] Dispatching notification for chunk #{$this->chunk_number}.");
        // $this->user->notify($notification);
        // Log::warning("Job [{$this->batchId()}] Dispatched notification for chunk #{$this->chunk_number}.");
      
        // fuerza a PHP a soltar buffers y sockets
        if (function_exists('fastcgi_finish_request')) {
          fastcgi_finish_request();
        }

        // paranoia sana
        flush();
        gc_collect_cycles();
        $this->import_status->save();

        $fin = microtime(true);
        $dur = $fin - $inicio;
        $this->add_observation('update_import_status en '.number_format($dur, 2, '.', '').' seg');

    }

    function notificar_import_status() {
        broadcast(
            new ImportStatusUpdated(
                $this->import_status->id,
                $this->user->id
            )
        );
        // $this->user->notifyNow(new ImportStatusNotification($this->import_status->id, $this->user->id));
    }

    function guardar_tiempos_de_ejecucion($inicio, $observations) {

        $fin = microtime(true);
        $duracion_chunk = $fin - $inicio;

        if (is_null($this->import_history->observations)) {
            $this->import_history->observations = '';
        }

        $text = 'TOTAL Chunk: '.number_format($duracion_chunk, 2, '.', '').' seg. ';
        $text .= $observations;
        $text .= $this->observations;

        // Log::info('observations que llegaron: '.$observations);


        $this->import_history->observations .= ' | '.$text; 
        $this->import_history->save(); 
    }

    function update_import_history() {

        // Log::info('update_import_history: ');
        // Log::info('import_history: ');
        // Log::info((array)$this->import_history);
        // Log::info('created_ids: ');
        // Log::info((array)$this->created_ids);

        $inicio = microtime(true);

        $this->import_history->created_models   += $this->import_result->created_count;
        $this->import_history->updated_models   += $this->import_result->updated_count;
        $this->import_history->articles_match   += $this->import_result->articles_match;
        $this->import_history->filas_procesadas += $this->import_result->filas_procesadas;
        $this->import_history->status           = 'en_proceso';
        $this->import_history->save();


        // 4) Adjuntar relaciones al ImportHistory definitivo
        if (!empty($this->created_ids)) {
            $this->import_history->articulos_creados()->syncWithoutDetaching($this->created_ids);
        }

        if (!empty($this->updated_props_by_article)) {
            $pivot_data = [];
            foreach ($this->updated_props_by_article as $this->article_id => $this->props_array) {
                $pivot_data[$this->article_id] = [
                    'updated_props' => json_encode($this->props_array, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                ];
            }
            $this->import_history->articulos_actualizados()->syncWithoutDetaching($pivot_data);
        }

        $fin = microtime(true);
        $dur = $fin - $inicio;
        $this->add_observation('update_import_history en '.number_format($dur, 2, '.', '').' seg');
    }

    function get_article_import_result() {

        $inicio = microtime(true);

        // 1) Traer el ultimo import_results creado 
        // $this->import_result = ArticleImportResult::with([
        $import_result = ArticleImportResult::where('import_uuid', $this->import_uuid)
                                                ->orderBy('id', 'DESC');

        if ($this->import_history->registrar_art_cre) {
            $import_result->with('articulos_creados:id');
        }

        if ($this->import_history->registrar_art_act) {
            $import_result->with(['articulos_actualizados' => function ($q) {
                                    $q->select('articles.id'); // pivot vendrá con updated_props
                                },]);
        }

        $this->import_result = $import_result->first();

        
        // 2) Consolidar
        $this->created_ids = [];
        $this->updated_props_by_article = []; // [article_id => array props merged]



        // 2.a) CREADOS
        if ($this->import_history->registrar_art_cre) {
            foreach ($this->import_result->articulos_creados as $art) {
                $this->created_ids[] = (int)$art->id;
            }

            // Unificar IDs creados
            $this->created_ids = array_values(array_unique($this->created_ids));
        }

        // 2.b) ACTUALIZADOS (merge si un article_id apareció en más de un chunk)
        if ($this->import_history->registrar_art_act) {
            foreach ($this->import_result->articulos_actualizados as $art) {
                $pivot_json = $art->pivot->updated_props ?? '{}';
                $props = json_decode($pivot_json, true);
                if (!is_array($props)) {
                    $props = [];
                }

                $aid = (int)$art->id;

                if (!isset($this->updated_props_by_article[$aid])) {
                    $this->updated_props_by_article[$aid] = $props;
                } else {
                    // merge por clave (la última ocurrencia pisa)
                    $this->updated_props_by_article[$aid] = $this->mergeUpdatedProps(
                        $this->updated_props_by_article[$aid],
                        $props
                    );
                }
            }
        }

        $fin = microtime(true);
        $dur = $fin - $inicio;
        $this->add_observation('get_article_import_result en '.number_format($dur, 2, '.', '').' seg');
    }
    /**
     * Merge de updated_props de un mismo artículo cuando aparece en múltiples chunks.
     * - Estrategia: la última ocurrencia pisa campos anteriores.
     * - Mantiene subestructuras (price_types_data, stock_addresses, stock_global, __diff__...).
     */
    public function mergeUpdatedProps(array $base, array $incoming): array
    {
        // merge superficial clave por clave, incoming prevalece
        // si necesitás un merge profundo para arrays anidados, podés ajustar acá
        foreach ($incoming as $k => $v) {
            $base[$k] = $v;
        }
        return $base;
    }
}
