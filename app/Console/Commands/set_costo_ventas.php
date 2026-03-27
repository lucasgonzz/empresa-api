<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Console\Command;
use Carbon\Carbon;

class set_costo_ventas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * user_id: opcional (se usa si no existe config('app.USER_ID'))
     */
    protected $signature = 'set_costo_ventas {from_sale_id?} {user_id?} {--solo_ventas_de_hoy}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setea costo y ganancia en ventas y pivots de articulos.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Ejecutandose...');

        // 1) Resolver user_id: config > argumento > abortar
        $configured_user_id = config('app.USER_ID');
        $argument_user_id = $this->argument('user_id');

        $user_id = null;

        if (!is_null($configured_user_id) && $configured_user_id !== '') {
            $user_id = (int) $configured_user_id;
            $this->info('Usando user_id desde config(app.USER_ID): ' . $user_id);
        } else if (!is_null($argument_user_id) && $argument_user_id !== '') {
            $user_id = (int) $argument_user_id;
            $this->info('Usando user_id desde parametro: ' . $user_id);
        } else {
            $this->error('No se puede continuar: no esta definido config(app.USER_ID) y no pasaste el parametro {user_id}.');
            $this->line('Ejemplo: php artisan set_costo_ventas 123');
            return 1;
        }

        // Validar que exista el user
        $user = User::find($user_id);

        if (is_null($user)) {
            $this->error('No se puede continuar: no existe el usuario con id ' . $user_id);
            return 1;
        }

        // 2) Query de ventas SOLO para ese user
        $sales_query = Sale::where('user_id', $user_id)
            ->orderBy('id', 'ASC');

        if ($this->option('solo_ventas_de_hoy')) {
            $sales_query->where('created_at', '>=', Carbon::today()->startOfDay());
        }

        if ($this->argument('from_sale_id')) {
            $sales_query->where('id', '>=', $this->argument('from_sale_id'));
        }

        $processed_sales = 0;

        foreach ($sales_query->cursor() as $sale) {

            $processed_sales++;

            // Importante: reiniciar una vez por venta
            $total_cost = 0;

            foreach ($sale->articles as $article) {

                $cost = $article->pivot->cost;
                $price = $article->pivot->price;

                if (is_null($cost)) {

                    if ($article->costo_real) {
                        
                        $cost = (float) $article->costo_real;
                    }
                }

                if (!$sale->valor_dolar) {
                    $sale->valor_dolar = $user->dollar;
                }

                if ($sale->valor_dolar) {

                    if (
                        $sale->moneda_id == 1
                        && $user->cotizar_precios_en_dolares == 0
                    ) {

                        // Pesos
                        if ($article->cost_in_dollars == 1) {
                            $cost *= (float) $sale->valor_dolar;
                        }

                    } else if ($sale->moneda_id == 2) {

                        if (
                            $article->cost_in_dollars == 0
                            || is_null($article->cost_in_dollars)
                        ) {
                            $cost /= (float) $sale->valor_dolar;
                        }
                    }
                }

                $amount = $article->pivot->amount;

                $cost *= $amount;
                $price *= $amount;

                $ganancia = $price - $cost;

                $sale->articles()->updateExistingPivot($article->id, [
                    'cost' => $cost,
                    'ganancia' => $ganancia,
                ]);

                $total_cost += $cost;
            }

            if ($total_cost > 0) {
                $sale->total_cost = $total_cost;
                $sale->timestamps = false;
                $sale->save();
            }


            if ($processed_sales % 100 === 0) {
                $this->info(
                    'Procesadas ' . $processed_sales . ' ventas. Memoria MB: ' .
                    round(memory_get_usage(true) / 1024 / 1024, 2)
                );
                $this->comment('Ultimo id procesado: '.$sale->id);
            }

            // Liberación de memoria
            unset($sale);
            gc_collect_cycles();
        }

        $this->info('Termino. Ventas procesadas: ' . $processed_sales);
        return 0;
    }
}