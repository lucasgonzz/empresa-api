<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Imports\ArticleImport;
use App\Models\ArticleImportResult;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Notifications\ImportStatusNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ProcessArticleChunk implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $import_uuid, $archivo_excel_path, $columns, $create_and_edit, $start_row, $finish_row,
              $provider_id, $user, $auth_user_id, $no_actualizar_articulos_de_otro_proveedor, $import_status_id, $import_history_id, $chunk_number;

    public $timeout = 1200; // 20 minutos por chunk, ajustable
    public $tries = 1;
    
    public function __construct($import_uuid, $archivo_excel_path, $columns, $create_and_edit, $no_actualizar_articulos_de_otro_proveedor, $start_row, $finish_row, $provider_id, $user, $auth_user_id, $import_status_id, $import_history_id, $chunk_number)
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
        $this->import_history_id = $import_history_id;
        $this->chunk_number = $chunk_number;
    }

    public function handle()
    {
        $inicio = microtime(true);

        try {

            $this->import_status = ImportStatus::find($this->import_status_id);
            $this->import_history = ImportHistory::find($this->import_history_id);

            if ($this->import_status->status == 'fallo') {
                return;
            }

            if (env('APP_ENV') == 'local') {
                // sleep(2);
            }

            $extension = pathinfo($this->archivo_excel_path, PATHINFO_EXTENSION);

            $ext = strtolower($extension);

            if ($ext == 'xls') {
                $this->reader_type = ExcelFormat::XLS;
            } else if ($ext == 'xlsx') {
                $this->reader_type = ExcelFormat::XLSX; 
            } else {
                $this->reader_type = ExcelFormat::XLSX; // fallback
            }

            $this->ejecutar_article_import();

            $this->get_article_import_result();

            $this->update_import_status();

            $this->update_import_history();

            // $this->limpiar_import_result();

            // $this->notificar_usuario();

            // $this->repasar_variantes();

            $fin = microtime(true);

            $duracion = $fin - $inicio;
            Log::info('Tardo en procesarce: '.number_format($duracion, 3).' segundos');

        } catch (\Throwable $e) {


            Log::error('Error al importar, desde ProcessArticleChunk handle');
            Log::error('Mensaje: ' . $e->getMessage());
            Log::error('Archivo: ' . $e->getFile());
            Log::error('Línea: ' . $e->getLine());
            // Log::error('Trace: ' . $e->getTraceAsString());


            $fin = microtime(true);

            $duracion = $fin - $inicio;

            Log::info('Tardo en procesarce: '.number_format($duracion, 3).' segundos');

            $this->set_import_history_error($e);

            // Registra el progreso y errores en Import History
            // ArticleImportHelper::create_import_history($this->user, $this->auth_user_id, $this->provider_id, $this->created_models, $this->updated_models, $this->columns, $this->archivo_excel_path, $error_message, $this->articulos_creados, $this->articulos_actualizados, $this->updated_props);

            ArticleImportHelper::error_notification($this->user, null, $e->getMessage());

            $this->notificar_error_input_status($e->getMessage());

            throw $e; // ✅ Esto detiene la chain
        }
    }

    function set_import_history_error($e) {
        $this->import_history->status = 'error';

        $error_message = '';
        $error_message .= ' | Mensaje: ' . $e->getMessage();
        $error_message .= ' | Archivo: ' . $e->getFile();
        $error_message .= ' | Línea: ' . $e->getLine();
        $error_message .= ' | Trace: ' . $e->getTraceAsString();

        $this->import_history->error_message = $error_message;
        $this->import_history->save();
    }

    function ejecutar_article_import() {

        try {

            Excel::import(new ArticleImport(
                $this->import_uuid,
                $this->columns, $this->create_and_edit,
                $this->no_actualizar_articulos_de_otro_proveedor,
                $this->start_row, $this->finish_row,
                $this->provider_id, $this->user,
                $this->auth_user_id, $this->archivo_excel_path,
                $this->chunk_number,
            ), $this->archivo_excel_path, null, $this->reader_type);

        } catch (\Throwable $e) {
            
            Log::error('Error al importar, desde ProcessArticleChunk ejecutar_article_import');

            throw $e;
        }
    }

    function guardar_error_en_import_history($e) {

        $this->import_history->status = 'error';

            Log::error('Mensaje: ' . $e->getMessage());
            Log::error('Archivo: ' . $e->getFile());
            Log::error('Línea: ' . $e->getLine());
    }

    function notificar_usuario() {
        ArticleImportHelper::enviar_notificacion($this->user, count($this->created_ids), count($this->updated_props_by_article));

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

        // $import_status = ImportStatus::find($this->import_status_id);
        $this->import_status->error_message = $error;
        $this->import_status->status = 'fallo';
        $this->import_status->save();

        $this->user->notify(new ImportStatusNotification($this->import_status, $this->user->id));
    }

    function update_import_status() {

        // $this->import_status = ImportStatus::find($this->import_status_id);
        $this->import_status->processed_chunks++;
        $this->import_status->articles_match += $this->import_result->articles_match;
        $this->import_status->created_models += $this->import_result->created_count;
        $this->import_status->updated_models += $this->import_result->updated_count;
        Log::info('import_result->articles_match: '.$this->import_result->articles_match);
        
        if ($this->import_status->processed_chunks == $this->import_status->total_chunks) {
            $this->import_status->status = 'completado';
        } else {
            $this->import_status->status = 'en_proceso';
        }
        $this->import_status->save();


        $this->user->notifyNow(new ImportStatusNotification($this->import_status, $this->user->id));
    }

    function update_import_history() {

        // Log::info('update_import_history: ');
        // Log::info('import_history: ');
        // Log::info((array)$this->import_history);
        // Log::info('created_ids: ');
        // Log::info((array)$this->created_ids);

        $this->import_history->created_models  = count($this->created_ids);
        $this->import_history->updated_models  = count($this->updated_props_by_article);
        $this->import_history->articles_match  += $this->import_result->articles_match;
        $this->import_history->status          = 'en_proceso';
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
    }

    function get_article_import_result() {

        // 1) Traer el ultimo import_results creado 
        $this->import_result = ArticleImportResult::with([
                                                    'articulos_creados:id', // sólo id para no cargar de más
                                                    'articulos_actualizados' => function ($q) {
                                                        $q->select('articles.id'); // pivot vendrá con updated_props
                                                    },
                                                ])
                                                ->where('import_uuid', $this->import_uuid)
                                                ->orderBy('id', 'DESC')
                                                ->first();

                                            // 2) Consolidar
        $this->created_ids = [];
        $this->updated_props_by_article = []; // [article_id => array props merged]



        // 2.a) CREADOS
        foreach ($this->import_result->articulos_creados as $art) {
            $this->created_ids[] = (int)$art->id;
        }

        // 2.b) ACTUALIZADOS (merge si un article_id apareció en más de un chunk)
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

        // Unificar IDs creados
        $this->created_ids = array_values(array_unique($this->created_ids));

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
