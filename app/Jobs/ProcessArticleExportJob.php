<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\Excel\Article\ArticleExportStreamer;
use App\Http\Controllers\Helpers\ExportHistoryHelper;
use App\Http\Controllers\Helpers\jobs\BackgroundJobFailureHandler;
use App\Jobs\Concerns\InstrumentaMemoria;
use App\Models\ExportHistory;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessArticleExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InstrumentaMemoria;

    /**
     * Define timeout amplio para exportaciones grandes.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Evita reprocesos duplicados sobre una misma exportación solicitada.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Guarda el usuario owner que recibirá la notificación final.
     *
     * @var int
     */
    protected $owner_user_id;

    /**
     * Guarda el id del usuario autenticado al solicitar la exportación.
     *
     * @var int
     */
    protected $auth_user_id;

    /**
     * Guarda los ids de artículos a exportar.
     *
     * @var array
     */
    protected $article_ids;

    /**
     * Registro de historial asociado a esta exportación.
     *
     * @var int
     */
    protected $export_history_id;

    /**
     * Crea el job de exportación en segundo plano.
     *
     * @param int $owner_user_id
     * @param int $auth_user_id
     * @param array $article_ids
     * @param int $export_history_id
     */
    public function __construct($owner_user_id, $auth_user_id, $article_ids, $export_history_id)
    {
        $this->owner_user_id = (int) $owner_user_id;
        $this->auth_user_id = (int) $auth_user_id;
        $this->article_ids = is_array($article_ids) ? $article_ids : [];
        $this->export_history_id = (int) $export_history_id;

        /*
         * En shared hosting va a la cola 'excel' (separada del asistente por WhatsApp/panel),
         * para que un import o export grande no retenga el mismo worker. En el VPS, null: sigue
         * en 'default', la única que el supervisor de cada cliente consume hoy.
         *
         * 🔴 Va en el constructor (propiedad pública $queue del trait Queueable), no como método
         * viaQueue(): ese hook de Laravel es solo para event listeners en cola, nunca se invoca
         * para Jobs.
         */
        $this->queue = config('app.VPS') ? null : 'excel';
    }

    /**
     * Ejecuta la generación de excel, guarda el archivo y notifica resultado.
     *
     * @return void
     */
    public function handle()
    {
        $export_history = ExportHistory::find($this->export_history_id);

        /*
         * El registro visible nació en `pendiente` cuando se creó el historial (en el request);
         * acá recién lo levanta un worker y pasa a en_proceso. Si el historial no existe
         * (despacho viejo), por_referencia() da null y el helper no hace nada.
         */
        BackgroundProcessHelper::avanzar(BackgroundProcessHelper::por_referencia($export_history), null, [
            'etapa' => 'Generando el archivo',
        ]);

        try {
            // Best effort: subir memory_limit del worker si viene bajo, y cortar temprano si ya arrancamos al tope.
            $this->asegurar_memoria_minima();
            $this->verificar_memoria_disponible('exportación de artículos');

            $owner_user = User::find($this->owner_user_id);
            if (is_null($owner_user)) {
                Log::warning('ProcessArticleExportJob: owner no encontrado', [
                    'owner_user_id' => $this->owner_user_id,
                ]);
                if ($export_history) {
                    ExportHistoryHelper::mark_failed($export_history, 'Usuario owner no encontrado');
                }
                return;
            }

            $es_seleccion = count($this->article_ids) > 0;

            $file_name = 'comerciocity-articulos_' . date_format(Carbon::now(), 'd-m-y_H-i-s') . '_' . uniqid() . '.xlsx';
            $relative_path = 'exported-files/' . $file_name;

            /*
             * El Excel se escribe por lotes (ArticleExportStreamer): antes esto era un
             * Excel::store() de un FromCollection que traía el catálogo entero de una. Con los
             * 750k artículos de Servian el worker reventaba 4 GB al minuto (28/9/2026).
             */
            $streamer = new ArticleExportStreamer($this->owner_user_id, $es_seleccion ? $this->article_ids : null);

            $proceso = BackgroundProcessHelper::por_referencia($export_history);
            BackgroundProcessHelper::avanzar($proceso, 0, [
                'total' => $streamer->total(),
                'etapa' => 'Escribiendo los artículos',
            ]);

            /*
             * Latido del historial: el watchdog (historiales:detectar-colgados) da por muerta una
             * exportación cuyo updated_at no se mueve en 90 minutos. Con avance real por lote, una
             * exportación larga pero viva no puede caer en esa red.
             */
            $ultimo_latido = time();
            $exported_count = $streamer->guardar($relative_path, function ($escritos) use ($proceso, $export_history, &$ultimo_latido) {

                // El helper ya limita la frecuencia de escritura y de broadcast.
                BackgroundProcessHelper::avanzar($proceso, $escritos);

                if (!is_null($export_history) && time() - $ultimo_latido >= 60) {
                    $export_history->touch();
                    $ultimo_latido = time();
                }
            });

            $download_link = $export_history
                ? ExportHistoryHelper::mark_completed($export_history, $file_name, $exported_count)
                : ExportHistoryHelper::build_download_url($file_name);

            $functions_to_execute = [
                [
                    'btn_text' => 'Descargar excel',
                    'btn_variant' => 'primary',
                    'link' => $download_link,
                ],
            ];

            $info_to_show = [
                [
                    'title' => 'Resultado de la exportacion',
                    'parrafos' => [
                        $es_seleccion
                            ? $exported_count . ' articulos exportados'
                            : 'Exportacion solicitada para todos los articulos (' . $exported_count . ')',
                    ],
                ],
            ];

            $owner_user->notify(new GlobalNotification([
                'message_text' => 'El excel de articulos ya esta listo para descargar',
                'color_variant' => 'success',
                'functions_to_execute' => $functions_to_execute,
                'info_to_show' => $info_to_show,
                'owner_id' => $owner_user->id,
                'is_only_for_auth_user' => $this->auth_user_id,
            ]));

            // Instrumentación: pico de memoria del proceso durante esta exportación (para diagnosticar OOM).
            if (!is_null($export_history)) {
                $export_history->peak_memory_mb = $this->peak_memory_mb();
                $export_history->memory_limit_mb = $this->memory_limit_mb();
                $export_history->save();

                Log::info('Exportación finalizada — pico memoria: ' . $export_history->peak_memory_mb
                    . ' MB de ' . ($export_history->memory_limit_mb ?: 'sin límite') . ' MB');
            }
        } catch (\Throwable $e) {

            Log::error('ProcessArticleExportJob: error al generar exportacion', [
                'owner_user_id'     => $this->owner_user_id,
                'auth_user_id'      => $this->auth_user_id,
                'export_history_id' => $this->export_history_id,
                'message'           => $e->getMessage(),
                'archivo'           => $e->getFile(),
                'linea'             => $e->getLine(),
            ]);

            // Punto único idempotente: marca 'failed' + notifica (una sola vez, aunque después corra failed()).
            BackgroundJobFailureHandler::marcar_export_fallido(
                $this->export_history_id,
                $this->owner_user_id,
                $this->auth_user_id,
                'No se pudo generar el excel de articulos',
                $e->getMessage()
            );

            // Re-lanzamos: deja traza en failed_jobs y evita que el job se marque como exitoso.
            throw $e;
        }
    }

    /**
     * Se ejecuta cuando el job falla en forma definitiva, INCLUIDO cuando el proceso murió sin llegar al
     * catch del handle() (OOM, kill del LVE de CloudLinux, timeout de proceso, worker reiniciado →
     * MaxAttemptsExceededException lanzada por el Worker). Corre en un proceso fresco: puede escribir en la
     * BD y notificar aunque el proceso anterior haya muerto por falta de memoria.
     *
     * Idempotente vía BackgroundJobFailureHandler: si el catch del handle() ya lo marcó, esto es no-op.
     *
     * @param  \Throwable  $e
     * @return void
     */
    public function failed($e)
    {
        $motivo = !is_null($e) ? $e->getMessage() : null;

        BackgroundJobFailureHandler::marcar_export_fallido(
            $this->export_history_id,
            $this->owner_user_id,
            $this->auth_user_id,
            'No se pudo generar el excel de articulos',
            $motivo
        );
    }
}
