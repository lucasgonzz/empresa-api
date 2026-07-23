<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ExportHistoryHelper;
use App\Models\ExportHistory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Detecta historiales de trabajo en segundo plano que quedaron colgados (en estado activo sin actividad por
 * más de N minutos) y los marca como fallidos para desbloquear al usuario.
 *
 * Red de seguridad del failed() de los jobs: cubre el caso en que un proceso murió sin dejar traza (OOM,
 * kill del LVE, worker reiniciado) y la cola tarda o directamente no llega a dispararlo.
 *
 * Diseñado genérico: el array $tipos permite sumar otros historiales (ej: importaciones, cuando corra el
 * grupo 116) sin duplicar el comando.
 */
class DetectarHistorialesColgados extends Command
{
    /**
     * @var string
     */
    protected $signature = 'historiales:detectar-colgados';

    /**
     * @var string
     */
    protected $description = 'Marca como fallidos los historiales (exportaciones, etc.) que quedaron colgados en estado activo sin actividad.';

    /**
     * @return int
     */
    public function handle()
    {
        /* Umbral en minutos. Debe superar el timeout del job (3600s = 60 min) + latencia de cola para no
           matar trabajos que legítimamente están corriendo. Nota: con config:cache, env() en runtime puede
           devolver null; si algún día se cachea la config, mover esto a config/app.php. */
        $umbral_minutos = (int) env('EXPORT_STUCK_MINUTES', 90);
        if ($umbral_minutos < 90) {
            $umbral_minutos = 90;
        }

        $limite = Carbon::now()->subMinutes($umbral_minutos);
        $total = 0;

        foreach ($this->tipos($umbral_minutos) as $tipo) {

            $colgados = call_user_func($tipo['buscar'], $limite);

            foreach ($colgados as $registro) {

                Log::warning('Watchdog: ' . $tipo['label'] . ' colgado marcado como fallido.', array(
                    'id'         => $registro->id,
                    'status'     => $registro->status,
                    'updated_at' => (string) $registro->updated_at,
                ));

                call_user_func($tipo['marcar'], $registro, $tipo['mensaje']);
                $total++;
            }
        }

        $this->info('Historiales colgados marcados como fallidos: ' . $total);

        return 0;
    }

    /**
     * Configuración de los tipos de historial a vigilar. Cada tipo sabe cómo buscar sus colgados y cómo
     * marcarlos fallidos. Agregar import acá cuando corra el grupo 116 (mismo shape).
     *
     * @param  int  $umbral_minutos
     * @return array
     */
    private function tipos($umbral_minutos)
    {
        $mensaje_export = 'La exportación quedó sin actividad por más de ' . $umbral_minutos . ' minutos. '
            . 'El proceso se interrumpió sin dejar traza (probable falta de memoria, timeout del proceso o '
            . 'worker reiniciado). Se marcó como fallida automáticamente para desbloquear nuevas exportaciones.';

        return array(

            'export' => array(
                'label'   => 'export_history',
                'mensaje' => $mensaje_export,
                'buscar'  => function ($limite) {
                    return ExportHistory::whereIn('status', array('pending', 'processing'))
                        ->where('updated_at', '<', $limite)
                        ->get();
                },
                'marcar'  => function ($registro, $mensaje) {
                    ExportHistoryHelper::mark_failed($registro, $mensaje);
                },
            ),

            /* Extensión import (grupo 116): descomentar y ajustar cuando exista el flujo de import.
            'import' => array(
                'label'   => 'import_history',
                'mensaje' => '...',
                'buscar'  => function ($limite) { ... },
                'marcar'  => function ($registro, $mensaje) { ... },
            ),
            */
        );
    }
}
