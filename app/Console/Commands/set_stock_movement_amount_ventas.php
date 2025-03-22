<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class set_stock_movement_amount_ventas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_stock_movement_amount_ventas {user_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seteo la cantidad en negativo para los movimientos de stock que sean de una venta y esten en positivo';

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

        $stock_movements = StockMovement::where('user_id', $this->argument('user_id'))
                                    ->where('concepto_stock_movement_id', 3)
                                    ->orderBy('created_at', 'ASC')
                                    ->get();

        $movimientos_chequeados = 0;

        $this->info(count($stock_movements).' movimientos');

        foreach ($stock_movements as $stock_movement) {
            
            $movimientos_chequeados++;

            $amount = abs($stock_movement->amount);
            $stock_movement->amount = -$amount;
            $stock_movement->timestamps = false;
            $stock_movement->save();
            
            if ($movimientos_chequeados % 1000 == 0) {
                $this->comment('Se chequearon '.$movimientos_chequeados);
            }
        }
        
        $this->info('Termino. '.$movimientos_chequeados.' movimientos chequeados');
        
        return 0;
    }
}
