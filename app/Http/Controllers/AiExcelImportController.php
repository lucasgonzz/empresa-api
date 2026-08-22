<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;
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
                /*
                 * Quién disparó la corrida, para dirigirle el aviso de "terminó"
                 * cuando el job la complete. El canal de global_notification es
                 * del owner y lo escuchan todos sus empleados: sin este id, el
                 * aviso le llegaría también a los que no subieron nada.
                 */
                'auth_user_id' => optional(auth()->user())->id,
                'tipo'       => 'analisis',
                'estado'     => 'pendiente',
                'excel_path' => $excel_path,
                'payload'    => [
                    'model'             => $model,
                    'original_filename' => $original_filename,
                    /*
                     * Rango de filas y detección de cabecera. El frontend los calcula
                     * leyendo el Excel en el navegador (paso 1) y hasta ahora vivían
                     * solo ahí. Los guardamos porque el modal se puede reabrir en el
                     * paso 2 desde otra pestaña o después de un F5, y en ese momento
                     * el archivo local ya no existe: sin estos tres valores el modal
                     * no puede rearmar el rango a importar.
                     */
                    'start_row'         => $request->input('start_row'),
                    'finish_row'        => $request->input('finish_row'),
                    'has_header_row'    => $request->input('has_header_row'),

                    /*
                     * Hoja elegida por el usuario en el paso 1 y fila de encabezado.
                     *
                     * 🔴 Los tres son OPCIONALES y tienen default, y eso no es prolijidad:
                     * es lo que hace que un cliente viejo siga funcionando. La SPA sin
                     * desplegar no manda ninguno de los tres, y AdminSync\AiExcelImportController
                     * —el que usa admin-api en producción— tampoco. Con los defaults de acá
                     * (hoja 0, sin nombre, sin fila de encabezado) esas dos puntas se comportan
                     * exactamente como antes de esta misión: primera hoja y detección automática.
                     *
                     * Viajan hoja Y hoja_nombre a propósito: el índice lo calcula SheetJS en el
                     * navegador y OpenSpout es quien después lee, y los dos no están garantizados
                     * a coincidir si el libro tiene chartsheets. ExcelWorkbookReader::resolver_indice()
                     * resuelve por nombre primero, y para eso el nombre tiene que llegar hasta el job.
                     *
                     * NO se guarda en columnas propias: excel_analysis_runs.payload es longText con
                     * cast 'array' y el modelo tiene $guarded = [], así que esto no lleva migración.
                     */
                    'hoja'              => self::normalizar_hoja($request->input('hoja')),
                    'hoja_nombre'       => self::normalizar_hoja_nombre($request->input('hoja_nombre')),
                    'header_row'        => self::normalizar_header_row($request->input('header_row')),
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

            /*
             * 🔴 El mensaje del usuario NO lleva $e->getMessage(), y no es por prolijidad.
             * Adentro de esa excepción viaja la ruta absoluta del servidor: la IOException de
             * OpenSpout dice "Could not open C:\...\storage\app\imported_files\ai_import_1234.xlsx
             * for reading!", y hasta esta misión eso se concatenaba acá y se le mostraba tal cual
             * al cliente. El detalle sigue estando —completo, con trace— en el Log::error de arriba,
             * que es donde sirve para diagnosticar.
             */
            return response()->json([
                'message' => 'Ocurrió un error inesperado al iniciar el análisis. Volvé a intentar en unos minutos; si sigue pasando, avisanos.',
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
            'uuid'     => $run->uuid,
            'tipo'     => $run->tipo,
            'estado'   => $run->estado,
            'progreso' => $run->progreso,
            'paso'     => $run->paso,
            'error'    => $run->error,
            /*
             * Estado del modal al momento de encolar la corrida. Va siempre, no
             * solo cuando terminó: el modal se puede reabrir con el análisis
             * todavía en curso y necesita saber de qué archivo se trata.
             */
            'contexto' => $run->contexto_para_frontend(),
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
     * Devuelve la corrida de análisis que este usuario tiene "abierta": o bien una
     * que todavía está corriendo, o bien una que ya terminó y que él nunca llegó a
     * mirar.
     *
     * Es lo que permite que el análisis sobreviva a cerrar la pestaña. El aviso de
     * "terminó" viaja por broadcast, pero un broadcast solo llega a quien está
     * conectado en ese momento: si el usuario encoló el análisis y se fue, el evento
     * pasó y no vuelve. La SPA llama a este endpoint al arrancar y recupera lo que
     * se haya perdido mientras no estaba.
     *
     * Se limita a corridas del propio auth_user (no del owner): el Excel lo subió
     * una persona, y es a esa persona a la que le interesa el resultado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function analysisEnCurso()
    {
        $auth_user_id = optional(auth()->user())->id;

        if (is_null($auth_user_id)) {
            return response()->json(['run' => null], 200);
        }

        /*
         * Solo miramos las últimas 48 horas porque es lo que el job conserva:
         * RunExcelAnalysisJob borra al arrancar las corridas de más de 2 días.
         * Ofrecer una corrida más vieja que eso sería ofrecer algo cuyo archivo
         * y cuyo resultado ya pueden no estar.
         */
        $run = ExcelAnalysisRun::where('auth_user_id', $auth_user_id)
            ->where('created_at', '>=', now()->subDays(2))
            ->whereNull('visto_at')
            ->orderBy('id', 'DESC')
            ->first();

        if (is_null($run)) {
            return response()->json(['run' => null], 200);
        }

        return response()->json([
            'run' => [
                'uuid'     => $run->uuid,
                'tipo'     => $run->tipo,
                'estado'   => $run->estado,
                'progreso' => $run->progreso,
                'paso'     => $run->paso,
                'error'    => $run->error,
                'contexto' => $run->contexto_para_frontend(),
            ],
        ], 200);
    }

    /**
     * Marca una corrida como vista, para que no se le vuelva a ofrecer al usuario
     * en la próxima carga de la SPA.
     *
     * Se llama tanto cuando el usuario abre el resultado como cuando descarta el
     * aviso: en los dos casos ya se enteró, que es de lo único que lleva registro
     * este campo.
     *
     * @param  string  $uuid
     * @return \Illuminate\Http\JsonResponse
     */
    public function marcarAnalisisVisto($uuid)
    {
        $run = ExcelAnalysisRun::where('uuid', $uuid)
            ->where('user_id', $this->userId())
            ->first();

        if (is_null($run)) {
            return response()->json(['message' => 'No se encontro el analisis'], 404);
        }

        $run->update(['visto_at' => now()]);

        return response(null, 200);
    }

    /**
     * Recalcula los conteos de provider_codes existentes en BD para un proveedor específico.
     *
     * Se llama desde el frontend cuando el usuario cambia el proveedor seleccionado en el paso 2,
     * para actualizar los chips "ya en BD (mismo proveedor)" y "ya en BD (otro proveedor)"
     * con el proveedor real en lugar del inferido por Claude.
     *
     * (Grupo 291, prompt 03) Antes, este endpoint releía el archivo completo en cada
     * cambio de proveedor — con archivos grandes, varios segundos por click, dentro de
     * un request síncrono. Ahora, si existe un análisis previo ("listo", con los
     * provider_codes ya extraídos por RunExcelAnalysisJob), reusa esa lista y solo hace
     * el cruce contra la base. Si no existe (archivo viejo, run limpiada por antigüedad,
     * o análisis hecho antes de este cambio), cae al comportamiento original leyendo el
     * archivo — el fallback no es opcional.
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
        /* Ruta relativa del Excel guardado en /analyze; obligatoria para localizar el análisis/archivo. */
        $excel_path = $request->input('excel_path');

        if (empty($excel_path)) {
            return response()->json(['message' => 'El campo "excel_path" es obligatorio.'], 422);
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
         * Hoja y fila de encabezado con las que se analizó el archivo. Opcionales con default
         * (hoja 0, encabezado automático): un cliente viejo que no los manda recalcula sobre la
         * primera hoja, que es lo que hacía antes de esta misión.
         *
         * Acá no se resuelve por nombre: este endpoint recibe el índice ya elegido en el paso 1,
         * no el nombre. La resolución por nombre vive en el job, que es el único que arranca
         * desde lo que mandó el navegador.
         */
        $opciones_de_hoja = [
            'hoja'            => self::normalizar_hoja($request->input('hoja')),
            'fila_encabezado' => self::normalizar_header_row($request->input('header_row')),
        ];

        /*
         * Camino rápido (grupo 291, prompt 03): buscamos el último análisis "listo" de
         * este mismo excel_path, para este usuario, con los provider_codes ya extraídos.
         * Filtramos también por usuario para que nadie pueda reusar códigos de otro
         * usuario adivinando/reutilizando un excel_path.
         */
        $run = ExcelAnalysisRun::where('user_id', $this->userId())
            ->where('excel_path', $excel_path)
            ->where('tipo', 'analisis')
            ->where('estado', 'listo')
            ->whereNotNull('codigos_proveedor')
            ->orderBy('id', 'DESC')
            ->first();

        if (!is_null($run)) {
            /* Solo el cruce contra la base: sin releer el archivo. */
            $stats = ExcelDuplicateStats::crossCheckProviderCodes(
                $run->codigos_proveedor,
                $provider_id,
                $this->userId()
            );

            return response()->json([
                'provider_codes_existentes_mismo_proveedor'   => $stats['provider_codes_existentes_mismo_proveedor'],
                'provider_codes_existentes_otros_proveedores' => $stats['provider_codes_existentes_otros_proveedores'],
            ], 200);
        }

        /*
         * Fallback: no hay análisis previo utilizable, releemos el archivo como antes
         * de este prompt. Ruta absoluta en storage donde quedó persistido el archivo.
         */
        $excel_full_path = storage_path('app/' . $excel_path);

        if (!file_exists($excel_full_path)) {
            return response()->json(['message' => 'El archivo Excel indicado no existe o ha expirado.'], 422);
        }

        /*
         * bar_code_column en null: solo recalcula existentes por provider_code,
         * sin releer códigos de barras (más liviano para este endpoint).
         */
        $stats = ExcelDuplicateStats::analyze(
            $excel_full_path,
            null,
            $provider_code_column_index,
            $provider_id,
            $this->userId(),
            $opciones_de_hoja
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
            // Prompt 310: flags "permitir_valores_en_blanco" por columna (default: ninguna, todas OFF).
            'blank_flags'           => $request->input('blank_flags', []),

            /*
             * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026): la planilla declara si sus
             * costos vienen con el IVA adentro.
             *
             * 🔴 Este flujo NO pasa por ArticleController::import(): arma su propio array y llama
             * derecho a InitExcelImport, así que cualquier clave que no se agregue acá llega con el
             * default del backend. Sin esta línea, una importación por IA de una planilla con
             * costos brutos los guardaría como netos y el costo quedaría 21% inflado, en silencio,
             * mientras el import clásico hace lo correcto con la misma planilla.
             *
             * `boolean()` y no `(bool)`: el flujo de IA manda JSON con un booleano real, pero
             * `(bool) 'false'` en PHP da TRUE, así que el cast crudo prendería el desglose justo
             * cuando el usuario no lo pidió. `boolean()` tolera 1/0, "1"/"0", true/false y
             * "true"/"false".
             */
            'precios_incluyen_iva'  => $request->boolean('precios_incluyen_iva'),
            'create_and_edit'       => $request->input('create_and_edit', false),

            /*
             * Hoja a importar, 0-based. Default 0 = primera hoja, o sea lo que hacía este
             * endpoint antes de esta misión.
             *
             * 🔴 Acá NO viaja header_row, y es a propósito: la importación real se rige por
             * start_row, que ya viaja, que el usuario ve en pantalla y que puede corregir a
             * mano. Mandar además la fila de encabezado sería tener dos fuentes de verdad para
             * "desde dónde arranco a leer", y la primera vez que difieran se importa de más o
             * de menos sin que nada avise.
             *
             * 🔴 Y OJO con esto (T5 del plan): armar_archivo_csv() vuelca la hoja elegida al CSV
             * 1:1 con preserveEmptyRows(true), así que línea de CSV = fila del Excel DE ESA HOJA.
             * start_row/finish_row tienen que venir calculados sobre la MISMA hoja que este
             * 'hoja'. Si el navegador calculó finish_row mirando la hoja 0 y manda hoja=1, se
             * importa de menos en silencio (ajustar_finish_row_segun_excel_real() recorta por
             * arriba, no completa por abajo).
             */
            'hoja'                  => $request->input('hoja', 0),
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
     * Encola la recomendación de configuración de importación usando el proveedor
     * ya confirmado por el usuario en el paso 2 del modal (grupo 291, prompt 03).
     *
     * A diferencia de /analyze (que usa el proveedor inferido por Claude),
     * este endpoint recibe el provider_id real elegido por el usuario,
     * recalcula los duplicate_stats con ese proveedor, y genera la recomendación
     * con datos correctos.
     *
     * Antes de este prompt, este método calculaba la recomendación de forma
     * síncrona dentro del request (tres recorridos completos del archivo + la
     * llamada a Claude), con el mismo problema de timeout que tenía /analyze
     * antes del prompt 02. Ahora solo valida y crea una ExcelAnalysisRun en
     * estado "pendiente" con tipo = 'recomendacion', encola RunExcelAnalysisJob
     * (mismo mecanismo del prompt 02) y devuelve 202 con el uuid. El resultado
     * se consulta con el mismo GET /ai-excel-import/analysis/{uuid} que ya usa
     * el análisis inicial.
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

        /*
         * Verificamos que el archivo siga existiendo antes de encolar: si ya expiró,
         * mejor devolver el 422 de inmediato que gastar un ciclo de cola para lo mismo.
         */
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

        try {
            /*
             * Creamos la corrida en estado "pendiente", igual que analyze() (prompt 02).
             * payload guarda todo lo que RunExcelAnalysisJob::handle_recomendacion()
             * necesita para reconstruir el mismo comportamiento que antes tenía este
             * método de forma síncrona: dentro del job no hay $this->userId(), así que
             * todo lo relevante del request queda acá.
             */
            $run = ExcelAnalysisRun::create([
                'uuid'       => Str::uuid()->toString(),
                'user_id'    => $this->userId(),
                /* Mismo motivo que en analyze(): a quién le corresponde el aviso. */
                'auth_user_id' => optional(auth()->user())->id,
                'tipo'       => 'recomendacion',
                'estado'     => 'pendiente',
                'excel_path' => $excel_path,
                'payload'    => [
                    'provider_id'                 => $provider_id,
                    'provider_code_column_index'  => $provider_code_column_index,
                    'column_mapping'              => $column_mapping,
                    /*
                     * uuid de la corrida de análisis de la que salió este paso 2.
                     * La recomendación sola no alcanza para rearmar el paso 3: la
                     * pantalla también muestra duplicados, placeholders y cadena de
                     * identificación, que son del análisis. Guardando el uuid padre,
                     * el modal puede reconstruir los dos pasos pidiendo primero el
                     * análisis y después esta recomendación.
                     */
                    'analysis_uuid'               => $request->input('analysis_uuid'),

                    /*
                     * Misma hoja y misma fila de encabezado con las que se hizo el análisis del
                     * que salió este paso 2. Opcionales con default (hoja 0, encabezado
                     * automático) por la misma razón que en analyze(): la SPA sin desplegar no
                     * los manda y tiene que seguir andando igual que hoy.
                     *
                     * Si no se guardaran, la recomendación se calcularía sobre la hoja 0 mientras
                     * el usuario eligió otra: los duplicados, los formatos numéricos y la
                     * política recomendada saldrían de una planilla distinta de la que se va a
                     * importar, y en pantalla no habría un solo indicio de eso.
                     */
                    'hoja'                        => self::normalizar_hoja($request->input('hoja')),
                    'header_row'                  => self::normalizar_header_row($request->input('header_row')),
                ],
            ]);

            /* Encolamos el job pasando solo el id (mismo criterio que analyze()). */
            RunExcelAnalysisJob::dispatch($run->id);

            /*
             * 202 Accepted: la recomendación quedó encolada, todavía no hay resultado.
             * El frontend hace polling a analysisStatus() con este uuid, igual que /analyze.
             */
            return response()->json([
                'analysis_uuid' => $run->uuid,
                'estado'        => $run->estado,
            ], 202);

        } catch (\Throwable $e) {
            Log::error('AiExcelImportController::getRecomendacion - error al encolar la recomendación', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            /* Mismo criterio que analyze(): la ruta del servidor va al log, no a la pantalla. */
            return response()->json([
                'message' => 'Ocurrió un error inesperado al iniciar la recomendación. Volvé a intentar en unos minutos; si sigue pasando, avisanos.',
            ], 500);
        }
    }

    /**
     * Índice 0-based de hoja recibido del cliente, normalizado.
     *
     * Default 0 (primera hoja) para cualquier cosa que no sea un número >= 0: ausente,
     * vacío, "null", basura. Ese default ES el contrato de compatibilidad hacia atrás
     * (§1.4 del plan): un cliente que no manda 'hoja' —la SPA sin desplegar, o
     * AdminSync\AiExcelImportController, que es el que usa admin-api en producción— tiene
     * que comportarse exactamente como antes de esta misión.
     *
     * @param  mixed $valor
     * @return int
     */
    protected static function normalizar_hoja($valor)
    {
        if (is_numeric($valor) && (int) $valor >= 0) {
            return (int) $valor;
        }

        return 0;
    }

    /**
     * Nombre de hoja recibido del cliente, normalizado a string no vacío o null.
     *
     * Viaja junto con el índice porque los dos pueden discrepar: el índice lo calcula
     * SheetJS en el navegador y OpenSpout es quien lee después.
     * ExcelWorkbookReader::resolver_indice() le da prioridad al nombre.
     *
     * @param  mixed $valor
     * @return string|null
     */
    protected static function normalizar_hoja_nombre($valor)
    {
        if (is_string($valor) && trim($valor) !== '') {
            return trim($valor);
        }

        return null;
    }

    /**
     * Fila de encabezado 1-based recibida del cliente, normalizada.
     *
     * Default null = "detectala vos". Cualquier valor menor a 1 también cae a null: una
     * fila 0 no existe en un Excel, y dejarla pasar activaría la rama de encabezado
     * corrido con un número imposible.
     *
     * @param  mixed $valor
     * @return int|null
     */
    protected static function normalizar_header_row($valor)
    {
        if (is_numeric($valor) && (int) $valor >= 1) {
            return (int) $valor;
        }

        return null;
    }
}
