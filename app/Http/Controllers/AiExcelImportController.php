<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\InitExcelImport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * Analiza un archivo Excel con la IA de Claude y devuelve el mapeo de columnas sugerido.
     *
     * El archivo debe enviarse como multipart en el campo "excel_file".
     * La respuesta incluye:
     *   - column_mapping: array de objetos { excel_column, excel_column_letter, excel_column_index, system_property, confidence }
     *   - provider_id: id del proveedor inferido (null si no se pudo)
     *   - provider_confidence: "alto" | "medio" | "bajo"
     *   - excel_path: ruta relativa del archivo guardado (para reutilizarla en /import)
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
             * Guardamos el archivo en storage para que AiExcelAnalyzer pueda leerlo
             * con OpenSpout, y también para reutilizarlo en el endpoint /import.
             */
            /*
             * Nombre original del archivo subido (p. ej. "Lista_Distribuidora_X.xlsx").
             * Se envía a Claude para inferir el proveedor; el guardado en disco usa otro nombre.
             */
            $original_filename = (string) $request->file('excel_file')->getClientOriginalName();

            $filename       = 'ai_import_' . time() . '_' . Str::random(8) . '.xlsx';
            $excel_path     = $request->file('excel_file')->storeAs('imported_files', $filename);
            $excel_full_path = storage_path('app/' . $excel_path);

            Log::info('AiExcelImportController::analyze - archivo guardado', [
                'excel_path'         => $excel_path,
                'original_filename'  => $original_filename,
            ]);

            /*
             * El analizador recibe el user_id del owner para filtrar sus proveedores
             * y poder inferir cuál corresponde al listado analizado.
             */
            $analyzer = new AiExcelAnalyzer($this->userId());
            $analysis = $analyzer->analyze($excel_full_path, $original_filename);

            return response()->json([
                'column_mapping'      => $analysis['column_mapping'],
                'provider_id'         => $analysis['provider_id'],
                'provider_confidence' => $analysis['provider_confidence'],
                /* Devolvemos la ruta relativa para que el frontend la envíe en /import. */
                'excel_path'          => $excel_path,
            ], 200);

        } catch (\RuntimeException $e) {
            Log::warning('AiExcelImportController::analyze - error de análisis', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('AiExcelImportController::analyze - error inesperado', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Ocurrió un error inesperado al analizar el archivo: ' . $e->getMessage(),
            ], 500);
        }
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
}
