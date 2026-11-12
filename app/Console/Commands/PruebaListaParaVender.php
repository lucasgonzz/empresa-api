<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\PrimeraVentaDeLaPruebaHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use Illuminate\Console\Command;

/**
 * Deja lista para la primera venta una instancia de la prueba gratuita que YA estaba armada (misión
 * prueba-primera-venta, 12/11/2026): sucursal del dueño fijada y caja de efectivo abierta.
 *
 * Las pruebas nuevas nacen así solas (lo hace el demo setup); esto es para las instancias del pool armadas
 * antes de esta versión. Idempotente. Fuera de una prueba activa no hace nada.
 */
class PruebaListaParaVender extends Command
{
    protected $signature = 'prueba:lista-para-vender';

    protected $description = 'Prueba gratuita: fija la sucursal del dueño y abre su caja de efectivo para la primera venta.';

    /**
     * @return int
     */
    public function handle()
    {
        PruebaHelper::olvidar_cache();

        $dueno = PruebaHelper::dueno();

        if (is_null($dueno) || !PruebaHelper::activa($dueno->id)) {
            $this->info('No es una instancia de prueba activa: no se toca nada.');

            return 0;
        }

        $resultado = PrimeraVentaDeLaPruebaHelper::dejar_lista_para_vender($dueno->id);

        $this->info('Sucursal del dueño: ' . ($resultado['address_id'] ?: 'ninguna')
            . ' · caja abierta para vender: ' . ($resultado['caja_abierta_id'] ?: 'ninguna'));

        return 0;
    }
}
