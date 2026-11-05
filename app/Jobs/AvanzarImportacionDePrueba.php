<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\ImportacionDePrueba;
use App\Services\Importacion\ImportacionDePruebaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AvanzarImportacionDePrueba implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 2;
    public $timeout = 900;
    public $uniqueFor = 960;

    private $importacion_id;

    public function __construct(int $importacion_id)
    {
        $this->importacion_id = $importacion_id;
    }

    public function uniqueId(): string
    {
        return (string) $this->importacion_id;
    }

    public function handle(ImportacionDePruebaService $servicio): void
    {
        Cache::lock('importacion-de-prueba:' . $this->importacion_id, $this->uniqueFor)->get(function () use ($servicio) {
            PruebaHelper::olvidar_cache();
            $run = ImportacionDePrueba::find($this->importacion_id);
            if ($run !== null && in_array($run->estado, ImportacionDePrueba::EN_CURSO, true)) {
                $servicio->avanzar($run);
            }
        });
    }

    public function failed(Throwable $error): void
    {
        $run = ImportacionDePrueba::find($this->importacion_id);
        if ($run === null || !in_array($run->estado, ImportacionDePrueba::EN_CURSO, true)) {
            return;
        }

        Log::warning('No se pudo avanzar la importación de la prueba.', [
            'id' => $run->id, 'estado' => $run->estado, 'error' => $error->getMessage(),
        ]);

        app(ImportacionDePruebaService::class)->fallar($run);
    }
}
