<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Cell;
use Throwable;

class InitExcelImport
{
    /**
     * Almacena los offsets de inicio por chunk para lectura eficiente del CSV.
     */
    protected $chunk_offsets = [];

    function importar($data)
    {
        $this->import_uuid              = $data['import_uuid'];
        $this->archivo_excel            = $data['archivo_excel'];
        $this->columns                  = $data['columns'];
        $this->create_and_edit          = $data['create_and_edit'];
        $this->start_row                = $data['start_row'];
        $this->finish_row               = $data['finish_row'];
        $this->provider_id              = $data['provider_id'];
        $this->user                     = $data['user'];
        $this->auth_user_id             = $data['auth_user_id'];
        $this->archivo_excel_path       = $data['archivo_excel_path'];
        $this->registrar_art_cre        = $data['registrar_art_cre'];
        $this->registrar_art_act        = $data['registrar_art_act'];

        $this->permitir_provider_code_repetido                      = $data['permitir_provider_code_repetido'];
        $this->permitir_provider_code_repetido_en_multi_providers   = $data['permitir_provider_code_repetido_en_multi_providers'];
        $this->actualizar_articulos_de_otro_proveedor               = $data['actualizar_articulos_de_otro_proveedor'];
        $this->actualizar_por_provider_code                         = $data['actualizar_por_provider_code'];
        $this->actualizar_proveedor                                 = $data['actualizar_proveedor'];

        $this->chunkSize    = config('app.ARTICLE_EXCEL_CHUNK_SIZE');
        $this->start        = $this->start_row;
        $this->jobs         = [];

        /*
         * Antes de preparar archivos y crear estados, validamos que el usuario
         * no tenga otra importación activa para evitar procesos solapados.
         */
        $importacion_en_curso = $this->tiene_importacion_en_curso();
        if ($importacion_en_curso) {
            return [
                'hubo_un_error' => true,
                'message' => 'Ya hay una importación en curso. Esperá a que termine para iniciar una nueva.',
                'info_to_show' => [],
                'functions_to_execute' => [],
            ];
        }

        $csv_ok = $this->armar_archivo_csv();

        if (!$csv_ok) {
            $link_tutorial = 'https://drive.google.com/drive/folders/1yMNfiJ57tXjtw_lSrnfTBzml-4-M0wWA?usp=drive_link';

            return [
                'hubo_un_error' => true,
                'message' => 'Error al abrir Excel',
                'info_to_show' => [
                    [
                        'title' => 'Formato de archivo invalido',
                        'parrafos' => [
                            'Copie el contenido de su archivo excel, en un nuevo archivo para no tener este problema.',
                            'Vea el siguiente video de referencia:',
                        ],
                    ],
                ],
                'functions_to_execute' => [
                    [
                        'btn_text' => 'Ver Tutorial (menos de 2min)',
                        'btn_variant' => 'primary',
                        'link' => $link_tutorial,
                    ],
                ],
            ];
        }

        $this->calcular_chunck();
        $this->chunk_offsets = $this->build_csv_chunk_offsets();
        $this->crear_import_status();
        $this->crear_import_history();
        $this->armar_jobs_de_chunks();

        /*
         * Selecciona la estrategia de despacho según la variable VPS del .env:
         *   - true  (VPS con Redis + Supervisor): batch paralelo, aprovecha múltiples workers.
         *   - false (hosting sin Redis/Supervisor): chain secuencial, compatible con cron queue:work.
         */
        if (config('app.VPS')) {
            $this->mandar_batch();
        } else {
            $this->mandar_chain();
        }

        return [
            'hubo_un_error' => false,
        ];
    }

    /**
     * Verifica si el usuario ya tiene una importación activa.
     *
     * @return bool true si existe una importación en preparación o en proceso.
     */
    function tiene_importacion_en_curso()
    {
        /*
         * Filtra por usuario y por estados activos para bloquear el inicio
         * de nuevas importaciones mientras haya una ejecución vigente.
         */
        return ImportHistory::where('user_id', $this->user->id)
            ->whereIn('status', ['en_preparacion', 'en_proceso'])
            ->exists();
    }

    /**
     * Despacha todos los chunks como un batch paralelo usando la conexión configurada en QUEUE_CONNECTION.
     * Requiere Redis (o database con tabla job_batches) y workers persistentes (Supervisor).
     * Al completarse el batch, dispara FinalizeArticleImport.
     */
    function mandar_batch()
    {
        // Usa la conexión configurada en el entorno (redis en VPS, database en hosting)
        $queue_connection = config('queue.default');

        Bus::batch($this->jobs)
            ->name('import_history_' . $this->import_history->id)
            ->onConnection($queue_connection)
            ->onQueue('default')
            ->then(function (Batch $batch) {

                Log::info('BATCH THEN ejecutado', [
                    'batch_id' => $batch->id,
                    'total_jobs' => $batch->totalJobs,
                    'pending_jobs' => $batch->pendingJobs,
                    'failed_jobs' => $batch->failedJobs,
                ]);

                FinalizeArticleImport::dispatch(
                    $this->user->id,
                    $this->import_history->id,
                    $this->import_status->id
                );
            })
            ->catch(function (Batch $batch, Throwable $e) {
                Log::error("Falló batch {$batch->id}: " . $e->getMessage());

                ArticleImportHelper::error_notification(
                    $this->user,
                    $e->getLine().'. Archivo: '.$e->getFile(),
                    $e->getMessage(),
                );
            })
            ->dispatch();
    }

    /**
     * Despacha los chunks como una cadena secuencial (Bus::chain).
     * Compatible con driver database y cron-based queue workers.
     * Agrega FinalizeArticleImport al final de la cadena para que se ejecute
     * una vez que todos los chunks hayan sido procesados.
     */
    function mandar_chain()
    {
        // Agrega el job de finalización al final de la cadena secuencial
        $jobs = $this->jobs;
        $jobs[] = new FinalizeArticleImport(
            $this->user->id,
            $this->import_history->id,
            $this->import_status->id
        );

        Bus::chain($jobs)->dispatch();
    }

    function calcular_chunck()
    {
        $this->total_rows = $this->finish_row - $this->start_row + 1;
        $this->total_chunks = (int) ceil($this->total_rows / $this->chunkSize);
    }

    function armar_archivo_csv()
    {
        $csv_relative_path = 'imported_files/' . pathinfo($this->archivo_excel_path, PATHINFO_FILENAME) . '_' . time() . '.csv';
        $this->csv_full_path = storage_path('app/' . $csv_relative_path);

        try {
            $conversion_inicio = microtime(true);

            Log::info('Iniciando conversión de XLSX a CSV. Origen: ' . $this->archivo_excel);

            $reader = ReaderEntityFactory::createXLSXReader();
            $reader->setShouldPreserveEmptyRows(true);
            $reader->open($this->archivo_excel);

            $writer = WriterEntityFactory::createCSVWriter();
            $writer->openToFile($this->csv_full_path);

            $fila = 1;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];

                    foreach ($row->getCells() as $cell) {
                        $value = $cell->getValue();

                        if ($value instanceof \DateTime) {
                            $value = $value->format('Y-m-d H:i:s');
                        }

                        if ($value === null) {
                            $value = '';
                        }

                        $cells[] = new Cell((string) $value);
                    }

                    if (count($cells) === 0) {
                        $cells[] = new Cell('');
                    }

                    $new_row = new Row($cells, null);
                    $writer->addRow($new_row);

                    $fila++;
                }

                break;
            }

            $writer->close();
            $reader->close();

            $conversion_fin = microtime(true);
            $conversion_duracion = $conversion_fin - $conversion_inicio;

            Log::info('Conversión a CSV completada en ' . number_format($conversion_duracion, 3) . ' segundos. Nuevo archivo: ' . $this->csv_full_path);

            return true;
        } catch (\Exception $e) {
            Log::error('Error al convertir XLSX a CSV: ' . $e->getMessage());
            return false;
        }
    }

    function build_csv_chunk_offsets(): array
    {
        $offsets = [];

        $target_rows = [];
        $row = $this->start_row;

        while ($row <= $this->finish_row) {
            $target_rows[$row] = true;
            $row += $this->chunkSize;
        }

        $handle = fopen($this->csv_full_path, 'r');

        if ($handle === false) {
            Log::error('No se pudo abrir el CSV para generar offsets: ' . $this->csv_full_path);
            return $offsets;
        }

        $current_row = 1;

        while (!feof($handle)) {
            $pos = ftell($handle);
            $line = fgets($handle);

            if ($line === false) {
                break;
            }

            if (isset($target_rows[$current_row])) {
                $offsets[$current_row] = $pos;
            }

            if ($current_row > $this->finish_row) {
                break;
            }

            $current_row++;
        }

        fclose($handle);

        Log::info('Offsets de chunks generados: ' . count($offsets));

        return $offsets;
    }

    function armar_jobs_de_chunks()
    {
        $this->chunk_number = 1;

        while ($this->start <= $this->finish_row) {
            $this->end = min($this->start + $this->chunkSize - 1, $this->finish_row);

            Log::info("Se mandó chunk desde {$this->start} hasta {$this->end}");

            $this->jobs[] = new ProcessArticleChunk(
                $this->csv_full_path,
                $this->columns,
                $this->create_and_edit,
                $this->start,
                $this->end,
                $this->provider_id,
                $this->user->id,
                $this->auth_user_id,
                $this->import_status->id,
                $this->import_history->id,
                $this->chunk_number,
                $this->chunk_offsets[$this->start] ?? null,
                
                $this->actualizar_articulos_de_otro_proveedor,
                $this->actualizar_proveedor,
                $this->permitir_provider_code_repetido,
                $this->permitir_provider_code_repetido_en_multi_providers,
                $this->actualizar_por_provider_code
            );

            $this->chunk_number++;
            $this->start = $this->end + 1;
        }
    }

    function crear_import_status()
    {
        $this->import_status = ImportStatus::create([
            'user_id' => $this->user->id,
            'total_chunks' => $this->total_chunks,
            'processed_chunks' => 0,
            'articles_match' => 0,
            'created_models' => 0,
            'updated_models' => 0,
            'status' => 'pendiente',
            'provider_id' => $this->provider_id,
        ]);
    }

    function crear_import_history()
    {
        $this->import_history = ImportHistory::create([
            'created_models'        => 0,
            'updated_models'        => 0,
            'articles_match'        => 0,
            'status'                => 'en_preparacion',
            'operacion_a_realizar'  => $this->create_and_edit ? 'Crear y actualizar' : 'Solo actualizar',
            // 'no_actualizar_otro_proveedor' => (bool) $this->actualizar_articulos_de_otro_proveedor,
            'user_id'               => $this->user ? $this->user->id : null,
            'employee_id'           => $this->auth_user_id,
            'model_name'            => 'article',
            'provider_id'           => $this->provider_id && $this->provider_id !== 'null' ? (int) $this->provider_id : null,
            'columnas'              => json_encode(ArticleImportHelper::convertirPosicionesAColumnas($this->columns), JSON_PRETTY_PRINT),
            'excel_url'             => $this->archivo_excel_path,
            'registrar_art_cre'     => $this->registrar_art_cre,
            'registrar_art_act'     => $this->registrar_art_act,
            // 'permitir_provider_code_repetido'    => $this->permitir_provider_code_repetido,
            'total_chunks'          => $this->total_chunks,
            'processed_chunks'      => 0,
            'operaciones'           => json_encode($this->get_operaciones()),
        ]);
    }

    function get_operaciones() {

        return [
            [
                'name'  => 'Operaciones',
                'value' => (bool)$this->create_and_edit ? 'Crear y actualizar' : 'Solo actualizar',
            ],
            [
                'name'  => 'Fila inicio',
                'value' => $this->start_row,
            ],
            [
                'name'  => 'Fila fin',
                'value' => $this->finish_row,
            ],
            [
                'name'  => 'Permitir codigos de proveedor repetidos',
                'value' => $this->permitir_provider_code_repetido ? 'Si' : 'No',
            ],
            [
                'name'  => 'Permitir codigos de proveedor repetidos en multiples proveedores',
                'value' => $this->permitir_provider_code_repetido_en_multi_providers ? 'Si' : 'No',
            ],
            [
                'name'  => 'Actualizar articulos de otro proveedor',
                'value' => $this->actualizar_articulos_de_otro_proveedor ? 'Si' : 'No',
            ],
            [
                'name'  => 'Actualizar por codigos de proveedor',
                'value' => $this->actualizar_por_provider_code ? 'Si' : 'No',
            ],
            [
                'name'  => 'Actualizar proveedor',
                'value' => $this->actualizar_proveedor ? 'Si' : 'No',
            ],
        ];
    }
}