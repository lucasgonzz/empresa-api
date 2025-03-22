<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use Illuminate\Console\Command;

class set_stock_movements_user_id extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_stock_movements_user_id';

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

        $stock_movements = StockMovement::whereNull('user_id')
                                        ->orderBy('created_at', 'ASC')
                                        ->get();


        $movimientos_chequeados = 0;

        $this->info(count($stock_movements).' articulos');

        foreach ($stock_movements as $stock_movement) {
            
            $movimientos_chequeados++;

            $article = $stock_movement->article;

            if ($article) {
                $stock_movement->user_id = $stock_movement->article->user_id;
                $stock_movement->timestamps = false;
                $stock_movement->save();
            }

            if ($movimientos_chequeados % 1000 == 0) {
                $this->comment('Se chequearon '.$movimientos_chequeados.' movimientos');
                $this->comment('Ultimo id: '.$stock_movement->id);
            }
        }

        $this->info('Termino');
        return 0;
    }
}
