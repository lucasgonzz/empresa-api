<?php

namespace App\Jobs;

use App\Models\ImportacionDeSoporte;
use App\Services\Importacion\ImportacionDeSoporteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avanza una importación de clientes o proveedores desde soporte (misión importaciones-de-martin, 11/11/2026):
 * el análisis con IA y, después del código del dueño, la importación. Mismo molde que AvanzarImportacionDePrueba.
 */
class AvanzarImportacionDeSoporte implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 2;
    public $timeout = 900;

    /**
     * Menos que los 10 minutos sin señales con los que la consulta del admin la relanza
     * (ImportacionDeSoporteService::MINUTOS_PARA_REANUDAR): si la cola perdió el job, su candado de único ya venció
     * y el relanzamiento no se descarta en silencio. Dos ejecuciones a la vez igual no pueden pasar: el handle()
     * toma su propio candado por corrida.
     */
    public $uniqueFor = 540;

    private $importacion_id;

    public function __construct(int $importacion_id)
    {
        $this->importacion_id = $importacion_id;
    }

    public function uniqueId(): string
    {
        return 'soporte-' . $this->importacion_id;
    }

    public function handle(ImportacionDeSoporteService $servicio): void
    {
        Cache::lock('importacion-de-soporte:' . $this->importacion_id, $this->timeout + 60)->get(function () use ($servicio) {
            $corrida = ImportacionDeSoporte::find($this->importacion_id);
            if ($corrida !== null && in_array($corrida->estado, ImportacionDeSoporte::EN_CURSO, true)) {
                $servicio->avanzar($corrida);
            }
        });
    }

    public function failed(Throwable $error): void
    {
        $corrida = ImportacionDeSoporte::find($this->importacion_id);
        if ($corrida === null || !in_array($corrida->estado, ImportacionDeSoporte::EN_CURSO, true)) {
            return;
        }

        Log::warning('No se pudo avanzar la importación de soporte.', [
            'id' => $corrida->id, 'estado' => $corrida->estado, 'error' => $error->getMessage(),
        ]);

        app(ImportacionDeSoporteService::class)->fallar($corrida);
    }
}
