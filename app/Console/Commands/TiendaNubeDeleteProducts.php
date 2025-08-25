<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TiendaNubeDeleteProductsService;

class TiendaNubeDeleteProducts extends Command
{
    protected $signature = 'tiendanube:delete-products {--y|yes : Ejecutar sin pedir confirmación}';
    protected $description = 'Elimina todos los productos de Tienda Nube del store configurado';

    public function handle(TiendaNubeDeleteProductsService $service): int
    {
        if (!$this->option('yes')) {
            if (!$this->confirm('Esto eliminará TODOS los productos de Tienda Nube. ¿Continuar?')) {
                $this->warn('Operación cancelada.');
                return self::SUCCESS;
            }
        }

        $this->info('Listando y eliminando productos...');

        $resultado = $service->delete_all_products(function ($product_id, $ok) {
            $this->line(($ok ? '[OK] ' : '[ERR] ') . "ID {$product_id}");
        });

        $this->newLine();
        $this->table(
            ['Eliminados', 'Fallidos', 'Errores'],
            [[
                $resultado['eliminados'],
                $resultado['fallidos'],
                implode(' | ', $resultado['errores']) ?: '-',
            ]]
        );

        return self::SUCCESS;
    }
}
