<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\DespertadorDeTareasIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TareasDelAsistenteIaHelper;
use Illuminate\Console\Command;

/**
 * Despierta las tareas del asistente personal que vencieron (misión agente-tareas-programadas, 21/11/2026).
 *
 * El Kernel lo agenda cada minuto (sólo con Agentes de IA y sólo si hay algo vencido o por cerrar). Es un barrido
 * CORTO: cierra los turnos que despertó antes, reclama las tareas vencidas, arma sus turnos y despacha el job; NUNCA
 * corre el turno del modelo en este proceso ni queda esperando (en hosting compartido un proceso largo colgado del
 * schedule retiene los candados del cron). Toda la lógica vive en DespertadorDeTareasIaHelper.
 *
 * Sólo trabaja para el dueño de esta instancia (config app.USER_ID): hay bases compartidas entre comercios.
 */
class DespertarTareasDelAsistente extends Command
{
    protected $signature = 'asistente:despertar-tareas {--owner= : Id del dueño (sólo corre si es el de esta instancia)}';

    protected $description = 'Despierta las tareas programadas del asistente que vencieron y cierra los turnos que ya terminaron.';

    /**
     * @return int
     */
    public function handle()
    {
        $dueno = AsistenteCanalHelper::dueno();

        if (!$dueno || !AgentesIaHelper::tiene($dueno)) {

            $this->line('Sin Agentes de IA activo: no hay tareas del asistente para despertar.');

            return 0;
        }

        if ($this->option('owner') !== null && (int) $this->option('owner') !== (int) $dueno->id) {

            $this->line('Ese dueño no es el de esta instancia: no se hace nada.');

            return 0;
        }

        if (!TareasDelAsistenteIaHelper::hay_tablas()) {

            $this->line('Faltan las tablas de las tareas del asistente (migraciones pendientes): no se hace nada.');

            return 0;
        }

        $cerradas = DespertadorDeTareasIaHelper::cerrar($dueno);
        $despertadas = DespertadorDeTareasIaHelper::despertar($dueno);

        $this->line('Tareas del asistente: ' . $despertadas . ' despertadas, ' . $cerradas . ' turnos cerrados.');

        return 0;
    }
}
