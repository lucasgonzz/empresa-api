<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\InitExcelImport;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Middleware\AdminApiKey;
use App\Imports\ClientImport;
use App\Imports\ProviderImport;
use App\Jobs\RunExcelAnalysisJob;
use App\Models\Article;
use App\Models\ExcelAnalysisRun;
use App\Models\Image;
use App\Models\User;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        if ($this->bandera($request->input('automatizar_prueba'))) {
            return app(AdminApiKey::class)->handle($request, function (Request $request) {
                return $this->analizar_archivo($request, true);
            }, AdminApiKey::MODO_SIEMPRE);
        }

        return $this->analizar_archivo($request, false);
    }

    protected function analizar_archivo(Request $request, bool $automatizar): JsonResponse
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

        if ($model === 'article' && $automatizar) {
            return ImportacionDePruebaController::iniciar($request, $user_id);
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
             * 🔴 EN SEGUNDO PLANO, SI EL ADMIN LO PIDE (misión cruzada prueba-martin-importacion,
             * 3/11/2026). El Excel que el negocio en prueba le manda a Martín por el ticket de soporte
             * no lo sube nadie desde el modal: el admin lo reenvía acá con `segundo_plano=1`, y lo que
             * hace falta es que quede EXACTAMENTE como si el dueño lo hubiera subido él —una
             * `ExcelAnalysisRun` suya, el job de siempre, el aviso `excel_analysis_ready` en el sistema
             * y la corrida abierta que la SPA levanta al entrar (`analysis-en-curso`)—, más el aviso
             * por WhatsApp si `avisar_por_whatsapp=1` (AvisosDeImportacionHelper). Sin el campo, este
             * endpoint sigue haciendo lo de siempre: el análisis síncrono con el mapeo en la respuesta.
             */
            if ($this->en_segundo_plano($request)) {
                return $this->encolar_analisis($request, $user_id, $model, $excel_path, $original_filename);
            }

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

        } catch (\Illuminate\Database\QueryException $e) {
            /*
             * 🔴 VA ANTES DEL CATCH DE \RuntimeException Y NO ES CASUALIDAD.
             * QueryException extiende PDOException, que extiende RuntimeException: puesto
             * después no corre nunca, y el catch de abajo devuelve el getMessage() tal cual,
             * o sea el SQL con los bindings. Y este controlador es el que le contesta a
             * admin-api, así que ese texto termina en lo que el admin le muestra al cliente.
             * Ver RunExcelAnalysisJob::MENSAJE_ERROR_DE_BASE.
             */
            Log::error('AdminSync\\AiExcelImportController::analyze - error de base', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'user_id' => $user_id,
            ]);

            return response()->json([
                'message' => RunExcelAnalysisJob::MENSAJE_ERROR_DE_BASE,
            ], 500);

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

            /*
             * Sin $e->getMessage(): adentro viaja la ruta absoluta del servidor. Del otro lado
             * de este endpoint hay un admin de ComercioCity, no el comerciante, por eso el
             * cierre es "avisá al equipo" y no "avisanos".
             */
            return response()->json([
                'message' => 'No pudimos analizar el archivo. Revisá que sea un Excel válido y volvé a intentar; si sigue pasando, avisá al equipo.',
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
            // Prueba gratuita (C4, misión prueba-experiencia-empresa, 3/11/2026): de dónde viene el archivo
            // (`excel` | `pdf` | `martin`). Opcional; sin él, por esta puerta es Martín.
            'prueba_origen'         => $request->input('origen', 'martin'),
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

            // Prueba gratuita (misión prueba-experiencia-empresa, 3/11/2026): el primer import se lleva lo de ejemplo.
            PruebaHelper::borrar_semilla_antes_de_importar($user_id, $request->input('origen', 'martin'));
            Excel::import(
                new ClientImport($columns, $create_and_edit, $start_row, $finish_row),
                $excel_full_path
            );
            PruebaHelper::importacion_terminada($user_id, 'client');

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

            /* Mismo criterio que analyze(): el detalle técnico va al log, no a la respuesta. */
            return response()->json([
                'message' => 'No pudimos importar los clientes de este archivo. Volvé a intentar; si sigue pasando, avisá al equipo.',
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

            // Prueba gratuita (misión prueba-experiencia-empresa, 3/11/2026): el primer import se lleva lo de ejemplo.
            PruebaHelper::borrar_semilla_antes_de_importar($user_id, $request->input('origen', 'martin'));
            Excel::import(
                new ProviderImport($columns, $create_and_edit, $start_row, $finish_row, null),
                $excel_full_path
            );
            PruebaHelper::importacion_terminada($user_id, 'provider');

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

            /* Mismo criterio que analyze(): el detalle técnico va al log, no a la respuesta. */
            return response()->json([
                'message' => 'No pudimos importar los proveedores de este archivo. Volvé a intentar; si sigue pasando, avisá al equipo.',
            ], 500);
        }
    }

    /**
     * Si el admin pidió el análisis en segundo plano (`segundo_plano=1`). Ausente o vacío = no, que
     * es lo que manda un admin anterior a la misión prueba-martin-importacion.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function en_segundo_plano(Request $request): bool
    {
        return $this->bandera($request->input('segundo_plano'));
    }

    /**
     * Una bandera del admin ("1", true, "si") a booleano. Vacío o ausente = false.
     *
     * @param  mixed  $valor
     * @return bool
     */
    protected function bandera($valor): bool
    {
        if ($valor === true) {
            return true;
        }

        if (is_null($valor)) {
            return false;
        }

        return in_array(strtolower(trim((string) $valor)), ['1', 'true', 'si', 'sí'], true);
    }

    /**
     * Crea la corrida de análisis del dueño y la encola (misión prueba-martin-importacion, 3/11/2026).
     *
     * Misma forma de `payload` que el `analyze()` autenticado por Bearer (hoja 0, encabezado detectado,
     * sin rango: son los defaults con los que ese endpoint trata a una SPA sin desplegar), más tres
     * claves propias: `origen` (`martin` o `pdf`: de dónde vino el archivo), `avisar_por_whatsapp` y
     * `provider_id` (el proveedor, si el admin lo sabe; la recomendación del paso 2 lo vuelve a pedir).
     *
     * `auth_user_id` es el dueño: es a quien le llega `excel_analysis_ready` y a quien la SPA le levanta
     * la corrida abierta al entrar. Sin él, el job no avisa a nadie (ver RunExcelAnalysisJob).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $user_id
     * @param  string  $model
     * @param  string  $excel_path
     * @param  string  $original_filename
     * @return \Illuminate\Http\JsonResponse  202 {analysis_uuid, excel_path, estado}
     */
    protected function encolar_analisis(Request $request, int $user_id, string $model, string $excel_path, string $original_filename): JsonResponse
    {
        $origen = trim((string) $request->input('origen', ''));

        $provider_id = $request->input('provider_id');
        $provider_id = is_numeric($provider_id) && (int) $provider_id > 0 ? (int) $provider_id : null;

        $run = ExcelAnalysisRun::create([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $user_id,
            'auth_user_id' => $user_id,
            'tipo'         => 'analisis',
            'estado'       => 'pendiente',
            'excel_path'   => $excel_path,
            'payload'      => [
                'model'               => $model,
                'original_filename'   => $original_filename,
                'start_row'           => null,
                'finish_row'          => null,
                'has_header_row'      => null,
                'hoja'                => 0,
                'hoja_nombre'         => null,
                'header_row'          => null,
                'origen'              => $origen !== '' ? mb_substr($origen, 0, 40) : 'admin',
                'avisar_por_whatsapp' => $this->bandera($request->input('avisar_por_whatsapp')),
                'provider_id'         => $provider_id,
            ],
        ]);

        RunExcelAnalysisJob::dispatch($run->id);

        Log::info('AdminSync\\AiExcelImportController::analyze - corrida encolada en segundo plano', [
            'excel_analysis_run_id' => $run->id,
            'uuid'                  => $run->uuid,
            'user_id'               => $user_id,
            'model'                 => $model,
            'origen'                => $run->payload['origen'],
        ]);

        return response()->json([
            'analysis_uuid' => $run->uuid,
            'excel_path'    => $excel_path,
            'estado'        => $run->estado,
        ], 202);
    }

    /**
     * Estado (y resultado, si terminó) de una corrida encolada con `segundo_plano=1`, para que el admin
     * o la tarea que armó el Excel la consulten. Misma forma que el `analysisStatus()` autenticado.
     *
     * `user_id` es obligatorio y la corrida tiene que ser suya: el uuid solo no alcanza para decidir de
     * quién es, y en las bases compartidas viejas conviven varios comercios.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $uuid
     * @return \Illuminate\Http\JsonResponse  200 · 404 · 422
     */
    public function analysisStatus(Request $request, $uuid): JsonResponse
    {
        $user_validation = $this->resolve_owner_user($request);
        if ($user_validation instanceof JsonResponse) {
            return $user_validation;
        }

        $run = ExcelAnalysisRun::where('uuid', (string) $uuid)
            ->where('user_id', (int) $request->input('user_id'))
            ->first();

        if (is_null($run)) {
            return response()->json(['message' => 'No se encontró el análisis.'], 404);
        }

        return response()->json([
            'uuid'      => $run->uuid,
            'tipo'      => $run->tipo,
            'estado'    => $run->estado,
            'progreso'  => $run->progreso,
            'paso'      => $run->paso,
            'error'     => $run->error,
            'contexto'  => $run->contexto_para_frontend(),
            'resultado' => $run->estado === 'listo' ? $run->resultado : null,
        ], 200);
    }

    /** Extensiones de imagen que se aceptan, con el mime que tiene que declarar el archivo. */
    const EXTENSIONES_DE_IMAGEN = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /** Peso máximo de cada imagen, en bytes. */
    const MAX_BYTES_IMAGEN = 4 * 1024 * 1024;

    /** Imágenes por pedido, como mucho. */
    const MAX_IMAGENES_POR_PEDIDO = 50;

    /**
     * Asigna fotos a los artículos del dueño por su código (misión prueba-martin-importacion, 3/11/2026).
     *
     * Es la mitad que el importador de 4 pasos no tiene: la columna `imagen` solo la mira para las
     * variantes (ProcessRow::build_variant_payload), así que las fotos que la tarea del PDF saca del
     * catálogo del proveedor no tenían por dónde entrar. Multipart: `user_id`, `imagenes[]` (jpg, png o
     * webp, hasta 4 MiB cada una, hasta 50 por pedido) y, opcionales, `provider_id` (para buscar también
     * por el código de proveedor de ese proveedor) y `reemplazar=1` (pisa la foto de un artículo que ya
     * tiene). El NOMBRE de cada archivo, sin extensión, es el código: se busca, dentro del dueño, por
     * `bar_code`, por `provider_code` y por el `provider_code` del pivot con el proveedor.
     *
     * 🔴 Un artículo que ya tiene imagen NO se pisa sin `reemplazar`: la foto que el dueño cargó a mano
     * vale más que la del catálogo. Y nunca se borran archivos del disco: al reemplazar se quitan las
     * filas de `images` del artículo y queda la nueva.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse  200 {asignadas, ya_tenian, sin_articulo, rechazadas} · 422
     */
    public function imagenes(Request $request): JsonResponse
    {
        $user_validation = $this->resolve_owner_user($request);
        if ($user_validation instanceof JsonResponse) {
            return $user_validation;
        }

        $user_id = (int) $request->input('user_id');

        $archivos = $request->file('imagenes');

        if (!is_array($archivos) || count($archivos) === 0) {
            return response()->json([
                'message' => 'Se requiere al menos una imagen en el campo "imagenes[]".',
            ], 422);
        }

        if (count($archivos) > self::MAX_IMAGENES_POR_PEDIDO) {
            return response()->json([
                'message' => 'Hasta ' . self::MAX_IMAGENES_POR_PEDIDO . ' imágenes por pedido.',
            ], 422);
        }

        $provider_id = $request->input('provider_id');
        $provider_id = is_numeric($provider_id) && (int) $provider_id > 0 ? (int) $provider_id : null;

        $reemplazar = $this->bandera($request->input('reemplazar'));

        $asignadas    = 0;
        $ya_tenian    = [];
        $sin_articulo = [];
        $rechazadas   = [];

        foreach ($archivos as $archivo) {
            if (!is_object($archivo) || !method_exists($archivo, 'isValid') || !$archivo->isValid()) {
                $rechazadas[] = ['codigo' => '', 'motivo' => 'archivo_invalido'];
                continue;
            }

            $nombre    = (string) $archivo->getClientOriginalName();
            $codigo    = trim((string) pathinfo($nombre, PATHINFO_FILENAME));
            $extension = strtolower((string) $archivo->getClientOriginalExtension());

            if ($codigo === '') {
                $rechazadas[] = ['codigo' => '', 'motivo' => 'sin_codigo'];
                continue;
            }

            if (!isset(self::EXTENSIONES_DE_IMAGEN[$extension])) {
                $rechazadas[] = ['codigo' => $codigo, 'motivo' => 'formato'];
                continue;
            }

            if ((int) $archivo->getSize() > self::MAX_BYTES_IMAGEN) {
                $rechazadas[] = ['codigo' => $codigo, 'motivo' => 'peso'];
                continue;
            }

            $mime = (string) $archivo->getMimeType();
            if (strpos($mime, 'image/') !== 0) {
                $rechazadas[] = ['codigo' => $codigo, 'motivo' => 'contenido'];
                continue;
            }

            $article = $this->articulo_por_codigo($user_id, $codigo, $provider_id);

            if (is_null($article)) {
                $sin_articulo[] = $codigo;
                continue;
            }

            $tiene_imagen = $article->images()->exists();

            if ($tiene_imagen && !$reemplazar) {
                $ya_tenian[] = $codigo;
                continue;
            }

            try {
                $guardado = (string) Str::uuid() . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);

                Storage::disk('public')->put($guardado, file_get_contents($archivo->getRealPath()));

                if ($tiene_imagen) {
                    $article->images()->delete();
                }

                Image::create([
                    'hosting_url'    => ApiUrlHelper::storage($guardado),
                    'imageable_id'   => $article->id,
                    'imageable_type' => 'article',
                ]);

                /* Igual que una imagen asignada por el motor: sin apagar timestamps, para que el sync
                   incremental del front baje el artículo con su imagen. */
                $article->needs_sync_with_tn = true;
                $article->save();

                try {
                    TiendaNubeSyncArticleService::add_article_to_sync($article);
                } catch (\Throwable $e) {
                    // Sin Tienda Nube no hay nada que encolar.
                }

                $asignadas++;
            } catch (\Throwable $e) {
                Log::warning('AdminSync\\AiExcelImportController::imagenes - no se pudo guardar una imagen', [
                    'user_id' => $user_id,
                    'codigo'  => $codigo,
                    'error'   => $e->getMessage(),
                ]);

                $rechazadas[] = ['codigo' => $codigo, 'motivo' => 'no_se_pudo_guardar'];
            }
        }

        return response()->json([
            'asignadas'    => $asignadas,
            'ya_tenian'    => $ya_tenian,
            'sin_articulo' => $sin_articulo,
            'rechazadas'   => $rechazadas,
        ], 200);
    }

    /**
     * El artículo del dueño con ese código: por `bar_code`, por `provider_code` o por el `provider_code`
     * del pivot con el proveedor indicado. El primero que aparece, en ese orden.
     *
     * @param  int  $user_id
     * @param  string  $codigo
     * @param  int|null  $provider_id
     * @return \App\Models\Article|null
     */
    protected function articulo_por_codigo(int $user_id, string $codigo, $provider_id)
    {
        $article = Article::where('user_id', $user_id)
            ->where(function ($query) use ($codigo) {
                $query->where('bar_code', $codigo)
                      ->orWhere('provider_code', $codigo);
            })
            ->orderBy('id')
            ->first();

        if (!is_null($article) || is_null($provider_id)) {
            return $article;
        }

        return Article::where('user_id', $user_id)
            ->whereHas('providers', function ($query) use ($codigo, $provider_id) {
                $query->where('providers.id', $provider_id)
                      ->where('article_provider.provider_code', $codigo);
            })
            ->orderBy('id')
            ->first();
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
