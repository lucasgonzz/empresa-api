<?php

namespace App\Jobs;

use App\Exports\ProviderExport;
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
use Maatwebsite\Excel\Facades\Excel;

class ProcessProviderExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InstrumentaMemoria;

    /**
     * Timeout amplio para exportaciones con muchos registros.
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
     * Usuario owner que recibirá la notificación final.
     *
     * @var int
     */
    protected $owner_user_id;

    /**
     * Usuario autenticado al solicitar la exportación (restricción de notificación).
     *
     * @var int
     */
    protected $auth_user_id;

    /**
     * Registro de historial asociado a esta exportación.
     *
     * @var int
     */
    protected $export_history_id;

    /**
     * Crea el job de exportación de proveedores en segundo plano.
     *
     * @param int $owner_user_id
     * @param int $auth_user_id
     * @param int $export_history_id
     */
    public function __construct($owner_user_id, $auth_user_id, $export_history_id)
    {
        $this->owner_user_id = (int) $owner_user_id;
        $this->auth_user_id = (int) $auth_user_id;
        $this->export_history_id = (int) $export_history_id;
    }

    /**
     * Genera el excel, actualiza historial y notifica al usuario.
     *
     * @return void
     */
    public function handle()
    {
        $export_history = ExportHistory::find($this->export_history_id);

        try {
            // Best effort: subir memory_limit del worker si viene bajo, y cortar temprano si ya arrancamos al tope.
            $this->asegurar_memoria_minima();
            $this->verificar_memoria_disponible('exportación de proveedores');

            $owner_user = User::find($this->owner_user_id);
            if (is_null($owner_user)) {
                Log::warning('ProcessProviderExportJob: owner no encontrado', [
                    'owner_user_id' => $this->owner_user_id,
                ]);
                if ($export_history) {
                    ExportHistoryHelper::mark_failed($export_history, 'Usuario owner no encontrado');
                }
                return;
            }

            $file_name = 'comerciocity-proveedores_' . date_format(Carbon::now(), 'd-m-y_H-i-s') . '_' . uniqid() . '.xlsx';
            $relative_path = 'exported-files/' . $file_name;

            $provider_export = new ProviderExport(null, $this->owner_user_id);
            $exported_count = $provider_export->collection()->count();
            Excel::store($provider_export, $relative_path);

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
                        $exported_count . ' proveedores exportados',
                    ],
                ],
            ];

            $owner_user->notify(new GlobalNotification([
                'message_text' => 'El excel de proveedores ya esta listo para descargar',
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

            Log::error('ProcessProviderExportJob: error al generar exportacion', [
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
                'No se pudo generar el excel de proveedores',
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
            'No se pudo generar el excel de proveedores',
            $motivo
        );
    }
}
