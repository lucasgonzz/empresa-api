<?php

namespace App\Console\Commands;

use App\Services\VinculacionMaestra;
use Illuminate\Console\Command;

class AutorizarNavegadorMaestro extends Command
{
    protected $signature = 'asistente:autorizar-navegador {codigo} {--dueno= : ID del dueño de esta instancia}';

    protected $description = 'Aprueba un desafío de vinculación para el login maestro del dueño de esta instancia';

    public function handle(): int
    {
        $dueno = filter_var($this->option('dueno'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$dueno || !VinculacionMaestra::aprobar($this->argument('codigo'), $dueno)) {
            $this->error('No se aprobó la vinculación. Revisá el dueño, la configuración y la vigencia del código.');

            return 1;
        }

        $this->info('Vinculación aprobada. Confirmala desde el navegador que mostró el código.');

        return 0;
    }
}
