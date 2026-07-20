<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\import\article\ImportFailureHandler;
use App\Models\ImportHistory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Detecta importaciones de artículos colgadas (en estado activo sin actividad por más de N minutos) y las
 * marca como fallidas para desbloquear al usuario.
 *
 * Red de seguridad del failed() de los jobs: cubre el caso en que un proceso muere sin dejar traza (OOM,
 * timeout, worker reiniciado) y la cola tarda ~40 min o directamente no llega a dispararlo.
 */
class DetectarImportacionesColgadas extends Command
{
    /**
     * @var string
     */
    protected $signature = 'imports:detectar-colgadas';

    /**
     * @var string
     */
    protected $description = 'Marca como fallidas las importaciones que quedaron colgadas en estado activo sin actividad.';

    /**
     * @return int
     */
    public function handle()
    {
        /* Umbral en minutos. Debe superar el timeout de un lote (1800s) + latencia de cola para no matar
           imports que legítimamente están corriendo. Nota: con config:cache, env() en runtime devuelve
           null; si algún día se cachea la config, mover esto a config/app.php. */
        $umbral_minutos = (int) env('IMPORT_STUCK_MINUTES', 45);
        if ($umbral_minutos < 45) {
            $umbral_minutos = 45;
        }

        // Instante límite: todo import activo con updated_at anterior a esto se considera colgado.
        $limite = Carbon::now()->subMinutes($umbral_minutos);

        // Imports en estado activo (bloqueante para el usuario) sin actividad reciente.
        // No incluye 'fallo' ni 'terminado', por lo que un import ya resuelto nunca se reprocesa aquí.
        $colgadas = ImportHistory::whereIn('status', array('en_preparacion', 'en_proceso'))
            ->where('updated_at', '<', $limite)
            ->get();

        if ($colgadas->isEmpty()) {
            return 0;
        }

        // Mensaje humano único para todos los colgados de esta corrida: no hay excepción real que
        // explique el motivo puntual, el proceso simplemente desapareció.
        $mensaje = 'La importación quedó sin actividad por más de ' . $umbral_minutos . ' minutos. '
            . 'El proceso se interrumpió sin dejar traza (probable falta de memoria, timeout del proceso o '
            . 'worker reiniciado). Se marcó como fallida automáticamente para desbloquear nuevas importaciones. '
            . 'Revisá el pico de memoria del último lote en el historial para diagnosticar.';

        foreach ($colgadas as $import_history) {

            Log::warning('Watchdog: importación colgada marcada como fallida.', array(
                'import_history_id' => $import_history->id,
                'user_id'           => $import_history->user_id,
                'status_previo'     => $import_history->status,
                'updated_at'        => (string) $import_history->updated_at,
                'processed_chunks'  => $import_history->processed_chunks,
                'total_chunks'      => $import_history->total_chunks,
            ));

            // Reutiliza el handler único de marcado de fallos (prompt 501): idempotente y no tira nunca.
            // import_status_id puede ser null en registros previos al prompt 500; en ese caso solo se
            // marca el history (alcanza para desbloquear, porque el gate mira el history).
            ImportFailureHandler::registrar(
                $import_history->id,
                $import_history->import_status_id,
                $import_history->user_id,
                $mensaje,
                null, // sin trace: no hubo excepción, el proceso desapareció
                $import_history->processed_chunks
            );
        }

        $this->info('Importaciones colgadas marcadas como fallidas: ' . $colgadas->count());

        return 0;
    }
}
