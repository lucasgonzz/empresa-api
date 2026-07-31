<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;
use App\Http\Controllers\Helpers\import\article\ExcelNumericFormatStats;
use App\Http\Controllers\Helpers\import\article\InitExcelImport;
use App\Imports\ClientImport;
use App\Imports\ProviderImport;
use App\Jobs\RunExcelAnalysisJob;
use App\Models\ExcelAnalysisRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Controlador para la importación de artículos asistida por Claude IA.
 *
 * Expone dos endpoints:
 *  - analyze: recibe un Excel y devuelve el mapeo de columnas sugerido por Claude.
 *  - import:  recibe el mapeo confirmado por el usuario y lanza la importación existente.
 *
 * Este controlador delega en AiExcelAnalyzer (análisis) y en InitExcelImport (importación),
 * sin modificar la lógica de importación existente.
 */
class AiExcelImportController extends Controller
{
    /**
     * Encola el análisis de un archivo Excel con la IA de Claude (grupo 291, prompt 02).
     *
     * El archivo debe enviarse como multipart en el campo "excel_file".
     *
     * Antes de este prompt, este método analizaba el archivo de forma síncrona
     * dentro del request (varios recorridos completos + la llamada a Claude), lo
     * que con archivos grandes superaba el timeout del proxy y devolvía un 504
     * sin mensaje útil. Ahora solo valida y guarda el archivo, crea una
     * ExcelAnalysisRun en estado "pendiente" y encola RunExcelAnalysisJob, que
     * hace el mismo trabajo de análisis (sin cambios) en un worker. El resultado
     * se consulta después con GET /ai-excel-import/analysis/{uuid}.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function analyze(Request $request)
    {
        /*
         * Validamos que venga un archivo y que sea un Excel (.xlsx, .xls).
         * En este proyecto la validación en backend se hace solo cuando es necesaria
         * por integridad mínima inevitable (el formato incorrecto rompería OpenSpout).
         * Esta validación sigue siendo síncrona: es instantánea y rechaza el caso
         * más común de error de uso antes de tocar storage o la cola.
         */
        if (!$request->hasFile('excel_file') || !$request->file('excel_file')->isValid()) {
            return response()->json([
                'message' => 'Se requiere un archivo Excel válido en el campo "excel_file".',
            ], 422);
        }

        /* Verificamos la extensión del archivo para rechazar formatos no soportados. */
        $extension = strtolower($request->file('excel_file')->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls'])) {
            return response()->json([
                'message' => 'El archivo debe ser un Excel válido (.xlsx o .xls).',
            ], 422);
        }

        try {
            /*
             * Guardamos el archivo en storage para que el job (y, luego, /import)
             * puedan leerlo con OpenSpout.
             */
            /*
             * Nombre original del archivo subido (p. ej. "Lista_Distribuidora_X.xlsx").
             * Se envía a Claude para inferir el proveedor; el guardado en disco usa otro nombre.
             */
            $original_filename = (string) $request->file('excel_file')->getClientOriginalName();

            $filename   = 'ai_import_' . time() . '_' . Str::random(8) . '.xlsx';
            $excel_path = $request->file('excel_file')->storeAs('imported_files', $filename);

            Log::info('AiExcelImportController::analyze - archivo guardado', [
                'excel_path'         => $excel_path,
                'original_filename'  => $original_filename,
            ]);

            /*
             * Modelo de análisis indicado en el request (article/client/provider).
             * El default es 'article' para mantener compatibilidad con llamadas que no envían model.
             * La elección real del analyzer ahora ocurre dentro del job.
             */
            $model = (string) $request->input('model', 'article');

            /*
             * Creamos la corrida en estado "pendiente". guarded está vacío en el
             * modelo, así que el create() de abajo asigna todos estos campos.
             * payload guarda lo mínimo que el job necesita para reconstruir el
             * mismo comportamiento que antes tenía este método.
             */
            $run = ExcelAnalysisRun::create([
                'uuid'       => Str::uuid()->toString(),
                'user_id'    => $this->userId(),
                'tipo'       => 'analisis',
                'estado'     => 'pendiente',
                'excel_path' => $excel_path,
                'payload'    => [
                    'model'             => $model,
                    'original_filename' => $original_filename,
                ],
            ]);

            /* Encolamos el job pasando solo el id, no el modelo (ver RunExcelAnalysisJob). */
            RunExcelAnalysisJob::dispatch($run->id);

            /*
             * 202 Accepted: el análisis quedó encolado, todavía no hay resultado.
             * El frontend hace polling a analysisStatus() con este uuid.
             */
            return response()->json([
                'analysis_uuid' => $run->uuid,
                'excel_path'    => $excel_path,
                'estado'        => $run->estado,
            ], 202);

        } catch (\Throwable $e) {
            Log::error('AiExcelImportController::analyze - error al encolar el análisis', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Ocurrió un error inesperado al iniciar el análisis: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Consulta el estado (y, si ya terminó, el resultado) de una corrida de
     * análisis de Excel encolada por analyze() (grupo 291, prompt 02).
     *
     * @param  string  $uuid  UUID de la corrida, devuelto por analyze() en "analysis_uuid".
     * @return \Illuminate\Http\JsonResponse
     */
    public function analysisStatus($uuid)
    {
        /*
         * Filtramos también por el usuario autenticado para que nadie pueda
         * consultar (y de paso leer el resultado de) la corrida de otro usuario
         * adivinando o iterando UUIDs.
         */
        $run = ExcelAnalysisRun::where('uuid', $uuid)
            ->where('user_id', $this->userId())
            ->first();

        if (is_null($run)) {
            return response()->json(['message' => 'No se encontro el analisis'], 404);
        }

        return response()->json([
            'estado'   => $run->estado,
            'progreso' => $run->progreso,
            'paso'     => $run->paso,
            'error'    => $run->error,
            /*
             * El resultado completo solo se devuelve cuando la corrida terminó
             * ("listo"); mientras está pendiente/procesando/error, va null.
             * Nunca se devuelve codigos_proveedor: puede tener decenas de miles
             * de strings y el frontend no lo usa (ver ExcelAnalysisRun, prompt 03).
             */
            'resultado' => $run->estado === 'listo' ? $run->resultado : null,
        ], 200);
    }

    /**
     * Recalcula los conteos de provider_codes existentes en BD para un proveedor específico.
     *
     * Se llama desde el frontend cuando el usuario cambia el proveedor seleccionado en el paso 2,
     * para actualizar los chips "ya en BD (mismo proveedor)" y "ya en BD (otro proveedor)"
     * con el proveedor real en lugar del inferido por Claude.
     *
     * Request params:
     *   - excel_path (string): ruta relativa del archivo ya guardado por /analyze
     *   - provider_code_column_index (int|null): índice 0-based de la columna provider_code
     *   - provider_id (int|null): ID del proveedor seleccionado por el usuario
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refreshProviderStats(Request $request)
    {
        /* Ruta relativa del Excel guardado en /analyze; obligatoria para localizar el archivo. */
        $excel_path = $request->input('excel_path');

        if (empty($excel_path)) {
            return response()->json(['message' => 'El campo "excel_path" es obligatorio.'], 422);
        }

        /* Ruta absoluta en storage donde quedó persistido el archivo del análisis. */
        $excel_full_path = storage_path('app/' . $excel_path);

        if (!file_exists($excel_full_path)) {
            return response()->json(['message' => 'El archivo Excel indicado no existe o ha expirado.'], 422);
        }

        /* Índice 0-based de la columna provider_code en el Excel; null si no hay columna mapeada. */
        $provider_code_column_index = $request->input('provider_code_column_index');
        $provider_code_column_index = is_numeric($provider_code_column_index)
            ? (int) $provider_code_column_index
            : null;

        /* Proveedor elegido por el usuario en el paso 2; null si aún no seleccionó uno. */
        $provider_id = $request->input('provider_id');
        $provider_id = is_numeric($provider_id) && (int) $provider_id > 0
            ? (int) $provider_id
            : null;

        /*
         * bar_code_column en null: solo recalcula existentes por provider_code,
         * sin releer códigos de barras (más liviano para este endpoint).
         */
        $stats = ExcelDuplicateStats::analyze(
            $excel_full_path,
            null,
            $provider_code_column_index,
            $provider_id,
            $this->userId()
        );

        return response()->json([
            'provider_codes_existentes_mismo_proveedor'   => $stats['provider_codes_existentes_mismo_proveedor'],
            'provider_codes_existentes_otros_proveedores' => $stats['provider_codes_existentes_otros_proveedores'],
        ], 200);
    }

    /**
     * Lanza la importación de artículos usando el mapeo de columnas confirmado por el usuario.
     *
     * El request debe incluir:
     *   - excel_path (string): ruta relativa guardada por /analyze
     *   - columns (array): mapeo de columnas en el formato que espera InitExcelImport
     *   - provider_id (int|null): proveedor seleccionado
     *   - create_and_edit (bool): si se deben crear y actualizar, o solo actualizar
     *   - start_row (int): fila de inicio de datos (generalmente 2)
     *   - finish_row (int): última fila a importar
     *   - registrar_art_cre (bool)
     *   - registrar_art_act (bool)
     *   - permitir_provider_code_repetido (bool)
     *   - permitir_provider_code_repetido_en_multi_providers (bool)
     *   - actualizar_articulos_de_otro_proveedor (bool)
     *   - actualizar_por_provider_code (bool)
     *   - actualizar_proveedor (bool)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function import(Request $request)
    {
        /*
         * Verificamos que el excel_path recibido exista en storage.
         * El path viene del endpoint /analyze y apunta al archivo ya guardado.
         */
        $excel_path = $request->input('excel_path');

        if (empty($excel_path)) {
            return response()->json([
                'message' => 'El campo "excel_path" es obligatorio. Primero ejecutá el análisis (/analyze).',
            ], 422);
        }

        $excel_full_path = storage_path('app/' . $excel_path);

        if (!file_exists($excel_full_path)) {
            return response()->json([
                'message' => 'El archivo Excel indicado no existe. Es posible que haya expirado. Volvé a subirlo.',
            ], 422);
        }

        /* El owner se resuelve igual que en ArticleController. */
        $owner = User::find($this->userId());

        if (is_null($owner)) {
            return response()->json([
                'message' => 'No se encontró el usuario propietario.',
            ], 403);
        }

        /*
         * Derivamos la importación al handler correspondiente según el modelo.
         * En este contexto el usuario ya está autenticado (Bearer token),
         * por lo que ClientImport y ProviderImport pueden resolver userId() directamente.
         */
        $model = (string) $request->input('model', 'article');

        if ($model === 'client') {
            return $this->import_clients($request, $excel_full_path);
        }

        if ($model === 'provider') {
            return $this->import_providers($request, $excel_full_path);
        }

        /* model === 'article': flujo original con InitExcelImport. */
        $import_uuid = (string) Str::uuid();

        /*
         * Delegamos en InitExcelImport exactamente con los mismos parámetros que
         * ArticleController, respetando el contrato ya definido.
         */
        $excel_import = new InitExcelImport();

        $result = $excel_import->importar([
            'import_uuid'           => $import_uuid,
            'archivo_excel'         => $excel_full_path,
            'columns'               => $request->input('columns', []),
            'create_and_edit'       => $request->input('create_and_edit', false),
            'start_row'             => $request->input('start_row', 2),
            'finish_row'            => $request->input('finish_row', 1000),
            'provider_id'           => $request->input('provider_id'),
            'user'                  => $owner,
            'auth_user_id'          => auth()->user()->id,
            'archivo_excel_path'    => $excel_path,
            'registrar_art_cre'     => $request->input('registrar_art_cre', true),
            'registrar_art_act'     => $request->input('registrar_art_act', true),

            'permitir_provider_code_repetido'                    => $request->input('permitir_provider_code_repetido', false),
            'permitir_provider_code_repetido_en_multi_providers' => $request->input('permitir_provider_code_repetido_en_multi_providers', false),
            'actualizar_articulos_de_otro_proveedor'             => $request->input('actualizar_articulos_de_otro_proveedor', false),
            'actualizar_por_provider_code'                       => $request->input('actualizar_por_provider_code', true),
            'actualizar_proveedor'                               => $request->input('actualizar_proveedor', false),
            'filas_repetidas_del_archivo'                        => $request->input('filas_repetidas_del_archivo'),

            /*
             * Modo elegido por el usuario para interpretar el punto en columnas numéricas
             * ambiguas (grupo 239, prompt 04). Mismo punto de entrada y normalización que
             * ArticleController@import: se resuelve acá, no se confía en el valor crudo.
             */
            'interpretacion_punto'                               => ImportHelper::normalizarInterpretacionPunto($request->input('interpretacion_punto')),
        ]);

        if ($result['hubo_un_error']) {
            return response()->json([
                'message'              => $result['message'] ?? 'Ocurrió un error al preparar la importación.',
                'info_to_show'         => $result['info_to_show'] ?? [],
                'functions_to_execute' => $result['functions_to_execute'] ?? [],
            ], 409);
        }

        return response(null, 200);
    }

    /**
     * Ejecuta la importación de clientes usando Maatwebsite Excel y ClientImport.
     *
     * En este controlador el usuario está autenticado vía Bearer token, por lo que
     * ClientImport resuelve userId() correctamente sin necesidad de login temporal.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string                    $excel_full_path  Ruta absoluta al archivo Excel
     * @return \Illuminate\Http\JsonResponse
     */
    protected function import_clients(Request $request, string $excel_full_path)
    {
        /* Parámetros de importación extraídos del request. */
        $columns         = $request->input('columns', []);
        $create_and_edit = $request->input('create_and_edit', true);
        $start_row       = (int) $request->input('start_row', 2);
        $finish_row      = $request->input('finish_row', null);

        /* Tratar finish_row vacío o cero como "hasta la última fila". */
        if ($finish_row === '' || $finish_row === '0' || $finish_row === 0) {
            $finish_row = null;
        } elseif (!is_null($finish_row)) {
            $finish_row = (int) $finish_row;
        }

        try {
            Excel::import(
                new ClientImport($columns, $create_and_edit, $start_row, $finish_row),
                $excel_full_path
            );

            Log::info('AiExcelImportController::import_clients - importación finalizada', [
                'user_id'    => $this->userId(),
                'start_row'  => $start_row,
                'finish_row' => $finish_row,
            ]);

            return response()->json(['message' => 'Importación de clientes iniciada.'], 200);

        } catch (\Throwable $e) {
            Log::error('AiExcelImportController::import_clients - error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'user_id' => $this->userId(),
            ]);

            return response()->json([
                'message' => 'Ocurrió un error al importar clientes: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ejecuta la importación de proveedores usando Maatwebsite Excel y ProviderImport.
     *
     * En este controlador el usuario está autenticado vía Bearer token, por lo que
     * ProviderImport resuelve userId() correctamente sin necesidad de login temporal.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string                    $excel_full_path  Ruta absoluta al archivo Excel
     * @return \Illuminate\Http\JsonResponse
     */
    protected function import_providers(Request $request, string $excel_full_path)
    {
        /* Parámetros de importación extraídos del request. */
        $columns         = $request->input('columns', []);
        $create_and_edit = $request->input('create_and_edit', true);
        $start_row       = (int) $request->input('start_row', 2);
        $finish_row      = $request->input('finish_row', null);

        /* Tratar finish_row vacío o cero como "hasta la última fila". */
        if ($finish_row === '' || $finish_row === '0' || $finish_row === 0) {
            $finish_row = null;
        } elseif (!is_null($finish_row)) {
            $finish_row = (int) $finish_row;
        }

        try {
            /*
             * El quinto parámetro ($provider_id) es null; en importación de proveedores
             * no se asigna un proveedor padre al registro importado.
             */
            Excel::import(
                new ProviderImport($columns, $create_and_edit, $start_row, $finish_row, null),
                $excel_full_path
            );

            Log::info('AiExcelImportController::import_providers - importación finalizada', [
                'user_id'    => $this->userId(),
                'start_row'  => $start_row,
                'finish_row' => $finish_row,
            ]);

            return response()->json(['message' => 'Importación de proveedores iniciada.'], 200);

        } catch (\Throwable $e) {
            Log::error('AiExcelImportController::import_providers - error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'user_id' => $this->userId(),
            ]);

            return response()->json([
                'message' => 'Ocurrió un error al importar proveedores: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Genera la recomendación de configuración de importación usando el proveedor
     * ya confirmado por el usuario en el paso 2 del modal.
     *
     * A diferencia de /analyze (que usa el proveedor inferido por Claude),
     * este endpoint recibe el provider_id real elegido por el usuario,
     * recalcula los duplicate_stats con ese proveedor, y genera la recomendación
     * con datos correctos.
     *
     * @param  Request  $request  Campos: excel_path (string), provider_id (int|null),
     *                             provider_code_column_index (int|null), column_mapping (array)
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecomendacion(Request $request)
    {
        /* Ruta relativa del archivo Excel guardado por /analyze */
        $excel_path = $request->input('excel_path');

        if (empty($excel_path)) {
            return response()->json(['message' => 'El campo "excel_path" es obligatorio.'], 422);
        }

        /* Ruta absoluta en el sistema de archivos */
        $excel_full_path = storage_path('app/' . $excel_path);

        if (!file_exists($excel_full_path)) {
            return response()->json(['message' => 'El archivo Excel indicado no existe o ha expirado.'], 422);
        }

        /* Proveedor confirmado por el usuario (puede ser null si no aplica) */
        $provider_id = $request->input('provider_id');
        $provider_id = is_numeric($provider_id) && (int) $provider_id > 0
            ? (int) $provider_id
            : null;

        /* Índice 0-based de la columna provider_code en el Excel (para calcular stats correctamente) */
        $provider_code_column_index = $request->input('provider_code_column_index');
        $provider_code_column_index = is_numeric($provider_code_column_index)
            ? (int) $provider_code_column_index
            : null;

        /* Mapeo de columnas confirmado por el usuario (para derivar columnas disponibles) */
        $column_mapping = $request->input('column_mapping', []);

        /*
         * Derivar el índice 0-based de la columna bar_code desde el column_mapping.
         * Es necesario para que ExcelDuplicateStats pueda leer el archivo y calcular
         * correctamente total_filas_datos y bar_codes_duplicados_intra_archivo.
         * Sin este índice, cuando no hay provider_code ambos índices quedan null y
         * ExcelDuplicateStats retorna todo en 0 — Claude interpreta "archivo vacío".
         */
        $bar_code_column_index = null;
        foreach ($column_mapping as $col) {
            if (($col['system_property'] ?? null) === 'codigo_de_barras') {
                $bar_code_column_index = isset($col['excel_column_index'])
                    ? (int) $col['excel_column_index']
                    : null;
                break;
            }
        }

        try {
            /*
             * Recalcular duplicate_stats con el proveedor real confirmado por el usuario.
             * Esto garantiza que la recomendación de Claude se base en datos correctos
             * y no en el proveedor inferido durante el análisis inicial.
             */
            $stats = ExcelDuplicateStats::analyze(
                $excel_full_path,
                $bar_code_column_index,
                $provider_code_column_index,
                $provider_id,
                $this->userId()
            );

            $analyzer = new AiExcelAnalyzer($this->userId());

            /*
             * Recalcular formatos_numericos con el column_mapping confirmado por el usuario
             * (grupo 239, prompt 02). En /analyze el mapeo es el sugerido por Claude; el
             * usuario todavía puede corregirlo en el paso 2, así que hay que recalcular acá
             * igual que se hace con duplicate_stats — si no, la alerta podría estar mirando
             * la columna equivocada. Reusa build_numeric_columns_map() para no duplicar la
             * lógica de derivar índices entre el analyzer y este controller.
             */
            $numeric_columns_map = $analyzer->build_numeric_columns_map($column_mapping);
            $formatos_numericos  = ExcelNumericFormatStats::analyze(
                $excel_full_path,
                $numeric_columns_map['indices'],
                $numeric_columns_map['nombres']
            );

            /*
             * Nombres de las filas con código de proveedor repetido (grupo 284, prompt 03): es la
             * evidencia que le permite a Claude recomendar politica_intra_archivo.
             */
            $duplicados_con_nombres = $analyzer->build_duplicados_con_nombres(
                $excel_full_path,
                $stats['detalle_provider_codes_duplicados'] ?? [],
                $column_mapping
            );

            /* Generar recomendación con los stats recalculados para el proveedor confirmado */
            $recomendacion = $analyzer->ask_claude_for_recomendation($stats, $column_mapping, $formatos_numericos, $duplicados_con_nombres);

            return response()->json([
                'recomendacion_configuracion'                  => $recomendacion,
                /* Stats actualizados para que el frontend pueda refrescar los chips de decisión */
                'provider_codes_existentes_mismo_proveedor'    => $stats['provider_codes_existentes_mismo_proveedor'] ?? 0,
                'provider_codes_existentes_otros_proveedores'  => $stats['provider_codes_existentes_otros_proveedores'] ?? 0,
                'formatos_numericos' => $formatos_numericos,
            ], 200);

        } catch (\RuntimeException $e) {
            Log::warning('AiExcelImportController::getRecomendacion - error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);

        } catch (\Throwable $e) {
            Log::error('AiExcelImportController::getRecomendacion - error inesperado', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Error inesperado al generar la recomendación.'], 500);
        }
    }
}
