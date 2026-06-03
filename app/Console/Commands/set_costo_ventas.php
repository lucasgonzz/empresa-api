<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Setea costo y ganancia en ventas y en el pivot article_sale.
 */
class set_costo_ventas extends Command
{
    /**
     * user_id: opcional (se usa si no existe config('app.USER_ID'))
     * from_sale_id / sale_id: reanudar desde un id de venta (>=).
     *
     * @var string
     */
    protected $signature = 'set_costo_ventas {user_id?} {from_sale_id?} {sale_id?} {--solo_ventas_de_hoy}';

    /**
     * @var string
     */
    protected $description = 'Setea costo y ganancia en ventas y pivots de articulos.';

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Procesa ventas en chunks con eager load y UPDATE directo al pivot.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Ejecutandose...');

        $user_id = $this->resolve_user_id();
        if ($user_id === null) {
            return 1;
        }

        $user = User::find($user_id);
        if ($user === null) {
            $this->error('No existe el usuario con id ' . $user_id);

            return 1;
        }

        $from_sale_id = $this->resolve_from_sale_id();

        $sales_query = Sale::query()
            ->where('user_id', $user_id)
            ->orderBy('id', 'ASC');

        if ($this->option('solo_ventas_de_hoy')) {
            $sales_query->where('created_at', '>=', Carbon::today()->startOfDay());
        }

        if ($from_sale_id !== null) {
            $sales_query->where('id', '>=', $from_sale_id);
            $this->comment('Desde venta id >= ' . $from_sale_id);
        }

        $processed_sales = 0;
        $user_dollar = (float) $user->dollar;
        $cotizar_precios_en_dolares = (int) $user->cotizar_precios_en_dolares;

        // Chunk + eager load: evita N+1 y reduce memoria frente a get() de todas las ventas.
        $sales_query->with([
            'articles' => function ($relation_query) {
                $relation_query->select(
                    'articles.id',
                    'articles.costo_real',
                    'articles.cost_in_dollars'
                );
            },
        ])->chunkById(100, function ($sales_chunk) use (
            $user_dollar,
            $cotizar_precios_en_dolares,
            &$processed_sales
        ) {
            foreach ($sales_chunk as $sale) {
                $processed_sales++;
                $this->process_sale_costs(
                    $sale,
                    $user_dollar,
                    $cotizar_precios_en_dolares
                );

                if ($processed_sales % 100 === 0) {
                    $this->info(
                        'Procesadas ' . $processed_sales . ' ventas. Memoria MB: ' .
                        round(memory_get_usage(true) / 1024 / 1024, 2)
                    );
                    $this->comment('Ultimo id procesado: ' . $sale->id);
                }
            }
        });

        $this->info('Termino. Ventas procesadas: ' . $processed_sales);

        return 0;
    }

    /**
     * Calcula cost/ganancia por articulo y persiste pivot + total_cost de la venta.
     *
     * @param  Sale  $sale
     * @param  float  $user_dollar
     * @param  int  $cotizar_precios_en_dolares
     * @return void
     */
    private function process_sale_costs(
        Sale $sale,
        $user_dollar,
        $cotizar_precios_en_dolares
    ) {
        $total_cost = 0;
        $valor_dolar = $sale->valor_dolar ? (float) $sale->valor_dolar : $user_dollar;

        foreach ($sale->articles as $article) {
            $pivot = $article->pivot;
            $cost = $pivot->cost;
            $price = (float) $pivot->price;

            if ($cost === null && $article->costo_real) {
                $cost = (float) $article->costo_real;
            } else {
                $cost = (float) $cost;
            }

            if ($valor_dolar) {
                if (
                    (int) $sale->moneda_id === 1
                    && $cotizar_precios_en_dolares === 0
                ) {
                    if ((int) $article->cost_in_dollars === 1) {
                        $cost *= $valor_dolar;
                    }
                } elseif ((int) $sale->moneda_id === 2) {
                    if ((int) $article->cost_in_dollars === 0 || $article->cost_in_dollars === null) {
                        $cost /= $valor_dolar;
                    }
                }
            }

            $amount = (float) $pivot->amount;
            $cost *= $amount;
            $price *= $amount;
            $ganancia = $price - $cost;

            // UPDATE directo al pivot (mas rapido que updateExistingPivot por articulo).
            DB::table('article_sale')
                ->where('sale_id', $sale->id)
                ->where('article_id', $article->id)
                ->update([
                    'cost' => $cost,
                    'ganancia' => $ganancia,
                ]);

            $total_cost += $cost;
        }

        if ($total_cost > 0) {
            DB::table('sales')
                ->where('id', $sale->id)
                ->update(['total_cost' => $total_cost]);
        }
    }

    /**
     * Resuelve user_id desde argumento o config.
     *
     * @return int|null
     */
    private function resolve_user_id()
    {
        $argument_user_id = $this->argument('user_id');
        if ($argument_user_id !== null && $argument_user_id !== '') {
            $this->info('Usando user_id desde parametro: ' . $argument_user_id);

            return (int) $argument_user_id;
        }

        $configured_user_id = config('app.USER_ID');
        if ($configured_user_id !== null && $configured_user_id !== '') {
            $this->info('Usando user_id desde config(app.USER_ID): ' . $configured_user_id);

            return (int) $configured_user_id;
        }

        $this->error('No se puede continuar: falta config(app.USER_ID) o parametro {user_id}.');
        $this->line('Ejemplo: php artisan set_costo_ventas 800');

        return null;
    }

    /**
     * Acepta from_sale_id o sale_id (plantillas de version usan sale_id?).
     *
     * @return int|null
     */
    private function resolve_from_sale_id()
    {
        $from_sale_id = $this->argument('from_sale_id');
        if ($from_sale_id !== null && $from_sale_id !== '') {
            return (int) $from_sale_id;
        }

        $sale_id = $this->argument('sale_id');
        if ($sale_id !== null && $sale_id !== '') {
            return (int) $sale_id;
        }

        return null;
    }
}
