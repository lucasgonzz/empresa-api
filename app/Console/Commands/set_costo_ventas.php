<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Setea costo y ganancia en ventas y en el pivot article_sale.
 *
 * 🔴 `article_sale.cost` es el costo UNITARIO. No es una opinion: lo fijan tres lugares que
 * ya estan en produccion y que multiplican por la cantidad DESPUES de leerlo:
 *
 *   - SaleHelper::attachArticle()                      guarda 'cost' unitario y 'ganancia' TOTAL de la linea
 *   - SaleTotalesHelper::set_total_cost()              total += pivot->cost * pivot->amount
 *   - ContabilidadRepository::costo_mercaderia_vendida SUM(article_sale.cost * article_sale.amount)
 *
 * Hasta el 17/9/2026 este comando escribia el costo TOTAL de la linea en esa columna
 * (`$cost *= $amount` antes del update). Tres consecuencias en cascada:
 *
 *   1. el costo de la linea quedaba inflado por la cantidad;
 *   2. `set_total_cost()` y el CMV del Estado de Resultados lo volvian a multiplicar por
 *      la cantidad, o sea inflados por amount²;
 *   3. no era idempotente: cada corrida multiplicaba de nuevo (medido en golonorte, donde
 *      la ganancia acumulada de ventas quedo en −$2.327.527.825).
 *
 * Ahora persiste el costo unitario, la ganancia total de la linea —igual que
 * `attachArticle`— y `sales.total_cost` como Σ(costo_unitario × cantidad).
 *
 * Guarda de idempotencia, en dos mitades:
 *
 *   a) la cotizacion a dolar solo se aplica cuando el costo se toma de `articles.costo_real`,
 *      nunca cuando ya venia guardado en el pivot. Es la misma regla de
 *      `SaleHelper::getCost()`, que cuando el item trae pivot devuelve el costo guardado
 *      "ya cotizado": volver a cotizarlo era la otra fuente de corridas no repetibles;
 *   b) no se escribe la fila cuando el valor calculado ya es el que esta guardado, asi que
 *      la segunda corrida no toca una sola fila y lo informa.
 *
 * El histórico roto NO lo repara este comando: eso es `sale:sanear-costo-de-linea`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class set_costo_ventas extends Command
{
    /**
     * Tolerancia para comparar contra lo guardado. Las columnas son decimal(x,2), asi que
     * una diferencia por debajo de medio centavo es la misma fila.
     */
    const EPSILON = 0.005;

    /**
     * Lineas de pivot efectivamente escritas en la corrida.
     *
     * @var int
     */
    private $lineas_actualizadas = 0;

    /**
     * Lineas que ya tenian el valor correcto y no se tocaron (guarda de idempotencia).
     *
     * @var int
     */
    private $lineas_sin_cambios = 0;

    /**
     * Ventas a las que se les reescribio el total_cost.
     *
     * @var int
     */
    private $ventas_actualizadas = 0;

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
        $this->info('Lineas escritas: ' . $this->lineas_actualizadas
            . '. Lineas que ya estaban bien: ' . $this->lineas_sin_cambios . '.');
        $this->info('Ventas con total_cost reescrito: ' . $this->ventas_actualizadas . '.');

        if ($this->lineas_actualizadas === 0 && $this->ventas_actualizadas === 0) {
            $this->comment('No se escribio nada: el dato ya estaba en su valor final (corrida idempotente).');
        }

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
            $price = (float) $pivot->price;
            $amount = (float) $pivot->amount;

            /*
             * Un costo ya guardado en el pivot esta cotizado en la moneda de la venta desde el
             * momento en que se cargo (SaleHelper::getCost() lo devuelve tal cual cuando el item
             * trae pivot). Solo el que se toma de `articles.costo_real` viene crudo y hay que
             * cotizarlo. Cotizar siempre era lo que hacia que dos corridas seguidas sobre un
             * cliente en dolares dieran numeros distintos.
             */
            if (is_null($pivot->cost)) {
                $cost = (float) $article->costo_real;
                $cost = $this->cotizar_costo(
                    $cost,
                    $sale,
                    $article,
                    $valor_dolar,
                    $cotizar_precios_en_dolares
                );
            } else {
                $cost = (float) $pivot->cost;
            }

            /*
             * Costo UNITARIO en `cost` y ganancia TOTAL de la linea en `ganancia`: la misma
             * convencion que persiste SaleHelper::attachArticle().
             */
            $cost = round($cost, 2);
            $ganancia = round(($price - $cost) * $amount, 2);

            if ($this->hay_que_escribir_la_linea($pivot, $cost, $ganancia)) {
                // UPDATE directo al pivot (mas rapido que updateExistingPivot por articulo).
                DB::table('article_sale')
                    ->where('sale_id', $sale->id)
                    ->where('article_id', $article->id)
                    ->update([
                        'cost' => $cost,
                        'ganancia' => $ganancia,
                    ]);

                $this->lineas_actualizadas++;
            } else {
                $this->lineas_sin_cambios++;
            }

            $total_cost += $cost * $amount;
        }

        $total_cost = round($total_cost, 2);

        /*
         * El total_cost en 0 no se escribe: una venta sin ningun costo cargado dejaria en cero
         * un total que pudo haberse calculado antes por otro camino. Es el comportamiento que
         * este comando ya tenia.
         */
        if ($total_cost > 0 && abs((float) $sale->total_cost - $total_cost) >= self::EPSILON) {
            DB::table('sales')
                ->where('id', $sale->id)
                ->update(['total_cost' => $total_cost]);

            $this->ventas_actualizadas++;
        }
    }

    /**
     * Cotiza a la moneda de la venta un costo tomado de `articles.costo_real` (crudo).
     *
     * @param  float  $cost
     * @param  Sale  $sale
     * @param  \App\Models\Article  $article
     * @param  float  $valor_dolar
     * @param  int  $cotizar_precios_en_dolares
     * @return float
     */
    private function cotizar_costo(
        $cost,
        Sale $sale,
        $article,
        $valor_dolar,
        $cotizar_precios_en_dolares
    ) {
        if (!$valor_dolar) {
            return $cost;
        }

        if ((int) $sale->moneda_id === 1 && $cotizar_precios_en_dolares === 0) {
            if ((int) $article->cost_in_dollars === 1) {
                $cost *= $valor_dolar;
            }
        } elseif ((int) $sale->moneda_id === 2) {
            if ((int) $article->cost_in_dollars === 0 || $article->cost_in_dollars === null) {
                $cost /= $valor_dolar;
            }
        }

        return $cost;
    }

    /**
     * Guarda de idempotencia: la fila se escribe solo si alguno de los dos valores cambia.
     *
     * @param  object  $pivot
     * @param  float  $cost
     * @param  float  $ganancia
     * @return bool
     */
    private function hay_que_escribir_la_linea($pivot, $cost, $ganancia)
    {
        if (is_null($pivot->cost) || is_null($pivot->ganancia)) {
            return true;
        }

        return abs((float) $pivot->cost - $cost) >= self::EPSILON
            || abs((float) $pivot->ganancia - $ganancia) >= self::EPSILON;
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
