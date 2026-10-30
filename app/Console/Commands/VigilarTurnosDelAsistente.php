<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\asistente_ia\VigiaDeTurnosIaHelper;
use Illuminate\Console\Command;

/**
 * El vigía de los turnos colgados del asistente (misión agente-mejoras-uso-real, ronda de arreglos,
 * 30/10/2026): cierra los mensajes del asistente que siguen en `pendiente` después de 10 minutos y, si
 * eran de WhatsApp, le deja al dueño un aviso citando su pedido. La lógica vive en
 * VigiaDeTurnosIaHelper; acá solo se la llama. Corre cada 5 minutos (app/Console/Kernel.php).
 */
class VigilarTurnosDelAsistente extends Command
{
    /**
     * @var string
     */
    protected $signature = 'asistente:vigilar-turnos';

    /**
     * @var string
     */
    protected $description = 'Cierra los turnos del asistente que quedaron colgados en pendiente y avisa al dueño por WhatsApp.';

    /**
     * Corre el vigía y dice cuántos turnos cerró.
     *
     * @return int
     */
    public function handle()
    {
        $cerrados = VigiaDeTurnosIaHelper::cerrar_colgados();

        $this->info('Turnos colgados cerrados: ' . $cerrados);

        return 0;
    }
}
