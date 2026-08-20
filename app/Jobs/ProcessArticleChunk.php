<?php

namespace App\Jobs;

use App\Events\ImportStatusUpdated;
use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\article\ImportFailureHandler;
use App\Imports\ArticleImport;
use App\Models\ArticleImportResult;
use App\Models\ArticleImportResultObservation;
use App\Models\ImportHistory;
use App\Models\ImportResultObservation;
use App\Models\ImportStatus;
use App\Models\User;
use App\Notifications\ImportStatusNotification;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;

class ProcessArticleChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $csv_path, $columns, $blank_flags, $create_and_edit, $start_row, $finish_row,
              $provider_id, $user_id, $auth_user_id, $import_status_id, $import_history_id, $chunk_number, $observations, $start_offset, $inicio_chunk, $actualizar_articulos_de_otro_proveedor, $actualizar_proveedor, $permitir_provider_code_repetido, $permitir_provider_code_repetido_en_multi_providers, $actualizar_por_provider_code, $user;

    /**
     * Modo elegido por el usuario para interpretar el punto en columnas numéricas
     * ambiguas (grupo 239, prompt 04): 'auto' | 'siempre_miles' | 'siempre_decimal'.
     */
    protected $interpretacion_punto;

    /**
     * Decisión del usuario para filas repetidas DENTRO del propio archivo (prompt 04,
     * grupo 265): 'ultima_gana' | 'productos_distintos'.
     */
    protected $filas_repetidas_del_archivo;

    /**
     * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026): si los costos de la planilla vienen
     * con IVA adentro. Viaja hasta ProcessRow, que es quien hace el back-out.
     */
    protected $precios_incluyen_iva = false;

    // public $timeout = 5; // 30 minutos por chunk, ajustable
    public $timeout = 1800; // 30 minutos por chunk, ajustable
    public $tries = 1;
    
	public function __construct(
            $csv_path,
            $columns,
            $blank_flags,
            $create_and_edit,
            $start_row, 
            $finish_row, 
            $provider_id, 
            $user_id, 
            $auth_user_id, 
            $import_status_id, 
            $import_history_id, 
            $chunk_number, 
            $start_offset, 
            
            $actualizar_articulos_de_otro_proveedor,
            $actualizar_proveedor,
            $permitir_provider_code_repetido,
            $permitir_provider_code_repetido_en_multi_providers,
            $actualizar_por_provider_code,

            $interpretacion_punto = 'auto',
            $filas_repetidas_del_archivo = 'ultima_gana',

            /*
             * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026): si los costos de la planilla
             * vienen con IVA adentro. Va último y con default para no romper los jobs que ya
             * estuvieran encolados y serializados sin esta clave.
             */
            $precios_incluyen_iva = false
    ) {

        $this->csv_path                                     = $csv_path;
        $this->columns                                      = $columns;
        // Prompt 310: flags "permitir_valores_en_blanco" por columna, propagados hasta ProcessRow.
        $this->blank_flags                                  = $blank_flags;
        $this->create_and_edit                              = $create_and_edit;
        $this->start_row                                    = $start_row;
        $this->finish_row                                   = $finish_row;
        $this->provider_id                                  = $provider_id;
        $this->user_id                                      = $user_id;
        $this->auth_user_id                                 = $auth_user_id;
        $this->import_status_id                             = $import_status_id;
        $this->import_history_id                            = $import_history_id;
        $this->chunk_number                                 = $chunk_number;
        $this->start_offset                                 = $start_offset;

        $this->actualizar_articulos_de_otro_proveedor               = $actualizar_articulos_de_otro_proveedor;
        $this->actualizar_proveedor                                 = $actualizar_proveedor;
        $this->permitir_provider_code_repetido                      = $permitir_provider_code_repetido;
        $this->permitir_provider_code_repetido_en_multi_providers   = $permitir_provider_code_repetido_en_multi_providers;
        $this->actualizar_por_provider_code                         = $actualizar_por_provider_code;

        $this->interpretacion_punto                                 = $interpretacion_punto;
        $this->filas_repetidas_del_archivo                          = $filas_repetidas_del_archivo;
        $this->precios_incluyen_iva                                 = $precios_incluyen_iva;

        $this->observations = '';

        $this->user = User::find($this->user_id);

        $this->inicio_chunk = microtime(true);
    }

    public function batchId() {
        return optional($this->batch())->id ?? 'NO_BATCH';
    }

    public function handle()
    {
        Log::info('Procesando chunk', [
            'chunk_number' => $this->chunk_number,
            'pid' => getmypid(),
        ]);
        // Log::warning("INICIO Job Chunk #{$this->chunk_number} del lote {$this->batchId()}. PID: " . getmypid());

        /*
         * Descartar el indice de articulos memoizado en RAM de este proceso ANTES de
         * instanciar ProcessRow (vía crear_article_import()/collection() mas abajo).
         *
         * Con varios workers en la misma cola, la RAM de este proceso puede haber
         * quedado cargada desde un lote anterior (de este mismo chunk-worker) y no
         * reflejar lo que agregaron otros workers en el cache compartido mientras
         * tanto. Sin este reset, el lote actual buscaría articulos contra un indice
         * desactualizado y terminaría creando duplicados (ver prompt 03, grupo 229 —
         * caso Servian: mismo bar_code creado dos veces por dos workers distintos).
         */
        ArticleIndexCache::reset_runtime((int) $this->user_id);

        $inicio = microtime(true);

        /* Best effort: si el memory_limit del worker es bajo, lo subimos (solo hacia arriba). En hosting
           compartido puede estar capado y el ini_set fallar silenciosamente; no pasa nada si no aplica. */
        $this->asegurar_memoria_minima();

        try {

            $this->import_status = ImportStatus::find($this->import_status_id);
            $this->import_history = ImportHistory::find($this->import_history_id);

            if ($this->import_status->status == 'fallo') {
                return;
            }

            /* Guard de memoria: si ya arrancamos cerca del techo, fallamos con un mensaje accionable AHORA
               en vez de crashear crudo por OOM (que la cola recién detecta ~40 min después). */
            $this->verificar_memoria_disponible();

            /*
                Feedback inmediato al usuario:
                - Apenas arranca cualquier chunk, marcamos el import como "en_proceso" (si corresponde).
                - El pasaje a "completado" se resuelve al final del chunk, luego de incrementar contadores.
            */
            $this->set_import_history_status_at_chunk_start();
            $this->set_import_status_at_chunk_start();

            // Notifico inmediatamente para que el usuario vea el status sin esperar a que termine el chunk
            $this->notificar_import_status();
            
            // ArticleImportHistory es la clase que guarda toda la info del chunk, cada ArticleImportHistory reprecenta a un chunk
            $this->crear_article_import_result();

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


            // $this->get_article_import_result();

            $this->recargar_article_import_result();

            $this->guardar_tiempos_de_ejecucion_en_article_import_result($observations);

            $this->update_import_status();
            $this->update_import_history();

            /*
                Notificación final:
                - Se emite luego de persistir contadores/status en ImportStatus e ImportHistory,
                  para que el usuario reciba el estado final con propiedades actualizadas.
            */
            $inicio_noti = microtime(true);
            $this->notificar_import_status();
            $fin = microtime(true);
            $dur = $fin - $inicio_noti;
            $this->add_observation('notificar_import_status en '.number_format($dur, 2, '.', '').' seg');

            // $this->guardar_tiempos_de_ejecucion($inicio, $observations);


        } catch (\Throwable $e) {

            $duracion = microtime(true) - $inicio;
            Log::error('Error al importar, desde ProcessArticleChunk handle. Chunk #' . $this->chunk_number
                . '. Tardó ' . number_format($duracion, 3) . ' seg. Mensaje: ' . $e->getMessage()
                . ' — ' . $e->getFile() . ':' . $e->getLine());

            /* Un único punto de fallo (idempotente): marca history+status en 'fallo', guarda motivo humano
               + log completo + lote, notifica y limpia cache. Ver ImportFailureHandler. */
            ImportFailureHandler::desde_excepcion(
                $this->import_history_id,
                $this->import_status_id,
                $this->user_id,
                $e,
                $this->chunk_number,
                array('start_row' => $this->start_row, 'finish_row' => $this->finish_row)
            );

            throw $e; // detiene la chain
        }
    }

    /**
     * Se ejecuta cuando el job falla en forma definitiva, INCLUIDO cuando el proceso muere sin llegar al
     * catch del handle() (OOM, timeout de proceso, worker reiniciado → MaxAttemptsExceededException que
     * lanza el Worker, no el handle). Corre en un proceso fresco: puede escribir en la BD aunque el
     * proceso anterior haya muerto por falta de memoria.
     *
     * Idempotente vía ImportFailureHandler: si el catch del handle() ya lo marcó, esto es no-op.
     *
     * @param  \Throwable  $e
     * @return void
     */
    public function failed($e)
    {
        ImportFailureHandler::desde_excepcion(
            $this->import_history_id,
            $this->import_status_id,
            $this->user_id,
            $e,
            $this->chunk_number,
            array('start_row' => $this->start_row, 'finish_row' => $this->finish_row)
        );
    }

    /**
     * Sube el memory_limit a un piso razonable si está por debajo (nunca lo baja). Best effort:
     * en hosting compartido puede estar capado y el ini_set no tener efecto.
     *
     * @return void
     */
    private function asegurar_memoria_minima()
    {
        $piso_bytes = 512 * 1024 * 1024; // 512 MB
        $actual = $this->memory_limit_bytes();

        // -1 => sin límite: no tocar. 0 => no se pudo parsear: no tocar.
        if ($actual > 0 && $actual < $piso_bytes) {
            @ini_set('memory_limit', '512M');
        }
    }

    /**
     * Si ya estamos usando más del 85% del memory_limit al arrancar el lote, corta con un mensaje claro.
     * Mejor un fallo accionable inmediato que un OOM crudo que la cola recién detecta ~40 min después.
     *
     * @return void
     */
    private function verificar_memoria_disponible()
    {
        $limite = $this->memory_limit_bytes();

        if ($limite <= 0) {
            return; // sin límite o no parseable: nada que verificar
        }

        $en_uso = memory_get_usage(true);
        $umbral = (int) ($limite * 0.85);

        if ($en_uso > $umbral) {
            $usados_mb = number_format($en_uso / 1048576, 0);
            $limite_mb = number_format($limite / 1048576, 0);

            throw new \RuntimeException(
                'Memoria insuficiente al iniciar el lote ' . $this->chunk_number . ': '
                . $usados_mb . ' MB usados de ' . $limite_mb . ' MB. '
                . 'Bajá el tamaño del archivo o la variable ARTICLE_EXCEL_CHUNK_SIZE, o subí el memory_limit del worker.'
            );
        }
    }

    /**
     * Devuelve el memory_limit vigente en bytes. -1 (sin límite) => -1. Sin poder parsear => 0.
     *
     * @return int
     */
    private function memory_limit_bytes()
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $unidad = strtoupper(substr($raw, -1));
        $numero = (int) $raw;

        switch ($unidad) {
            case 'G':
                return $numero * 1024 * 1024 * 1024;
            case 'M':
                return $numero * 1024 * 1024;
            case 'K':
                return $numero * 1024;
            default:
                // valor en bytes puro
                if (is_numeric($raw)) {
                    return (int) $raw;
                }
                return 0;
        }
    }

    function recargar_article_import_result() {
        $this->import_result = ArticleImportResult::find($this->import_result->id);
    }

    function add_observation($text) {
        $this->observations .= $text .' - ';
    }

    function get_row_from_csv() {

        $chunkRows = [];
        $file_path = $this->csv_path;

        Log::warning("Abriendo y leyendo archivo CSV.");
        Log::info("start_offset: ".$this->start_offset);
        Log::info("start_row: ".$this->start_row);
        Log::info("finish_row: ".$this->finish_row);

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

        Log::info(count($chunkRows).' extraidas del csv');

        // Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Archivo CSV leído y cerrado.");
        // Log::warning("Job [{$this->batchId()}] PID: " . getmypid() . " - Invocando lógica de importación (importer->collection).");

        return $chunkRows;
    }


    function crear_article_import_result() {

        $this->import_result = ArticleImportResult::create([
            'import_history_id'    => $this->import_history->id,
            'chunk_number'         => $this->chunk_number,
        ]);
    }

    function crear_article_import() {

        try {

            $this->importer = new ArticleImport(
                $this->columns,
                $this->blank_flags,
                $this->create_and_edit,
                $this->start_row,
                $this->finish_row,
                $this->provider_id,
                $this->user,
                $this->auth_user_id,
                $this->csv_path,
                $this->chunk_number,
                $this->import_history->registrar_art_cre,
                $this->import_history->registrar_art_act,
                $this->import_result->id,
                $this->import_history->id,

                $this->actualizar_articulos_de_otro_proveedor,
                $this->actualizar_proveedor,
                $this->permitir_provider_code_repetido,
                $this->permitir_provider_code_repetido_en_multi_providers,
                $this->actualizar_por_provider_code,

                $this->interpretacion_punto,
                $this->filas_repetidas_del_archivo,
                $this->precios_incluyen_iva,
            );

        } catch (\Throwable $e) {

            Log::error('Error al importar, desde ProcessArticleChunk crear_article_import');

            throw $e;
        }
    }

    function repasar_variantes() {

        Artisan::call('set_article_address_stock_from_variants', [
            'user_id' => $this->user->id
        ]);
    }

    function update_import_status() {
        $inicio = microtime(true);

        // Actualización ATÓMICA (evita que los workers se pisen)
        DB::table('import_statuses')
            ->where('id', $this->import_status_id)
            ->update([
                'processed_chunks'   => DB::raw('processed_chunks + 1'),
                'articles_match'     => DB::raw('articles_match + ' . (int) $this->import_result->articles_match),
                'created_models'     => DB::raw('created_models + ' . (int) $this->import_result->created_count),
                'updated_models'     => DB::raw('updated_models + ' . (int) $this->import_result->updated_count),
                'filas_procesadas'   => DB::raw('filas_procesadas + ' . (int) $this->import_result->filas_procesadas),
                'articles_repetidos' => DB::raw('articles_repetidos + ' . (int) $this->import_result->articles_repetidos),
            ]);

        // Traigo el estado actualizado y seteo status correctamente (luego de incrementar contadores)
        $import_status = ImportStatus::select('id', 'processed_chunks', 'total_chunks', 'status')
            ->find($this->import_status_id);

        if ($import_status) {
            $new_status = 'en_proceso';

            if ((int) $import_status->processed_chunks >= (int) $import_status->total_chunks) {
                $new_status = 'completado';
            }

            if ($import_status->status !== $new_status) {
                ImportStatus::where('id', $this->import_status_id)->update([
                    'status' => $new_status,
                ]);
            }
        }

        $fin = microtime(true);
        $dur = $fin - $inicio;
        $this->add_observation('update_import_status en ' . number_format($dur, 2, '.', '') . ' seg');
    }

    /**
     * Setea el status de la importación al comienzo del chunk para dar feedback inmediato.
     * - Si aún no terminó, lo pasa a "en_proceso".
     * - Si ya estaba terminado (por cualquier motivo), mantiene/ajusta a "completado".
     *
     * Importante: no incrementa contadores; solo gestiona `status`.
     */
    private function set_import_status_at_chunk_start(): void
    {
        // Traigo el estado actualizado para decidir el status de forma consistente
        $import_status = ImportStatus::select('id', 'processed_chunks', 'total_chunks', 'status')
            ->find($this->import_status_id);

        if (!$import_status) {
            return;
        }

        // Defino el nuevo status según progreso actual (sin sumar el chunk en curso)
        $new_status = 'en_proceso';

        if ((int) $import_status->processed_chunks >= (int) $import_status->total_chunks) {
            $new_status = 'completado';
        }

        if ($import_status->status !== $new_status) {
            ImportStatus::where('id', $this->import_status_id)->update([
                'status' => $new_status,
            ]);
        }
    }

    /**
     * Setea el status del historial de importación al comienzo del chunk.
     * - Si aún no terminó, lo pasa a "en_proceso".
     * - Si ya estaba terminado (por cualquier motivo), mantiene/ajusta a "terminado".
     *
     * Importante: no incrementa contadores; solo gestiona `status`.
     * No se notifica al usuario desde acá (la notificación inmediata es solo para ImportStatus).
     */
    private function set_import_history_status_at_chunk_start(): void
    {
        // Traigo el estado actualizado para decidir el status de forma consistente
        $import_history = ImportHistory::select('id', 'processed_chunks', 'total_chunks', 'status')
            ->find($this->import_history_id);

        if (!$import_history) {
            return;
        }

        // Defino el nuevo status según progreso actual (sin sumar el chunk en curso)
        $new_status = 'en_proceso';

        if ((int) $import_history->processed_chunks >= (int) $import_history->total_chunks) {
            $new_status = 'terminado';
        }

        if ($import_history->status !== $new_status) {
            ImportHistory::where('id', $this->import_history_id)->update([
                'status' => $new_status,
            ]);
        }
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


        // Actualización ATÓMICA (evita que los workers se pisen)
        DB::table('import_histories')
            ->where('id', $this->import_history_id)
            ->update([
                'processed_chunks'                   => DB::raw('processed_chunks + 1'),
                'articles_match'                     => DB::raw('articles_match + ' . (int) $this->import_result->articles_match),
                'created_models'                     => DB::raw('created_models + ' . (int) $this->import_result->created_count),
                'updated_models'                     => DB::raw('updated_models + ' . (int) $this->import_result->updated_count),
                'filas_procesadas'                   => DB::raw('filas_procesadas + ' . (int) $this->import_result->filas_procesadas),
                'articles_repetidos'                 => DB::raw('articles_repetidos + ' . (int) $this->import_result->articles_repetidos),
                /* Acumular artículos creados con código repetido de cada chunk. */
                'created_with_repeated_code_count'   => DB::raw('created_with_repeated_code_count + ' . (int) $this->import_result->created_with_repeated_code_count),
            ]);

        // Traigo el estado actualizado y seteo status correctamente
        $import_history = ImportHistory::find($this->import_history_id);

        if ($import_history) {
            $new_status = 'en_proceso';

            if ((int) $import_history->processed_chunks >= (int) $import_history->total_chunks) {
                $new_status = 'terminado';
            }

            if ($import_history->status !== $new_status) {
                ImportHistory::where('id', $this->import_history_id)->update([
                    'status' => $new_status,
                ]);
            }
        }

    }

    function guardar_tiempos_de_ejecucion_en_article_import_result($observations) {

        $fin = microtime(true);

        $dur = $fin - $this->inicio_chunk;
        $this->import_result->terminado_at = Carbon::now();
        $this->import_result->duration = $dur;

        /* Instrumentación: pico de memoria del proceso durante este lote. Si el pico sube lote a lote
           hacia el memory_limit, el cuelgue del lote ~20 es OOM. */
        $limite_bytes = $this->memory_limit_bytes();
        $this->import_result->peak_memory_mb = round(memory_get_peak_usage(true) / 1048576, 2);
        $this->import_result->memory_limit_mb = $limite_bytes > 0 ? (int) round($limite_bytes / 1048576) : null;

        Log::info('Lote ' . $this->chunk_number . ' — pico memoria: ' . $this->import_result->peak_memory_mb
            . ' MB de ' . ($this->import_result->memory_limit_mb ?: 'sin límite') . ' MB');

        $this->import_result->save();

        $rows_observations              = $observations['rows_observations'];
        $article_import_observations    = $observations['article_import_observations'];

        // Log::info('rows_observations');
        // Log::info($rows_observations);
        // Log::info('article_import_observations');
        // Log::info($article_import_observations);

        usort($rows_observations, function ($a, $b) {
            $total_a = isset($a['duration']) ? $a['duration'] : 0;
            $total_b = isset($b['duration']) ? $b['duration'] : 0;

            // Orden descendente (más lento primero)
            return $total_b <=> $total_a;
        });

        $insert_data = [];
        foreach ($rows_observations as $row_observations) {

            $insert_data[] = [
                'article_import_result_id'  => $this->import_result->id,
                'duration'                  => isset($row_observations['duration']) ? $row_observations['duration'] : 0,
                'fila'                      => $row_observations['fila'],
                'procesos'                  => isset($row_observations['procesos']) ? json_encode($row_observations['procesos']) : null,
            ];
        }

        /* 

            Cada ArticleImportResultObservation equivale a una fila del excel, y
            y cada ArticleImportResultObservation pertenece a un article_import_result, que equivale a un chunk
        */ 
        ArticleImportResultObservation::insert($insert_data); 

        usort($article_import_observations['procesos'], function ($a, $b) {
            $total_a = isset($a['duration']) ? $a['duration'] : 0;
            $total_b = isset($b['duration']) ? $b['duration'] : 0;

            // Orden descendente (más lento primero)
            return $total_b <=> $total_a;
        });
        $this->import_result->article_import_observations = json_encode($article_import_observations);
        $this->import_result->save();
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
