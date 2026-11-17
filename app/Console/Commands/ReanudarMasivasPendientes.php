<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\masivas\EjecucionPorTandasHelper;
use Illuminate\Console\Command;

class ReanudarMasivasPendientes extends Command
{
    protected $signature = 'masivas:reanudar-pendientes';
    protected $description = 'Recupera despachos interrumpidos de masivas, con cadencia y cantidad acotadas.';

    public function handle()
    {
        $this->info('Continuaciones recuperadas: ' . EjecucionPorTandasHelper::reconciliar());
        return 0;
    }
}
