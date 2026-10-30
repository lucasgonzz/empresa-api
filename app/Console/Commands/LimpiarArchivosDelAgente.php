<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\asistente_ia\ArchivosDelAgenteIaHelper;
use Illuminate\Console\Command;

/**
 * Borra los archivos vencidos del agente personal (misión agente-mejoras-uso-real, 29/10/2026, §4
 * E3.1 del plan): los Excel de exportar_excel cuyo link ya no vale (7 días). Borra el binario del
 * disco privado y la fila de `ai_archivos`.
 *
 * Corre todos los días desde el scheduler (app/Console/Kernel.php), solo si la tabla existe. Sin la
 * tabla (la ventana de un upgrade) no hace nada y lo dice. Toda la lógica vive en
 * ArchivosDelAgenteIaHelper::limpiar_vencidos(), que también usan los tests.
 */
class LimpiarArchivosDelAgente extends Command
{
    /**
     * @var string
     */
    protected $signature = 'asistente:limpiar-archivos';

    /**
     * @var string
     */
    protected $description = 'Borra los archivos del agente personal (Excel) cuyo link ya venció';

    /**
     * Ejecuta la limpieza y deja una línea con cuántos borró.
     *
     * @return int
     */
    public function handle()
    {
        $resultado = ArchivosDelAgenteIaHelper::limpiar_vencidos();

        $this->info('asistente:limpiar-archivos: ' . $resultado['archivos'] . ' archivo(s) vencido(s) borrado(s)'
            . ($resultado['fallidos'] ? ', ' . $resultado['fallidos'] . ' no se pudieron borrar (ver el log).' : '.'));

        return 0;
    }
}
