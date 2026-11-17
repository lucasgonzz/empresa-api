<?php

namespace Tests\Support;

use App\Http\Controllers\Helpers\masivas\EjecucionPorTandasHelper;
use App\Jobs\ProcessMasiveUpdateJob;
use App\Jobs\ProcessMasiveUpdateRevertJob;
use App\Models\MasiveUpdate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Fixture para regresiones de cálculo que antes dependían de QUEUE=sync.
 * Mantiene las aserciones históricas y ejecuta las tandas reales, con pausas de reloj simuladas;
 * no desactiva cupos, transacciones, candados, middleware ni exige una excepción por APP_ENV.
 */
trait EjecutaMasivasAsincronas
{
    public function putJson($uri, array $data = [], array $headers = [])
    {
        $masiva = preg_match('#api/update/article$#i', $uri) === 1;
        if ($masiva) {
            Queue::fake([ProcessMasiveUpdateJob::class, ProcessMasiveUpdateRevertJob::class]);
        }
        $respuesta = parent::putJson($uri, $data, $headers);
        if ($masiva) {
            $this->terminar_masiva_de_calculo($respuesta);
        }
        return $respuesta;
    }

    public function postJson($uri, array $data = [], array $headers = [])
    {
        $masiva = preg_match('#api/masive-update/\d+/revert$#', $uri) === 1;
        if ($masiva) {
            Queue::fake([ProcessMasiveUpdateJob::class, ProcessMasiveUpdateRevertJob::class]);
        }
        $respuesta = parent::postJson($uri, $data, $headers);
        if ($masiva) {
            $this->terminar_masiva_de_calculo($respuesta);
        }
        return $respuesta;
    }

    private function terminar_masiva_de_calculo($respuesta)
    {
        if ($respuesta->getStatusCode() !== 200 || !$respuesta->json('masive_update_id')) {
            return;
        }
        $reloj = Carbon::getTestNow();
        try {
            for ($i = 0; $i < 62; $i++) {
                $masiva = MasiveUpdate::findOrFail($respuesta->json('masive_update_id'));
                if (!in_array($masiva->status, ['pending', 'processing'], true)) {
                    return;
                }
                $estado = EjecucionPorTandasHelper::estado($masiva);
                Carbon::setTestNow(Carbon::createFromTimestamp(max(now()->timestamp, $estado['no_antes'])));
                $job = $masiva->action === 'revert'
                    ? new ProcessMasiveUpdateRevertJob($masiva->id, $estado['cursor'], $estado['generacion'])
                    : new ProcessMasiveUpdateJob($masiva->id, $estado['cursor'], $estado['generacion']);
                // Mismos eventos que emite un worker: conservar el marco/silencio de auditoría.
                $envoltura = new \Illuminate\Queue\Jobs\SyncJob(app(), json_encode([
                    'displayName' => get_class($job), 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                    'data' => ['commandName' => get_class($job), 'command' => serialize($job)],
                ]), 'prueba_asincrona', 'default');
                event(new \Illuminate\Queue\Events\JobProcessing('prueba_asincrona', $envoltura));
                try {
                    $job->handle();
                } finally {
                    event(new \Illuminate\Queue\Events\JobProcessed('prueba_asincrona', $envoltura));
                }
            }
            throw new \RuntimeException('El fixture no pudo terminar las tandas de cálculo.');
        } finally {
            Carbon::setTestNow($reloj);
        }
    }
}
