<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Carbon\Carbon;

class set_conceptos_stock_movements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_conceptos_stock_movements {user_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        set_time_limit(9999999);

        $this->user_id = $this->argument('user_id') ?? null;

        $stock_movements = StockMovement::where('user_id', $this->user_id)
                                        ->orderBy('created_at', 'ASC')
                                        ->whereNull('concepto_stock_movement_id')
                                        ->get();

        $movimientos_chequeados = 0;

        $this->info(count($stock_movements).' movimientos');

        foreach ($stock_movements as $stock_movement) {
            
            $movimientos_chequeados++;

            $concepto_id = $this->get_concepto_id($stock_movement);

            $stock_movement->concepto_stock_movement_id = $concepto_id;
            $stock_movement->timestamps = false;
            $stock_movement->save();
            
            if ($movimientos_chequeados % 1000 == 0) {
                $this->comment('Se chequearon '.$movimientos_chequeados);
            }
        }




        $this->info('Termino');
    }

    function get_concepto_id($stock_movement) {

        if (is_null($stock_movement->concepto)) {
            return;
        }


        $conceptos = [
            'Ingreso manual'                => 1,
            'Reseteo de Stock'              => 2,
            'Venta'                         => 3,
            'Act Venta'                     => 4,
            'Se elimino de la venta'        => 5,
            'Se elimino la venta'           => 6,
            'Compra a proveedor'            => 8,
            'Creacion de deposito'          => 10,
            'Actualizacion de deposito'     => 11,

            'Mov entre depositos'           => 12,
            'Mov manual entre depositos'    => 13,
            'Importacion Excel'             => 15, 

            'Eliminacion Compra a proveedor'    => 18,
        ];



        if (
            $stock_movement->concepto == 'Compra a proveedor'
            || $stock_movement->concepto == 'Resta de Stock'
        ) {
            return $conceptos['Ingreso manual'];
        }
        
        if ($stock_movement->concepto == 'Reseteo de stock') {
            return $conceptos['Reseteo de Stock'];
        }
        
        if (substr($stock_movement->concepto, 0, 5) == 'Venta') {
            return $conceptos['Venta'];
        }
        
        if (substr($stock_movement->concepto, 0, 4) == 'Act.') {
            return $conceptos['Act Venta'];
        }
        
        if (substr($stock_movement->concepto, 0, 22) == 'Se elimino de la venta') {
            return $conceptos['Se elimino de la venta'];
        }
        
        if (substr($stock_movement->concepto, 0, 20) == 'Eliminacion de venta') {
            return $conceptos['Se elimino la venta'];
        }
        
        if (substr($stock_movement->concepto, 0, 20) == 'Eliminacion venta') {
            return $conceptos['Se elimino la venta'];
        }

        if (substr($stock_movement->concepto, 0, 16) == 'Pedido Proveedor') {
            return $conceptos['Compra a proveedor'];
        }
        
        if ($stock_movement->concepto == 'Creacion de deposito') {
            return $conceptos['Creacion de deposito'];
        }
        
        if ($stock_movement->concepto == 'Act de depositos') {
            return $conceptos['Actualizacion de deposito'];
        }
        
        if (substr($stock_movement->concepto, 0, 13) == 'Mov. Deposito') {
            return $conceptos['Mov entre depositos'];
        }
        
        if ($stock_movement->concepto == 'Movimiento de depositos') {
            return $conceptos['Mov manual entre depositos'];
        }
        
        if ($stock_movement->concepto == 'Importacion Excel') {
            return $conceptos['Mov manual entre depositos'];
        }
        
        if (substr($stock_movement->concepto, 0, 23) == 'Eliminacion Pedido Prov') {
            return $conceptos['Eliminacion Compra a proveedor'];
        }

        if (
            !str_contains($stock_movement->concepto, 'Restauracion')
            && !str_contains($stock_movement->concepto, 'Eliminacion Nota C.')
        ) {

            $this->info('No se encontro concepto para stock_movement id: '.$stock_movement->id);
        }


    }
}
