<?php

namespace App\Console\Commands;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Console\Command;
use Carbon\Carbon;

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
                            // ->where('created_at', '>', Carbon::today()->subMonths(1)->startOfMonth())
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
                    $price = $article->pivot->price;

                    // if (
                    //     (
                    //         is_null($cost)
                    //         || $cost == 0
                    //         || (float)$cost > (float)$price
                    //     )
                    //     && $article->costo_real
                    // ) {
                    if (
                        $article->costo_real
                    ) {

                        $cost = (float)$article->costo_real;

                    }

                    if (!$sale->valor_dolar) {
                        $sale->valor_dolar = $user->dollar;
                    }

                    // $esta_mal = false;

                    // if ($cost >= $price) {
                    //     $this->info('ANTES de cotizar: Costo mal sale num '.$sale->num.' article: '.$article->name);
                    //     $this->info('Cost: '.Numbers::price($cost));
                    //     $this->info('Price: '.Numbers::price($price));
                    //     $esta_mal = true;
                    // }

                    if ($sale->valor_dolar) {
                        
                        if (
                            $sale->moneda_id == 1
                            && $user->cotizar_precios_en_dolares == 0
                        ) {

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

                    // if ($cost >= $price) {
                    //     $this->comment('DESPUES de cotizar: Costo mal sale num '.$sale->num.' article: '.$article->name);
                    //     $this->comment('Cost: '.Numbers::price($cost));
                    //     $this->comment('Price: '.Numbers::price($price));
                    //     $this->comment('');
                    //     $this->comment('');
                    // } else if ($esta_mal) {
                    //     $this->comment('AHORA ESTA EN');
                    //     $this->comment('Cost: '.Numbers::price($cost));
                    //     $this->comment('Price: '.Numbers::price($price));
                    //     $this->comment('');
                    //     $this->comment('');
                    // }


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

                // $this->info('Total_cost: '.$total_cost);

                if ($total_cost > 0) {
                    
                    $sale->total_cost = $total_cost;
                    $sale->timestamps = false;
                    $sale->save();

                }
                // $this->info('Listo venta '.$sale->num);
            // }
        }
        $this->info('Termino');
        return 0;
    }
}
