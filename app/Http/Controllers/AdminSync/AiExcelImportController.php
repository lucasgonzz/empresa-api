<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\InitExcelImport;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Imports\ClientImport;
use App\Imports\ProviderImport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Importación de Excel asistida por IA expuesta vía admin-sync.
 *
 * Misma funcionalidad que AiExcelImportController, pero autenticada con X-Admin-Api-Key
 * y con el owner resuelto por user_id del request (no por Auth()->user()).
 */
class AiExcelImportController extends Controller
{
    /**
     * Modelos soportados en el contrato admin-sync (article habilitado; client/provider pendientes).
     *
     * @var array
     */
    protected const ALLOWED_MODELS = ['article', 'client', 'provider'];

    /**
     * Analiza un Excel con IA y devuelve el mapeo de columnas sugerido para el owner indicado.
     *
     * Campos requeridos:
     *  - excel_file (multipart): archivo .xlsx o .xls
     *  - user_id (int): id del usuario owner en la tabla users
     *  - model (string): article | client | provider
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function analyze(Request $request): JsonResponse
    {
        /* Validamos user_id y model antes de procesar el archivo. */
        $user_validation = $this->resolve_owner_user($request);
        if ($user_validation instanceof JsonResponse) {
            return $user_validation;
        }

        $model_validation = $this->resolve_model($request);
        if ($model_validation instanceof JsonResponse) {
            return $model_validation;
        }

        $user_id = (int) $request->input('user_id');
        $model   = (string) $request->input('model');

        $excel_validation = $this->validate_excel_file($request);
        if ($excel_validation instanceof JsonResponse) {
            return $excel_validation;
        }

        try {
            /*
             * Nombre original del archivo subido; se envía a Claude como pista contextual.
             */
            $original_filename = (string) $request->file('excel_file')->getClientOriginalName();

            /* Guardamos el Excel en storage para reutilizarlo en /import. */
            $filename        = 'ai_import_' . time() . '_' . Str::random(8) . '.xlsx';
            $excel_path      = $request->file('excel_file')->storeAs('imported_files', $filename);
            $excel_full_path = storage_path('app/' . $excel_path);

            Log::info('AdminSync\\AiExcelImportController::analyze - archivo guardado', [
                'excel_path'        => $excel_path,
                'original_filename' => $original_filename,
                'user_id'           => $user_id,
                'model'             => $model,
            ]);

            /*
             * Instanciamos el analizador correspondiente al modelo indicado.
             * Todos implementan el mismo contrato: analyze(string $path, string $filename): array.
             */
            if ($model === 'client') {
                $analyzer = new AiClientAnalyzer($user_id);
            } elseif ($model === 'provider') {
                $analyzer = new AiProviderAnalyzer($user_id);
            } else {
                $analyzer = new AiExcelAnalyzer($user_id);
            }

            $analysis = $analyzer->analyze($excel_full_path, $original_filename);

            return response()->json([
                'column_mapping'      => $analysis['column_mapping'],
                'provider_id'         => $analysis['provider_id'],
                'provider_confidence' => $analysis['provider_confidence'],
                'excel_path'          => $excel_path,
                /* Conteo real de filas de datos del Excel (excluye cabecera). */
                'row_count'           => $analysis['row_count'] ?? 0,
            ], 200);

        } catch (\RuntimeException $e) {
            Log::warning('AdminSync\\AiExcelImportController::analyze - error de análisis', [
                'message' => $e->getMessage(),
                'user_id' => $user_id,
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('AdminSync\\AiExcelImportController::analyze - error inesperado', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'user_id' => $user_id,
            ]);

            return response()->json([
                'message' => 'Ocurrió un error inesperado al analizar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lanza la importación usando el mapeo confirmado y el owner indicado por user_id.
     *
     * Campos requeridos adicionales respecto al endpoint autenticado por Bearer:
     *  - user_id (int)
     *  - model (string): article | client | provider
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function import(Request $request)
    {
        $user_validation = $this->resolve_owner_user($request);
        if ($user_validation instanceof JsonResponse) {
            return $user_validation;
        }

        $model_validation = $this->resolve_model($request);
        if ($model_validation instanceof JsonResponse) {
            return $model_validation;
        }

        $user_id = (int) $request->input('user_id');
        $model   = (string) $request->input('model');

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

        $owner = User::find($user_id);

        if (is_null($owner)) {
            return response()->json([
                'message' => 'No se encontró el usuario propietario.',
            ], 403);
        }

        /*
         * Derivamos la importación al handler correspondiente según el modelo.
         * - article: usa InitExcelImport (jobs en background con chunks).
         * - client/provider: usa Maatwebsite Excel directamente, igual que los
         *   controladores web de clientes y proveedores.
         */
        if ($model === 'client') {
            return $this->import_clients($request, $user_id, $excel_full_path);
        }

        if ($model === 'provider') {
            return $this->import_providers($request, $user_id, $excel_full_path);
        }

        /* model === 'article': flujo original con InitExcelImport. */
        $import_uuid = (string) Str::uuid();

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
            'auth_user_id'          => $user_id,
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

    /**
     * Ejecuta la importación de clientes usando Maatwebsite Excel y ClientImport.
     *
     * ClientImport usa internamente Controller::userId() → UserHelper::userId() → Auth::user().
     * En el contexto admin-sync no hay sesión autenticada, por lo que hacemos login temporal
     * del owner antes de lanzar el import y logout inmediatamente después.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int                       $user_id         ID del usuario owner
     * @param  string                    $excel_full_path  Ruta absoluta al archivo Excel
     * @return \Illuminate\Http\JsonResponse
     */
    protected function import_clients(Request $request, int $user_id, string $excel_full_path): JsonResponse
    {
        /* Parámetros de importación extraídos del request. */
        $columns        = $request->input('columns', []);
        $create_and_edit = $request->input('create_and_edit', true);
        $start_row      = (int) $request->input('start_row', 2);
        $finish_row     = $request->input('finish_row', null);

        /* El finish_row vacío o cero se trata como "hasta la última fila". */
        if ($finish_row === '' || $finish_row === '0' || $finish_row === 0) {
            $finish_row = null;
        } elseif (!is_null($finish_row)) {
            $finish_row = (int) $finish_row;
        }

        try {
            /*
             * Login temporal del owner para que ClientImport pueda resolver userId()
             * correctamente en este contexto sin sesión autenticada.
             */
            Auth::loginUsingId($user_id);

            Excel::import(
                new ClientImport($columns, $create_and_edit, $start_row, $finish_row),
                $excel_full_path
            );

            Auth::logout();

            Log::info('AdminSync\\AiExcelImportController::import_clients - importación finalizada', [
                'user_id'  => $user_id,
                'start_row' => $start_row,
                'finish_row' => $finish_row,
            ]);

            return response()->json(['message' => 'Importación de clientes iniciada.'], 200);

        } catch (\Throwable $e) {
            /* Asegurar logout incluso ante error para no dejar sesión colgada. */
            Auth::logout();

            Log::error('AdminSync\\AiExcelImportController::import_clients - error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'user_id' => $user_id,
            ]);

            return response()->json([
                'message' => 'Ocurrió un error al importar clientes: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ejecuta la importación de proveedores usando Maatwebsite Excel y ProviderImport.
     *
     * Mismo patrón que import_clients: login temporal del owner antes del import
     * y logout inmediato después para resolver userId() en contexto admin-sync.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int                       $user_id         ID del usuario owner
     * @param  string                    $excel_full_path  Ruta absoluta al archivo Excel
     * @return \Illuminate\Http\JsonResponse
     */
    protected function import_providers(Request $request, int $user_id, string $excel_full_path): JsonResponse
    {
        /* Parámetros de importación extraídos del request. */
        $columns        = $request->input('columns', []);
        $create_and_edit = $request->input('create_and_edit', true);
        $start_row      = (int) $request->input('start_row', 2);
        $finish_row     = $request->input('finish_row', null);

        /* El finish_row vacío o cero se trata como "hasta la última fila". */
        if ($finish_row === '' || $finish_row === '0' || $finish_row === 0) {
            $finish_row = null;
        } elseif (!is_null($finish_row)) {
            $finish_row = (int) $finish_row;
        }

        try {
            /*
             * Login temporal del owner para que ProviderImport pueda resolver userId()
             * correctamente en este contexto sin sesión autenticada.
             * El quinto parámetro ($provider_id) es null porque no aplica en este flujo.
             */
            Auth::loginUsingId($user_id);

            Excel::import(
                new ProviderImport($columns, $create_and_edit, $start_row, $finish_row, null),
                $excel_full_path
            );

            Auth::logout();

            Log::info('AdminSync\\AiExcelImportController::import_providers - importación finalizada', [
                'user_id'   => $user_id,
                'start_row' => $start_row,
                'finish_row' => $finish_row,
            ]);

            return response()->json(['message' => 'Importación de proveedores iniciada.'], 200);

        } catch (\Throwable $e) {
            /* Asegurar logout incluso ante error para no dejar sesión colgada. */
            Auth::logout();

            Log::error('AdminSync\\AiExcelImportController::import_providers - error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'user_id' => $user_id,
            ]);

            return response()->json([
                'message' => 'Ocurrió un error al importar proveedores: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Valida que user_id venga informado y exista en la tabla users.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Models\User|\Illuminate\Http\JsonResponse
     */
    protected function resolve_owner_user(Request $request)
    {
        $user_id = $request->input('user_id');

        if ($user_id === null || $user_id === '') {
            return response()->json([
                'message' => 'El campo "user_id" es obligatorio.',
            ], 422);
        }

        if (!is_numeric($user_id) || (int) $user_id <= 0) {
            return response()->json([
                'message' => 'El campo "user_id" debe ser un entero válido.',
            ], 422);
        }

        $owner = User::find((int) $user_id);

        if (is_null($owner)) {
            return response()->json([
                'message' => 'No existe un usuario con el user_id indicado.',
            ], 422);
        }

        return $owner;
    }

    /**
     * Valida que model venga informado y sea uno de los valores permitidos.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|\Illuminate\Http\JsonResponse
     */
    protected function resolve_model(Request $request)
    {
        $model = $request->input('model');

        if ($model === null || $model === '') {
            return response()->json([
                'message' => 'El campo "model" es obligatorio (article, client o provider).',
            ], 422);
        }

        if (!in_array($model, self::ALLOWED_MODELS, true)) {
            return response()->json([
                'message' => 'El campo "model" debe ser "article", "client" o "provider".',
            ], 422);
        }

        return (string) $model;
    }

    /**
     * Valida presencia y formato del archivo Excel en el request multipart.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return true|\Illuminate\Http\JsonResponse
     */
    protected function validate_excel_file(Request $request)
    {
        if (!$request->hasFile('excel_file') || !$request->file('excel_file')->isValid()) {
            return response()->json([
                'message' => 'Se requiere un archivo Excel válido en el campo "excel_file".',
            ], 422);
        }

        $extension = strtolower($request->file('excel_file')->getClientOriginalExtension());

        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            return response()->json([
                'message' => 'El archivo debe ser un Excel válido (.xlsx o .xls).',
            ], 422);
        }

        return true;
    }
}
