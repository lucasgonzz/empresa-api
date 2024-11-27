<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncDeletedSales extends Command
{
    protected $signature = 'sync:deleted-sales';
    protected $description = 'Sincroniza las ventas eliminadas de la base de datos de respaldo con la base de datos original.';

    public function handle()
    {
        $this->info('Conectando a la base de datos de respaldo...');

        // Conectar a la base de datos de respaldo y obtener las ventas eliminadas
        $deletedSales = DB::connection('backup')
            ->table('sales')
            ->where('user_id', 228)
            ->whereNotNull('deleted_at')
            ->get(['id', 'deleted_at']);

        $this->info('Ventas eliminadas obtenidas: ' . $deletedSales->count());

        if ($deletedSales->isEmpty()) {
            $this->info('No se encontraron ventas eliminadas en la base de datos de respaldo.');
            return;
        }

        // return;

        // Actualizar la base de datos original con los valores de deleted_at
        $this->info('Actualizando la base de datos original...');
        foreach ($deletedSales as $sale) {
            DB::table('sales')
                ->where('id', $sale->id)
                ->update(['deleted_at' => $sale->deleted_at]);

            $this->info("Venta ID {$sale->id} actualizada con deleted_at: {$sale->deleted_at}");
        }

        $this->info('Sincronización completada.');
    }
}
