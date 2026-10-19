<?php

namespace App\Console\Commands;

use App\Services\ProductFacts\SerperUsageService;
use Illuminate\Console\Command;

class SyncSerperUsage extends Command
{
    protected $signature = 'serper:sincronizar-consumo';
    protected $description = 'Envía al admin las consultas pendientes de Serper de forma idempotente';

    public function handle(SerperUsageService $service)
    {
        try {
            $count = $service->sync();
            $this->info('Consultas de Serper sincronizadas: ' . $count);
            return 0;
        } catch (\Throwable $e) {
            $this->warn('El consumo de Serper quedó guardado para reintentar en la próxima sincronización.');
            return 1;
        }
    }
}
