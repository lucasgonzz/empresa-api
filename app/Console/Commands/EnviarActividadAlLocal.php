<?php

namespace App\Console\Commands;

use App\Services\LocalActividad;
use Illuminate\Console\Command;

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
            $this->warn('Los eventos de El local quedaron guardados para reintentar en la próxima corrida.');
            return 1;
        }
    }
}
