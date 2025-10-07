<?php

namespace App\Console\Commands;

use App\Models\Sale;
use Illuminate\Console\Command;

class set_costo_ventas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_costo_ventas';

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
        $sales = Sale::orderBy('id', 'ASC')
                            ->get();

        foreach ($sales as $sale) {

            if ($sale->total < $sale->total_cost) {
                
                foreach ($sale->articles as $article) {
                    
                    $total_cost = 0;

                    $cost = $article->pivot->cost;

                    if ($article->costo_real) {

                        $cost = (float)$article->costo_real;
                        $price = $article->pivot->price;
                        $amount = $article->pivot->amount;

                        $ganancia = $price - $cost;

                        $sale->articles()->updateExistingPivot($article->id, [
                            'cost'  => $cost,
                            'ganancia'  => $ganancia,
                        ]);

                    }

                    $total_cost += $cost;

                }

                if ($total_cost > 0) {
                    
                    $sale->total_cost = $total_cost;
                    $sale->timestamps = false;
                    $sale->save();

                }
                $this->comment('Listo venta '.$sale->id);
            }
        }
        $this->info('Termino');
        return 0;
    }
}
