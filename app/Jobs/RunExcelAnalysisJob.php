<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Models\ExcelAnalysisRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que ejecuta en segundo plano el análisis de un Excel de importación
 * (grupo 291, prompt 02).
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
     * Ejecuta el análisis del Excel y persiste el resultado en la corrida.
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
            $run->update([
                'estado' => 'error',
                'error'  => 'El archivo Excel indicado no existe o ha expirado.',
            ]);
            return;
        }

        /* Parámetros con los que el controller creó esta corrida (ver analyze()). */
        $payload           = $run->payload ?? [];
        $model             = (string) ($payload['model'] ?? 'article');
        $original_filename = (string) ($payload['original_filename'] ?? '');

        try {
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

            $analysis = $analyzer->analyze($excel_full_path, $original_filename);

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
            ];

            /*
             * codigos_proveedor queda en null en este prompt: extraer la lista de
             * códigos distintos del archivo desde acá exigiría un quinto recorrido
             * (ExcelDuplicateStats no los expone en su resultado hoy). El prompt 03
             * se encarga de eso desde ExcelDuplicateStats y ya tiene fallback para
             * cuando este campo viene null.
             */
            $run->update([
                'estado'    => 'listo',
                'progreso'  => 100,
                'paso'      => null,
                'resultado' => $resultado,
            ]);

        } catch (\RuntimeException $e) {
            /* Mismo caso que antes devolvía 422 con el mensaje propio del analyzer. */
            Log::warning('RunExcelAnalysisJob: error de análisis', [
                'excel_analysis_run_id' => $run->id,
                'message'                => $e->getMessage(),
            ]);

            $run->update([
                'estado' => 'error',
                'error'  => $e->getMessage(),
            ]);

        } catch (\Throwable $e) {
            Log::error('RunExcelAnalysisJob: error inesperado', [
                'excel_analysis_run_id' => $run->id,
                'message'                => $e->getMessage(),
                'trace'                  => $e->getTraceAsString(),
            ]);

            $run->update([
                'estado' => 'error',
                'error'  => 'Ocurrió un error inesperado al analizar el archivo: ' . $e->getMessage(),
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
            $run->update([
                'estado' => 'error',
                'error'  => 'El análisis falló inesperadamente: ' . $exception->getMessage(),
            ]);
        }
    }
}
