<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\User;
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

        $user = User::find($sales[0]->user_id);

        foreach ($sales as $sale) {

            // if (
            //     $sale->total <= $sale->total_cost
            //     || (float)$sale->total_cost <= 0
            // ) {
                
                foreach ($sale->articles as $article) {
                    
                    $total_cost = 0;

                    $cost = $article->pivot->cost;

                    if ($article->costo_real) {

                        $cost = (float)$article->costo_real;

                    }

                    if (!$sale->valor_dolar) {
                        $sale->valor_dolar = $user->dollar;
                    }


                    if ($sale->valor_dolar) {
                        
                        if ($sale->moneda_id == 1) {

                            // Pesos
                            if ($article->cost_in_dollars == 1) {
                                $cost *= (float)$sale->valor_dolar;
                            }

                        } else if ($sale->moneda_id == 2) {

                            if (
                                $article->cost_in_dollars == 0
                                || is_null($article->cost_in_dollars)
                            ) {
                                $cost /= (float)$sale->valor_dolar;
                            }
                        } 
                    }


                    $price = $article->pivot->price;
                    $amount = $article->pivot->amount;

                    $cost *= $amount;
                    $price *= $amount;

                    $ganancia = $price - $cost;

                    $sale->articles()->updateExistingPivot($article->id, [
                        'cost'  => $cost,
                        'ganancia'  => $ganancia,
                    ]);


                    $total_cost += $cost;

                }

                $this->info('Total_cost: '.$total_cost);

                if ($total_cost > 0) {
                    
                    $sale->total_cost = $total_cost;
                    $sale->timestamps = false;
                    $sale->save();

                }
                $this->comment('Listo venta '.$sale->id);
            // }
        }
        $this->info('Termino');
        return 0;
    }
}
