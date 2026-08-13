<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\DemoEventosPushHelper;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use Illuminate\Console\Command;

/**
 * Barrido del minuto de los eventos de la demo que quedaron sin sincronizar (mision 50).
 *
 * Es la red de abajo del push inmediato: si el canal con el admin estaba caido cuando
 * ocurrio el evento, o el request murio antes de que corriera el terminating, la fila
 * sigue en demo_eventos con sincronizado_at null y este comando la vuelve a intentar.
 *
 * Corre en el schedule de TODAS las instancias, incluidas las de los clientes reales.
 * Ahi el primer chequeo lo hace salir sin tocar nada mas.
 */
class DemoFlushEventos extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:flush-eventos';

    /**
     * @var string
     */
    protected $description = 'Empuja al admin los eventos de la demo que quedaron sin sincronizar';

    /**
     * @return int
     */
    public function handle()
    {
        if (!DemoTrackingConfigHelper::hay_canal()) {
            /**
             * Sin canal configurado esta instancia no es una demo. No se usa info() para
             * no escribir una linea por minuto en el log de los ~40 clientes reales.
             */
            return 0;
        }

        $resumen = DemoEventosPushHelper::flush();

        $this->info(
            'demo:flush-eventos | enviados: ' . $resumen['enviados']
            . ' | sincronizados: ' . $resumen['sincronizados']
            . ' | fallados: ' . $resumen['fallados']
            . ' | varados: ' . $resumen['varados']
        );

        return 0;
    }
}
