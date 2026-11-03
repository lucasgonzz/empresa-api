<?php

namespace App\Console\Commands;

use App\Services\LocalActividad;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnviarActividadAlLocal extends Command
{
    protected $signature = 'local:enviar-actividad';
    protected $description = 'Envía al admin los eventos pendientes de «El local» (quién entró, escaneos, respuestas del agente)';

    public function handle(LocalActividad $service)
    {
        try {
            $this->info('Eventos de El local enviados: ' . $service->enviar());
            return 0;
        } catch (\Throwable $e) {
            Log::warning('local:enviar-actividad: la corrida falló.', ['message' => $e->getMessage()]);
            $this->warn('Los eventos de El local quedaron guardados para reintentar en la próxima corrida.');
            return 1;
        }
    }
}
