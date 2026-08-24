<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;
use App\Http\Controllers\Helpers\import\article\ExcelNumericFormatStats;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Models\ExcelAnalysisRun;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que ejecuta en segundo plano el análisis (y, desde el prompt 03, también la
 * recomendación de configuración) de un Excel de importación (grupo 291).
 *
 * Antes de este job, AiExcelImportController::analyze() corría todo el análisis
 * (cuatro recorridos completos del archivo — count_data_rows, ExcelDuplicateStats,
 * analyze_identification_chain, ExcelNumericFormatStats — más la llamada a Claude)
 * de forma síncrona dentro del request HTTP. Con archivos grandes (~20.000 filas)
 * eso supera el timeout del proxy y el usuario ve un 504 sin mensaje útil.
 *
 * Este job mueve esa misma lógica (byte por byte, sin tocar AiExcelAnalyzer ni los
 * helpers de análisis) a un worker, y persiste progreso/resultado en la tabla
 * excel_analysis_runs para que el frontend haga polling con GET
 * /ai-excel-import/analysis/{uuid}.
 *
 * (Grupo 291, prompt 03) `getRecomendacion()` tenía el mismo problema — tres
 * recorridos del archivo más otra llamada a Claude — así que ahora también pasa
 * por este job, ramificando por `$run->tipo` ('analisis' vs 'recomendacion').
 * Dentro del job no hay usuario autenticado: todo lo que antes usaba
 * `$this->userId()` en el controller ahora usa `$run->user_id`.
 *
 * Riesgo conocido y aceptado: este job se despacha a la cola por defecto de la
 * conexión activa — la misma cola que usan los chunks de ProcessArticleImport /
 * ProcessArticleChunk (ninguno de los dos usa onQueue() explícito tampoco). Si el
 * worker está ocupado importando un Excel grande, el análisis espera su turno
 * detrás de esos chunks. Es preferible al 504 anterior, pero puede hacer que un
 * análisis tarde varios minutos en arrancar si hay una importación grande en
 * curso — si el día de mañana alguien se pregunta por qué, la respuesta es esta.
 */
class RunExcelAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Id de la corrida (excel_analysis_runs.id) a procesar.
     *
     * Se pasa solo el id (no el modelo completo) para que el payload serializado
     * del job quede chico y para no arrastrar datos que puedan haber cambiado
     * entre el momento del dispatch (dentro del request) y la ejecución real
     * (en el worker, potencialmente minutos después).
     *
     * @var int
     */
    protected $excel_analysis_run_id;

    /**
     * Tiempo máximo (segundos) que el worker le da a este job antes de matarlo.
     * Un análisis completo (varios recorridos del archivo + llamada a Claude,
     * que ya tiene su propio timeout de 60s) puede tardar varios minutos con
     * archivos grandes.
     *
     * @var int
     */
    public $timeout = 900;

    /**
     * No reintentar automáticamente: si el análisis falló, el problema está en
     * el contenido del archivo o en la respuesta de Claude, y reintentar solo
     * quemaría hasta 15 minutos más de worker para volver a llegar al mismo error.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * @param int $excel_analysis_run_id  Id de la ExcelAnalysisRun a procesar.
     */
    public function __construct(int $excel_analysis_run_id)
    {
        $this->excel_analysis_run_id = $excel_analysis_run_id;
    }

    /**
     * Ejecuta la corrida (análisis o recomendación, según `tipo`) y persiste el
     * resultado.
     *
     * @return void
     */
    public function handle()
    {
        /* Buscamos la corrida por id; si no existe (borrada, id inválido), no hay nada que hacer. */
        $run = ExcelAnalysisRun::find($this->excel_analysis_run_id);

        if (is_null($run)) {
            Log::warning('RunExcelAnalysisJob: no se encontró la ExcelAnalysisRun', [
                'excel_analysis_run_id' => $this->excel_analysis_run_id,
            ]);
            return;
        }

        /*
         * Limpieza: al arrancar cada corrida nueva, borramos las corridas de más
         * de 2 días de este mismo usuario. Sin esto, excel_analysis_runs crece
         * indefinidamente con JSONs grandes en "resultado" y "payload". Se hace
         * acá (y no en un comando aparte) para no depender de un cron extra.
         */
        ExcelAnalysisRun::where('user_id', $run->user_id)
            ->where('created_at', '<', now()->subDays(2))
            ->delete();

        /* Hito de progreso inicial: recién estamos por abrir el archivo. */
        $run->update([
            'estado'   => 'procesando',
            'progreso' => 5,
            'paso'     => 'Leyendo el archivo…',
        ]);

        /* Ruta absoluta del Excel, guardado previamente por el controller. */
        $excel_full_path = storage_path('app/' . $run->excel_path);

        if (!file_exists($excel_full_path)) {
            $this->finalizar_con_error($run, 'El archivo Excel indicado no existe o ha expirado.');
            return;
        }

        /*
         * (grupo 291, prompt 03) Ramificamos por tipo: 'recomendacion' mueve el
         * cuerpo que antes vivía en AiExcelImportController::getRecomendacion();
         * cualquier otro valor (por ahora solo 'analisis') sigue el camino
         * original de este job, sin cambios de comportamiento.
         */
        if ($run->tipo === 'recomendacion') {
            $this->handle_recomendacion($run, $excel_full_path);
            return;
        }

        $this->handle_analisis($run, $excel_full_path);
    }

    /**
     * Ejecuta el análisis inicial del Excel (tipo = 'analisis') y persiste el
     * resultado en la corrida. Es la lógica original de este job (prompt 02),
     * con el agregado de persistir `codigos_proveedor` (prompt 03).
     *
     * @param  \App\Models\ExcelAnalysisRun  $run              Corrida en curso, ya en estado "procesando"
     * @param  string                        $excel_full_path  Ruta absoluta al archivo Excel
     * @return void
     */
    protected function handle_analisis(ExcelAnalysisRun $run, string $excel_full_path)
    {
        /* Parámetros con los que el controller creó esta corrida (ver analyze()). */
        $payload           = $run->payload ?? [];
        $model             = (string) ($payload['model'] ?? 'article');
        $original_filename = (string) ($payload['original_filename'] ?? '');

        try {
            /*
             * Hoja elegida y fila de encabezado, resueltas antes de tocar el analyzer.
             *
             * Las tres claves del payload son opcionales y con default (§1.4 del plan): una
             * corrida creada por un cliente viejo —la SPA sin desplegar, o AdminSync— no las
             * tiene, y entonces esto da hoja 0 y fila_encabezado null, que es el
             * comportamiento de siempre.
             *
             * La resolución por NOMBRE se hace acá y no adentro del analyzer porque este es el
             * único punto que ve lo que mandó el navegador. El índice lo calculó SheetJS y el
             * que lee es OpenSpout: si el libro tiene chartsheets los dos pueden no coincidir,
             * y elegir la hoja equivocada no se ve en pantalla (ver T11 del plan).
             */
            $opciones = [
                'hoja'            => $this->resolver_indice_de_hoja($excel_full_path, $payload),
                'fila_encabezado' => isset($payload['header_row']) && is_numeric($payload['header_row'])
                                        ? (int) $payload['header_row']
                                        : null,
            ];

            /*
             * Elegimos el analizador según el modelo indicado: misma cadena de
             * if que tenía AiExcelImportController::analyze() antes de este
             * cambio, sin modificar ninguno de los analyzers.
             */
            if ($model === 'client') {
                $analyzer = new AiClientAnalyzer($run->user_id);
            } elseif ($model === 'provider') {
                $analyzer = new AiProviderAnalyzer($run->user_id);
            } else {
                /* model === 'article' o cualquier valor no reconocido: comportamiento original. */
                $analyzer = new AiExcelAnalyzer($run->user_id);
            }

            /* Hito de progreso: estamos por entrar a la parte más lenta (la llamada a Claude). */
            $run->update([
                'progreso' => 40,
                'paso'     => 'Analizando el archivo con IA…',
            ]);

            $analysis = $analyzer->analyze($excel_full_path, $original_filename, $opciones);

            /*
             * Armamos el array de resultado con exactamente las mismas claves y
             * los mismos valores que devolvía el endpoint síncrono antes de este
             * cambio (ver AiExcelImportController::analyze() previo al prompt 02
             * del grupo 291). El frontend no debe notar diferencia en el contrato
             * de "resultado" respecto de la respuesta 200 que recibía antes.
             */
            $resultado = [
                'column_mapping'        => $analysis['column_mapping'],
                'provider_id'           => $analysis['provider_id'],
                'provider_confidence'   => $analysis['provider_confidence'],
                'excel_path'            => $run->excel_path,
                'row_count'             => $analysis['row_count'] ?? 0,
                'assistant_notes'       => $analysis['assistant_notes'] ?? [],
                'duplicate_stats'       => $analysis['duplicate_stats'] ?? null,
                'preview_rows'          => $analysis['preview_rows'] ?? [],
                'placeholders'          => $analysis['placeholders'] ?? [],
                'cadena_identificacion' => $analysis['cadena_identificacion'] ?? null,
                'nombres_duplicados'    => $analysis['nombres_duplicados'] ?? null,
                'formatos_numericos'    => $analysis['formatos_numericos'] ?? null,

                /*
                 * 🔴 Estos cinco nombres de clave están congelados y la SPA ya está codeada
                 * contra ellos: si alguno se renombra acá, el modal no rompe — se queda mudo,
                 * que es peor. El selector de hoja no aparece, la alerta de columnas sin nombre
                 * no aparece, y el usuario importa a ciegas.
                 *
                 * Los produce el analyzer adentro de analyze(); este job solo los deja pasar,
                 * sin filtrarlos ni reinterpretarlos. Los defaults cubren dos casos reales:
                 * model=client/provider, cuyos analyzers pueden no devolver todo, y las corridas
                 * viejas que quedaron guardadas antes de esta misión.
                 */
                'hojas'                 => $analysis['hojas'] ?? [],
                'hoja_elegida'          => $analysis['hoja_elegida'] ?? null,
                'encabezado_detectado'  => $analysis['encabezado_detectado'] ?? null,
                'columnas_sin_nombre'   => $analysis['columnas_sin_nombre'] ?? [],
                'fusiones_aplicadas'    => $analysis['fusiones_aplicadas'] ?? 0,
            ];

            /*
             * (grupo 291, prompt 03) Persistimos la lista de provider_codes distintos
             * del archivo en codigos_proveedor: AiExcelAnalyzer::analyze() la extrajo
             * de duplicate_stats y la devuelve en $analysis['provider_codes_distintos']
             * (ver ese archivo para el porqué de sacarla de ahí). Con esto,
             * refreshProviderStats() puede recalcular sin releer el archivo.
             * Para model=client/provider este campo no viene (esos analyzers no usan
             * ExcelDuplicateStats), así que queda null igual que antes de este prompt;
             * refreshProviderStats() ya tiene fallback para ese caso.
             */
            $codigos_proveedor = $analysis['provider_codes_distintos'] ?? null;

            $run->update([
                'estado'            => 'listo',
                'progreso'          => 100,
                'paso'              => null,
                'resultado'         => $resultado,
                'codigos_proveedor' => $codigos_proveedor,
            ]);

            $this->notificar_fin($run);

        } catch (\RuntimeException $e) {
            /* Mismo caso que antes devolvía 422 con el mensaje propio del analyzer. */
            Log::warning('RunExcelAnalysisJob: error de análisis', [
                'excel_analysis_run_id' => $run->id,
                'message'                => $e->getMessage(),
            ]);

            $this->finalizar_con_error($run, $e->getMessage());

        } catch (\Throwable $e) {
            Log::error('RunExcelAnalysisJob: error inesperado', [
                'excel_analysis_run_id' => $run->id,
                'message'                => $e->getMessage(),
                'trace'                  => $e->getTraceAsString(),
            ]);

            /*
             * 🔴 El texto que ve el usuario NO lleva $e->getMessage().
             *
             * Adentro viaja la ruta absoluta del servidor: la IOException de OpenSpout con un
             * .xls viejo dice "Could not open C:\...\storage\app\imported_files\ai_import_1234.xlsx
             * for reading!", y hasta esta misión eso se concatenaba acá y aparecía tal cual en la
             * pantalla del cliente. El detalle completo sigue en el Log::error de arriba.
             *
             * Los errores que SÍ tienen algo útil para decirle al usuario llegan como
             * \RuntimeException (ver ExcelWorkbookReader::MENSAJE_ARCHIVO_ILEGIBLE) y los toma el
             * catch de arriba, que guarda ese mensaje tal cual porque fue escrito para él.
             */
            $this->finalizar_con_error($run, 'Ocurrió un error inesperado al analizar el archivo. Volvé a intentar; si sigue pasando, avisanos.');
        }
    }

    /**
     * Ejecuta el cálculo de la recomendación de configuración (tipo = 'recomendacion')
     * y persiste el resultado en la corrida (grupo 291, prompt 03).
     *
     * Es el mismo cuerpo que antes vivía, síncrono, en
     * AiExcelImportController::getRecomendacion(): recalcula duplicate_stats con el
     * proveedor confirmado por el usuario, formatos_numericos y duplicados_con_nombres,
     * y le pide a Claude la recomendación final.
     *
     * IMPORTANTE: dentro del job no hay usuario autenticado. Todo lo que en el
     * controller original resolvía con $this->userId() acá se resuelve con
     * $run->user_id (el id del usuario que disparó /get-recomendacion, guardado al
     * crear la corrida). Si esto se pierde y se cuela un Auth::user() implícito en
     * algún helper, la recomendación terminaría calculándose contra el catálogo de
     * otro cliente o contra ninguno.
     *
     * @param  \App\Models\ExcelAnalysisRun  $run              Corrida en curso, ya en estado "procesando"
     * @param  string                        $excel_full_path  Ruta absoluta al archivo Excel
     * @return void
     */
    protected function handle_recomendacion(ExcelAnalysisRun $run, string $excel_full_path)
    {
        /* Parámetros con los que el controller creó esta corrida (ver getRecomendacion()). */
        $payload = $run->payload ?? [];

        /* Proveedor confirmado por el usuario (puede ser null si no aplica). */
        $provider_id = $payload['provider_id'] ?? null;

        /* Índice 0-based de la columna provider_code en el Excel. */
        $provider_code_column_index = $payload['provider_code_column_index'] ?? null;

        /* Mapeo de columnas confirmado por el usuario en el paso 2 del modal. */
        $column_mapping = $payload['column_mapping'] ?? [];

        /*
         * Derivar el índice 0-based de la columna bar_code desde el column_mapping.
         * Igual que en el controller original: sin este índice, cuando no hay
         * provider_code, ExcelDuplicateStats retorna todo en 0.
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

        /*
         * Misma hoja y misma fila de encabezado con las que se analizó el archivo en el paso 1.
         * Opcionales con default: una corrida vieja (o un cliente que no las manda) recalcula
         * sobre la primera hoja con detección automática, igual que antes de esta misión.
         *
         * Acá no hace falta resolver por nombre: /get-recomendacion recibe el índice que ya se
         * eligió en el paso 1, no el nombre de la hoja.
         */
        $opciones = [
            'hoja'            => isset($payload['hoja']) && is_numeric($payload['hoja'])
                                    ? (int) $payload['hoja']
                                    : 0,
            'fila_encabezado' => isset($payload['header_row']) && is_numeric($payload['header_row'])
                                    ? (int) $payload['header_row']
                                    : null,
        ];

        try {
            /* Hito de progreso: por entrar a la parte más pesada del recorrido del archivo. */
            $run->update([
                'progreso' => 30,
                'paso'     => 'Calculando estadísticas…',
            ]);

            /*
             * Recalcular duplicate_stats con el proveedor real confirmado por el usuario,
             * igual que hacía el endpoint síncrono. $run->user_id reemplaza a
             * $this->userId(): no hay usuario autenticado dentro del job.
             */
            $stats = ExcelDuplicateStats::analyze(
                $excel_full_path,
                $bar_code_column_index,
                $provider_code_column_index,
                $provider_id,
                $run->user_id,
                $opciones
            );

            $analyzer = new AiExcelAnalyzer($run->user_id);

            /*
             * Recalcular formatos_numericos con el column_mapping confirmado por el
             * usuario (grupo 239, prompt 02), igual que hacía el endpoint síncrono.
             */
            $numeric_columns_map = $analyzer->build_numeric_columns_map($column_mapping);
            $formatos_numericos  = ExcelNumericFormatStats::analyze(
                $excel_full_path,
                $numeric_columns_map['indices'],
                $numeric_columns_map['nombres'],
                /*
                 * $max_ejemplos explícito con su valor por defecto: PHP 7.4 no tiene argumentos
                 * nombrados, así que para llegar al 5º parámetro hay que pasar el 4º sí o sí.
                 */
                6,
                $opciones
            );

            /*
             * Nombres de las filas con código de proveedor repetido (grupo 284, prompt 03):
             * evidencia que le permite a Claude recomendar politica_intra_archivo.
             */
            $duplicados_con_nombres = $analyzer->build_duplicados_con_nombres(
                $excel_full_path,
                $stats['detalle_provider_codes_duplicados'] ?? [],
                $column_mapping,
                /*
                 * Misma hoja y mismo encabezado que el resto del paso 2. Si esto quedara en el
                 * default, los nombres de los artículos repetidos saldrían de la hoja 0 mientras
                 * los códigos salieron de la elegida: Claude recomendaría una política mirando
                 * nombres de otra planilla, y en pantalla no habría un solo indicio.
                 */
                $opciones['hoja'],
                $opciones['fila_encabezado']
            );

            /* Hito de progreso: por entrar a la llamada a Claude. */
            $run->update([
                'progreso' => 80,
                'paso'     => 'Generando recomendación con IA…',
            ]);

            /* Generar recomendación con los stats recalculados para el proveedor confirmado. */
            $recomendacion = $analyzer->ask_claude_for_recomendation($stats, $column_mapping, $formatos_numericos, $duplicados_con_nombres);

            /*
             * Mismo array que devolvía el endpoint síncrono en su respuesta 200
             * (ver AiExcelImportController::getRecomendacion() previo a este prompt).
             * No incluye 'provider_codes_distintos': $stats la trae ahora (prompt 03),
             * pero acá se listan explícitamente solo las claves que el frontend espera.
             */
            $resultado = [
                'recomendacion_configuracion'                 => $recomendacion,
                'provider_codes_existentes_mismo_proveedor'   => $stats['provider_codes_existentes_mismo_proveedor'] ?? 0,
                'provider_codes_existentes_otros_proveedores' => $stats['provider_codes_existentes_otros_proveedores'] ?? 0,
                'formatos_numericos'                          => $formatos_numericos,
            ];

            $run->update([
                'estado'    => 'listo',
                'progreso'  => 100,
                'paso'      => null,
                'resultado' => $resultado,
            ]);

            $this->notificar_fin($run);

        } catch (\RuntimeException $e) {
            /* Mismo caso que antes devolvía 422 con el mensaje propio del analyzer. */
            Log::warning('RunExcelAnalysisJob: error de recomendación', [
                'excel_analysis_run_id' => $run->id,
                'message'                => $e->getMessage(),
            ]);

            $this->finalizar_con_error($run, $e->getMessage());

        } catch (\Throwable $e) {
            Log::error('RunExcelAnalysisJob: error inesperado al generar la recomendación', [
                'excel_analysis_run_id' => $run->id,
                'message'                => $e->getMessage(),
                'trace'                  => $e->getTraceAsString(),
            ]);

            /* Mismo criterio que handle_analisis(): la ruta del servidor va al log, no a la pantalla. */
            $this->finalizar_con_error($run, 'Ocurrió un error inesperado al generar la recomendación. Volvé a intentar; si sigue pasando, avisanos.');
        }
    }

    /**
     * Deja la corrida en estado de error y le avisa al usuario.
     *
     * Existe para que ninguna de las cinco salidas de error del job pueda
     * olvidarse del aviso. Mientras el usuario esperaba mirando el modal, un
     * error simplemente aparecía en pantalla; ahora puede estar en cualquier otra
     * parte del sistema, y un error sin aviso es una espera infinita.
     *
     * @param  \App\Models\ExcelAnalysisRun  $run
     * @param  string                        $mensaje  Texto legible para el usuario
     * @return void
     */
    protected function finalizar_con_error(ExcelAnalysisRun $run, string $mensaje)
    {
        $run->update([
            'estado' => 'error',
            'error'  => $mensaje,
        ]);

        $this->notificar_fin($run);
    }

    /**
     * Avisa por broadcast que la corrida terminó (bien o mal).
     *
     * El aviso lleva lo mínimo para poder decir "terminó el análisis de tal
     * archivo" y para saber adónde ir si el usuario quiere verlo: uuid, tipo,
     * estado y módulo. El resumen del análisis NO viaja acá — se pide recién
     * cuando el usuario aprieta el botón, que es justamente lo que le permite
     * ignorar el aviso sin haber pagado nada por él.
     *
     * Nunca deja que un problema al notificar tumbe la corrida: el análisis ya
     * está hecho y guardado, y el usuario lo va a encontrar igual a través de
     * /analysis-en-curso la próxima vez que cargue la SPA.
     *
     * @param  \App\Models\ExcelAnalysisRun  $run
     * @return void
     */
    protected function notificar_fin(ExcelAnalysisRun $run)
    {
        /*
         * Corridas viejas (anteriores a este cambio) no tienen auth_user_id. Sin
         * ese dato el aviso llegaría a todos los empleados del comercio, así que
         * preferimos no avisar: el resultado sigue estando y el modal lo levanta
         * cuando el usuario vuelve a abrirlo.
         */
        if (empty($run->auth_user_id)) {
            return;
        }

        try {
            /*
             * La notificación se emite sobre el canal del owner (así lo hace
             * GlobalNotification::broadcastOn) y se filtra en el frontend por
             * is_only_for_auth_user.
             */
            $owner = User::find($run->user_id);

            if (is_null($owner)) {
                return;
            }

            $presentacion = $run->datos_de_presentacion();

            /* Entre comillas y con nombre si lo tenemos; si no, "el archivo" a secas. */
            $archivo = $presentacion['original_filename'] !== ''
                ? '"' . $presentacion['original_filename'] . '"'
                : 'el archivo';

            $es_recomendacion = $run->tipo === 'recomendacion';

            if ($run->estado === 'listo') {
                $message_text = $es_recomendacion
                    ? 'Está lista la recomendación de importación para ' . $archivo
                    : 'Terminó el análisis de ' . $archivo;
            } else {
                $message_text = $es_recomendacion
                    ? 'No se pudo generar la recomendación para ' . $archivo
                    : 'No se pudo analizar ' . $archivo;
            }

            $owner->notify(new GlobalNotification([
                'message_text'          => $message_text,
                'color_variant'         => $run->estado === 'listo' ? 'success' : 'danger',
                /*
                 * info_to_show y functions_to_execute son el plan B: si el SPA que
                 * recibe esto todavía no conoce el modal excel_analysis_ready, cae
                 * en el global-notification genérico, y ahí estos dos campos son lo
                 * único que se muestra. El aviso queda pobre pero no queda mudo.
                 */
                'info_to_show'          => [],
                'functions_to_execute'  => [
                    [
                        'btn_text'      => 'Entendido',
                        'function_name' => 'close_notification_modal',
                        'btn_variant'   => 'primary',
                    ],
                ],
                'owner_id'              => $run->user_id,
                /* Solo para quien subió el Excel, no para todo el comercio. */
                'is_only_for_auth_user' => $run->auth_user_id,
                'notification_modal'    => 'excel_analysis_ready',
                'excel_analysis'        => [
                    'uuid'              => $run->uuid,
                    'tipo'              => $run->tipo,
                    'estado'            => $run->estado,
                    'error'             => $run->error,
                    'model'             => $presentacion['model'],
                    'original_filename' => $presentacion['original_filename'],
                ],
            ]));

        } catch (\Throwable $e) {
            Log::error('RunExcelAnalysisJob: no se pudo notificar el fin de la corrida', [
                'excel_analysis_run_id' => $run->id,
                'message'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Se ejecuta cuando el job falla de forma no controlada (timeout del worker
     * matando el proceso, error fatal, etc.) y nunca llega a su propio catch
     * dentro de handle(). Sin esto, la corrida quedaría en estado "procesando"
     * para siempre y el frontend giraría sin fin esperando un estado que nunca
     * cambia.
     *
     * @param  \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('RunExcelAnalysisJob: failed()', [
            'excel_analysis_run_id' => $this->excel_analysis_run_id,
            'message'                => $exception->getMessage(),
            'trace'                  => $exception->getTraceAsString(),
        ]);

        $run = ExcelAnalysisRun::find($this->excel_analysis_run_id);

        /* Si por alguna carrera rarísima ya quedó "listo", no lo pisamos con error. */
        if (!is_null($run) && $run->estado !== 'listo') {
            /*
             * Sin $exception->getMessage(), mismo motivo que los catch de handle_analisis() y
             * handle_recomendacion(): por acá también pasan excepciones de OpenSpout con la ruta
             * absoluta del servidor adentro, y este texto se le muestra al cliente. El detalle,
             * con trace, quedó en el Log::error de arriba.
             */
            $this->finalizar_con_error($run, 'El análisis falló inesperadamente. Volvé a intentar; si sigue pasando, avisanos.');
        }
    }

    /**
     * Índice 0-based de la hoja a analizar, resuelto a partir de lo que mandó el cliente.
     *
     * Prioridad: nombre exacto -> índice en rango -> 0, que es la de
     * ExcelWorkbookReader::resolver_indice().
     *
     * El atajo del principio no es una micro-optimización: `hoja` ausente o 0 y sin
     * `hoja_nombre` es EXACTAMENTE el caso del cliente viejo (SPA sin desplegar, AdminSync),
     * y en ese caso no se abre el libro de más. resolver_indice() lista las hojas, lo que
     * implica abrir el libro una vez más: se paga sólo cuando el usuario efectivamente eligió
     * una hoja.
     *
     * @param  string $excel_full_path
     * @param  array  $payload          payload de la corrida
     * @return int
     *
     * @throws \RuntimeException  si el archivo no se puede abrir (mensaje limpio, sin la ruta)
     */
    protected function resolver_indice_de_hoja($excel_full_path, array $payload)
    {
        $hoja = isset($payload['hoja']) && is_numeric($payload['hoja']) ? (int) $payload['hoja'] : 0;

        $hoja_nombre = isset($payload['hoja_nombre']) && is_string($payload['hoja_nombre']) && trim($payload['hoja_nombre']) !== ''
            ? trim($payload['hoja_nombre'])
            : null;

        if ($hoja <= 0 && is_null($hoja_nombre)) {
            return 0;
        }

        return ExcelWorkbookReader::resolver_indice($excel_full_path, $hoja, $hoja_nombre);
    }
}
