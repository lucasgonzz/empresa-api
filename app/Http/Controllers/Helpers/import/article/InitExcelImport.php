<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
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
        /*
         * Normalizamos claves del mapeo (p. ej. importación con IA: codigo_proveedor → codigo_de_proveedor).
         */
        $this->columns                  = ArticleImportColumnsNormalizer::normalize(
            is_array($data['columns'] ?? null) ? $data['columns'] : []
        );
        /*
         * Prompt 310: flags "permitir_valores_en_blanco" por columna mapeada, normalizados con
         * los mismos alias que $this->columns para que ProcessRow los pueda cruzar por clave.
         */
        $this->blank_flags              = ArticleImportColumnsNormalizer::normalize_blank_flags(
            is_array($data['blank_flags'] ?? null) ? $data['blank_flags'] : []
        );
        $this->create_and_edit          = $data['create_and_edit'];

        /*
         * Hoja del libro a importar, 0-based. Default 0 = primera hoja, que es lo que este
         * importador hizo siempre (el `break` del foreach de hojas en armar_archivo_csv()).
         *
         * Clave OPCIONAL: AdminSync\AiExcelImportController arma su propio array y no la manda,
         * y tampoco la manda una SPA sin desplegar. Los dos tienen que seguir importando la
         * primera hoja, sin un ArgumentCountError ni un índice raro de por medio.
         */
        $this->hoja                     = is_numeric($data['hoja'] ?? null) && (int) $data['hoja'] >= 0
                                            ? (int) $data['hoja']
                                            : 0;

        /*
         * Nombre de la hoja elegida. Clave OPCIONAL, default null = "usá el índice".
         *
         * Es la mitigación que ya estaba construida y no se usaba en este camino:
         * ExcelWorkbookReader::resolver_indice() existe porque el índice lo calcula
         * SheetJS en el navegador y quien lee después es OpenSpout, y los dos pueden
         * discrepar. El camino con IA queda tapado por accidente (la SPA se pisa el índice
         * con el que le devuelve el backend); acá, en el import clásico, no hay ida y
         * vuelta: el índice del navegador llega derecho a armar_archivo_csv().
         *
         * Si el nombre no viene —que es TODO lo que pasa hoy: ni la SPA ni AdminSync lo
         * mandan— no se resuelve nada y se usa el índice crudo, exactamente como antes.
         */
        $this->hoja_nombre              = (isset($data['hoja_nombre'])
                                            && is_string($data['hoja_nombre'])
                                            && trim($data['hoja_nombre']) !== '')
                                            ? trim($data['hoja_nombre'])
                                            : null;
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

        /*
         * Modo elegido por el usuario para interpretar el punto en columnas numéricas
         * ambiguas (grupo 239, prompt 04). El controller ya lo normalizó, pero se
         * vuelve a resolver el default acá por si algún llamador viejo no manda la clave.
         */
        $this->interpretacion_punto                                 = $data['interpretacion_punto'] ?? 'auto';

        /*
         * Flag opcional que separa la decisión "repetido dentro del propio archivo"
         * de "repetido contra la base" (prompt 04, grupo 265). Antes de este prompt,
         * ambas decisiones compartían permitir_provider_code_repetido. Cualquier valor
         * que no sea exactamente uno de los dos reconocidos (incluido null, si no
         * viene el parámetro) cae al default 'ultima_gana', que preserva el
         * comportamiento histórico: cambio 100% aditivo para llamadores viejos.
         */
        $this->filas_repetidas_del_archivo = $this->normalizar_filas_repetidas_del_archivo(
            $data['filas_repetidas_del_archivo'] ?? null
        );

        /*
         * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026): la planilla declara si sus costos
         * vienen con el IVA adentro. Es la ÚNICA fuente de esa decisión para el import — no hay
         * fallback por condición fiscal ni por configuración de la cuenta —, igual que la compra la
         * declara con `provider_orders.precios_incluyen_iva` y el ABM con el input en el que se
         * tipeó. Default false (= netos), que es el comportamiento histórico del import.
         */
        $this->precios_incluyen_iva = filter_var(
            $data['precios_incluyen_iva'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

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
                // 'info_to_show' => [],
                // 'functions_to_execute' => [],
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
         * Siempre procesamiento secuencial (Bus::chain), independientemente del entorno.
         *
         * El modo paralelo (mandar_batch) causaba una condición de carrera: el índice
         * anti-duplicados (ArticleIndexCache) vive en RAM estática por proceso, por lo
         * que workers concurrentes no comparten estado y pueden crear el mismo artículo
         * dos veces cuando un artículo no existía al inicio del batch.
         *
         * La pérdida de velocidad es aceptable dado el tamaño típico de los archivos
         * importados (~5.000 filas) y el chunk size de 50 filas.
         */
        $this->mandar_chain();

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
            /*
             * No se puede hardcodear 'default' acá: pisaría el aislamiento por
             * instalación (QUEUE_NAME) definido en config/queue.php. Se resuelve
             * dinámicamente el nombre de cola configurado para la conexión activa
             * ($queue_connection), para que el batch quede en la misma cola que
             * escucha el worker de esta instalación.
             */
            ->onQueue(config("queue.connections.{$queue_connection}.queue"))
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
        if ($this->total_rows < 1) {
            $this->total_rows = 1;
        }
        $this->total_chunks = (int) ceil($this->total_rows / $this->chunkSize);
    }

    /**
     * Valida el valor crudo recibido del request para filas_repetidas_del_archivo
     * (prompt 04, grupo 265) contra los dos valores permitidos. Cualquier otra cosa,
     * incluido null o un string vacío, cae al default 'ultima_gana': no confiamos en
     * que el frontend siempre mande un valor válido (mismo criterio que
     * ImportHelper::normalizarInterpretacionPunto para interpretacion_punto).
     *
     * @param  mixed $valor
     * @return string 'ultima_gana' | 'productos_distintos'
     */
    protected function normalizar_filas_repetidas_del_archivo($valor): string
    {
        $valores_validos = ['ultima_gana', 'productos_distintos'];

        if (is_string($valor) && in_array($valor, $valores_validos, true)) {
            return $valor;
        }

        return 'ultima_gana';
    }

    /**
     * Reduce finish_row cuando el cliente envió un tope mayor que las filas reales del Excel.
     *
     * @param  int $ultima_fila_con_contenido  Última fila 1-based con al menos una celda no vacía
     * @return void
     */
    protected function ajustar_finish_row_segun_excel_real(int $ultima_fila_con_contenido): void
    {
        $finish_row_original = (int) $this->finish_row;

        if ($finish_row_original <= $ultima_fila_con_contenido) {
            return;
        }

        $this->finish_row = max($ultima_fila_con_contenido, (int) $this->start_row);

        Log::info('InitExcelImport: finish_row ajustado al tamaño real del Excel', [
            'finish_row_original'       => $finish_row_original,
            'finish_row'                => $this->finish_row,
            'ultima_fila_con_contenido' => $ultima_fila_con_contenido,
            'chunk_size'                => $this->chunkSize,
        ]);
    }

    function armar_archivo_csv()
    {
        $csv_relative_path = 'imported_files/' . pathinfo($this->archivo_excel_path, PATHINFO_FILENAME) . '_' . time() . '.csv';
        $this->csv_full_path = storage_path('app/' . $csv_relative_path);

        try {
            $conversion_inicio = microtime(true);

            Log::info('Iniciando conversión de XLSX a CSV. Origen: ' . $this->archivo_excel);

            /*
             * La hoja elegida, no "la primera y listo".
             *
             * Antes acá había un `foreach ($reader->getSheetIterator() as $sheet) { ...; break; }`:
             * ese break es "siempre la hoja 0", y como nadie lo elegía ni lo veía, un libro con
             * la lista de precios en la hoja 2 se volcaba a un CSV con la hoja de notas y se
             * importaba cualquier cosa sin un solo error en pantalla.
             *
             * Todo lo demás del volcado queda igual: preservar_filas_vacias en true, una línea de
             * CSV por cada fila del Excel. Eso es lo que hace que línea de CSV = fila del Excel,
             * y de eso dependen build_csv_chunk_offsets() y armar_jobs_de_chunks(), que navegan
             * el archivo por número de línea. Si esto dejara de ser 1:1, start_row y finish_row
             * pasarían a apuntar a filas equivocadas.
             */
            /*
             * Si vino el nombre de la hoja, manda el nombre.
             *
             * resolver_indice() prioriza nombre exacto -> índice en rango -> 0, y nunca
             * devuelve un índice fuera de rango. Sólo se llama cuando hay nombre: cuesta un
             * listado completo del libro (un Reader::open() que parsea sharedStrings.xml
             * entero, ~200ms en un xlsx de 20.000 filas), y no se le paga ese peaje a las
             * importaciones que hoy andan bien sin él.
             */
            $indice_de_hoja = $this->hoja;

            if (!is_null($this->hoja_nombre)) {
                $indice_de_hoja = ExcelWorkbookReader::resolver_indice(
                    $this->archivo_excel,
                    $this->hoja,
                    $this->hoja_nombre
                );
            }

            $lectura = ExcelWorkbookReader::abrir($this->archivo_excel, $indice_de_hoja, true);

            $writer = WriterEntityFactory::createCSVWriter();
            $writer->openToFile($this->csv_full_path);

            /* Número de fila actual en el Excel (1-based) y última fila con al menos una celda con datos. */
            $fila = 1;
            $ultima_fila_con_contenido = 1;

            foreach ($lectura->filas() as $row) {
                $cells = [];
                $fila_tiene_contenido = false;

                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();

                    if ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d H:i:s');
                    }

                    if ($value === null) {
                        $value = '';
                    }

                    $text_value = trim((string) $value);
                    if ($text_value !== '') {
                        $fila_tiene_contenido = true;
                    }

                    $cells[] = new Cell((string) $value);
                }

                if (count($cells) === 0) {
                    $cells[] = new Cell('');
                }

                if ($fila_tiene_contenido) {
                    $ultima_fila_con_contenido = $fila;
                }

                $new_row = new Row($cells, null);
                $writer->addRow($new_row);

                $fila++;
            }

            $nombre_de_hoja = $lectura->nombre();

            $writer->close();
            $lectura->cerrar();

            /*
             * Si el frontend envió finish_row muy alto (p. ej. 99999 en importación con IA),
             * limitamos al rango real del archivo para no crear miles de chunks vacíos.
             */
            $this->ajustar_finish_row_segun_excel_real($ultima_fila_con_contenido);

            /*
             * 🔴 Este log existe para diagnosticar "se importó de menos y nadie se dio cuenta".
             *
             * ajustar_finish_row_segun_excel_real() protege por ARRIBA (recorta un finish_row
             * más grande que el archivo) pero NO por abajo: si finish_row quedó demasiado chico
             * —porque el navegador lo calculó sobre otra hoja y después el usuario cambió de
             * hoja— se importan menos filas de las que el archivo tiene, sin error, sin
             * advertencia y sin nada raro en pantalla. Es el mismo síntoma que el histórico del
             * proyecto conoce como "me faltan artículos y no sé por qué".
             *
             * Con estos cuatro números en el log, ese caso se responde en un minuto: si
             * finish_row < ultima_fila_con_contenido, se importó de menos y ya sabemos sobre qué
             * hoja se calculó el rango.
             */
            Log::info('InitExcelImport: hoja volcada al CSV', [
                'hoja'                      => $indice_de_hoja,
                'hoja_pedida'               => $this->hoja,
                'hoja_nombre_pedido'        => $this->hoja_nombre,
                'hoja_nombre'               => $nombre_de_hoja,
                'start_row'                 => (int) $this->start_row,
                'finish_row'                => (int) $this->finish_row,
                'ultima_fila_con_contenido' => $ultima_fila_con_contenido,
            ]);

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
                $this->blank_flags,
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
                $this->actualizar_por_provider_code,
                $this->interpretacion_punto,
                $this->filas_repetidas_del_archivo,
                $this->precios_incluyen_iva
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
            /* Link al ImportStatus para que el watchdog pueda marcar ambos si el import queda colgado. */
            'import_status_id'      => $this->import_status->id,
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
            [
                /*
                 * Deja registrado con qué modo se importó (grupo 239, prompt 04), para poder
                 * responder si un cliente reclama por un costo raro dentro de unos meses.
                 */
                'name'  => 'Interpretacion del punto',
                'value' => $this->interpretacion_punto,
            ],
            [
                /*
                 * Deja registrado con qué criterio se resolvieron las filas repetidas del
                 * propio archivo (prompt 04, grupo 265), separado del criterio "repetido
                 * contra la base" (permitir_provider_code_repetido, arriba). Se persiste
                 * dentro del JSON de 'operaciones' que ya existe: no se agrega columna
                 * nueva a import_histories. build_import_options_for_notification() lo
                 * toma de acá automáticamente para mostrarlo en el historial.
                 */
                'name'  => 'Filas repetidas del archivo',
                'value' => $this->filas_repetidas_del_archivo,
            ],
        ];
    }
}