<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Services\AsistenteIa\ConsolidacionDeMemoriaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ConsolidarMemoriaDelAgente extends Command
{
    protected $signature = 'agente:consolidar-memoria {--fecha= : Día argentino a repasar (AAAA-MM-DD)}';
    protected $description = 'Consolida enseñanzas del negocio y separa mejoras pendientes del agente.';

    public function handle(ConsolidacionDeMemoriaService $servicio)
    {
        $dueno = AsistenteCanalHelper::dueno();
        if (!$dueno || !AgentesIaHelper::tiene($dueno)) {
            $this->line('Sin Agentes de IA activo: no hay nada para consolidar.');
            return 0;
        }
        if (!Schema::hasTable('agente_consolidaciones') || !Schema::hasTable('agente_consolidacion_estados')) {
            $this->error('Falta la migración de consolidación de memoria.');
            return 1;
        }
        try {
            $resultado = $servicio->repasar($dueno, $this->option('fecha'));
            $this->line('Repaso: ' . $resultado['lotes'] . ' lotes; ' . $resultado['errores'] . ' errores; '
                . ($resultado['memorias_pendientes'] ?? 0) . ' enseñanzas pendientes de incorporar; '
                . ($resultado['repasos_pendientes'] ?? 0) . ' repasos pendientes.');
            return $resultado['errores'] ? 1 : 0;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
