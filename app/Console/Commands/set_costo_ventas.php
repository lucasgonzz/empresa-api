<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\sale\CostoDeLineaDeVentaHelper;
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
 * El histórico roto NO lo repara este comando: eso es `sale:sanear-costo-de-linea`. Y el orden
 * entre los dos NO es una recomendacion: si este corre primero, borra la firma que hace reparable
 * al historico. Por eso hay una guarda que se niega a correr, con `--force` para saltearla — ver
 * `paso_la_guarda_de_orden()`.
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
    protected $signature = 'set_costo_ventas {user_id?} {from_sale_id?} {sale_id?} {--solo_ventas_de_hoy}
                            {--force : Corre igual aunque el cliente tenga historico roto sin sanear. Destruye la firma que lo hace reparable.}';

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

        if (!$this->paso_la_guarda_de_orden($user_id, $from_sale_id)) {
            return 1;
        }

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
     * 🔴 LA GUARDA DE ORDEN: este comando NO PUEDE correr antes que `sale:sanear-costo-de-linea`.
     *
     * Por que existe, que es lo unico que la justifica. Una linea rota por la causa B quedo con el
     * costo TOTAL en la columna unitaria, y lo unico que la vuelve reparable es su FIRMA:
     * `ganancia = price × amount − cost`. Este comando, ya arreglado, recalcula
     * `ganancia = (price − cost) × amount` usando el `cost` todavia roto. Con eso:
     *
     *   1. la linea pasa a cumplir la firma SANA y el saneo no la detecta NUNCA MAS. El historico
     *      de ese cliente queda irreparable;
     *   2. `sales.total_cost` pasa de estar inflado por `amount` a estarlo por `amount²`.
     *
     * Y no es un riesgo teorico: este comando esta publicado en el admin como comando de la version
     * 1.0.1 con `is_required=1` y `run_manually=0`, o sea que corre solo en el upgrade de cualquier
     * cliente que venga de esa version. Sin esta guarda, el upgrade destruye el historico en
     * silencio y nadie se entera hasta que alguien mira la ganancia acumulada.
     *
     * ─── La paridad con el saneo, que es lo que hace usable a esta guarda ──────────────────────
     *
     * 🔴 **La guarda cuenta EXACTAMENTE las lineas que el saneo corregiria, ni una mas.** No es una
     * aspiracion escrita en un comentario: las dos llaman a `CostoDeLineaDeVentaHelper::analizar()`,
     * que es el unico lugar donde vive el criterio. Es lo unico que vuelve cierta la secuencia que
     * este comando imprime mas abajo (dry-run → revisar → `--aplicar` → volver a correr): si frenara
     * por una linea que el saneo despues se niega a tocar, esa secuencia NO destraba nada y al
     * cliente le quedan dos salidas, las dos malas — `--force`, que destruye el historico que
     * todavia era reparable, o quedarse trabado para siempre.
     *
     * Hasta el 17/9/2026 el criterio estaba escrito dos veces (en SQL aca, en PHP en el saneo) y el
     * PHPDoc afirmaba que eran el mismo. Era falso: esto era una condicion NECESARIA y el saneo
     * aplicaba cuatro descartes mas. Cada uno de los cuatro trababa a un cliente real:
     *
     * | Lo que contaba la guarda | Lo que hacia el saneo |
     * |---|---|
     * | `price` no null, sin exigir `> 0` | una linea de regalo (`price = 0`) se descartaba |
     * | no joineaba `articles` | INNER JOIN: una linea con el articulo borrado en duro ni se miraba |
     * | no verificaba que existiera `k` | sin `k` coherente se descartaba (cliente que corrio el comando 5+ veces) |
     * | no miraba `article_sale.unidades_individuales` | si difiere de la del articulo, se descartaba |
     *
     * ⚠️ La guarda evalua la corrida POR DEFECTO del saneo (las dos causas y el `k_max` por defecto),
     * que es exactamente el comando que le imprime al operador. Si alguien corriera el saneo con
     * `--causa=b` o con un `k_max` mas chico, podria quedar algo sin corregir que la guarda sigue
     * contando: la salida en ese caso es correr el saneo como la guarda lo indica.
     *
     * Y cuenta solo lo corregible por la causa **B**, no cualquier correccion: lo que este comando
     * destruye es la firma de la causa B. Una linea de causa A se detecta por `cost > price × 2` mas
     * `unidades_individuales`, que el recalculo de la ganancia no toca — sigue siendo reparable
     * despues, asi que no hay por que trabar el upgrade por ella.
     *
     * @param  int  $user_id
     * @param  int|null  $from_sale_id
     * @return bool
     */
    private function paso_la_guarda_de_orden($user_id, $from_sale_id)
    {
        $rotas = $this->contar_lineas_que_el_saneo_corregiria($user_id, $from_sale_id);

        if ($rotas === 0) {
            return true;
        }

        if ($this->option('force')) {
            $this->warn('ATENCION: hay ' . $rotas . ' lineas de venta con el costo roto sin sanear y se corrio con --force.');
            $this->warn('Esas lineas van a quedar IRREPARABLES: el recalculo de la ganancia borra la unica marca que');
            $this->warn('permite detectarlas. Si esto no fue a proposito, cortalo ahora (Ctrl+C).');

            return true;
        }

        $this->error('==============================================================================');
        $this->error(' NO SE EJECUTO NADA. Este cliente tiene historico de ventas roto sin reparar.');
        $this->error('==============================================================================');
        $this->line('');
        $this->line('  Lineas de venta afectadas (user_id ' . $user_id . '): ' . $rotas);
        $this->line('');
        $this->line('  Que pasa: esas lineas tienen el costo TOTAL guardado en `article_sale.cost`, que por');
        $this->line('  convencion es el costo UNITARIO. Las dejo asi una version vieja de este mismo comando.');
        $this->line('  Se pueden reparar porque su ganancia guardada tiene una marca que las delata.');
        $this->line('');
        $this->line('  Por que se freno: si este comando corre primero, recalcula la ganancia con el costo');
        $this->line('  todavia roto, la marca DESAPARECE y esas ventas quedan mal para siempre, sin forma de');
        $this->line('  encontrarlas. Ademas el costo total de cada venta se infla otra vez.');
        $this->line('');
        $this->line('  Esas ' . $rotas . ' son exactamente las que el saneo corrige, asi que esta secuencia destraba:');
        $this->line('');
        $this->line('    1) php artisan sale:sanear-costo-de-linea --user_id=' . $user_id . '            (dry-run, no escribe)');
        $this->line('    2) revisar el JSON que deja en storage/app/saneo-costo-de-linea/ y BAJARLO');
        $this->line('    3) php artisan sale:sanear-costo-de-linea --user_id=' . $user_id . ' --aplicar');
        $this->line('    4) recien ahi, volver a correr este comando');
        $this->line('');
        $this->line('  Correr el saneo con --causa o con --k_max acotados puede dejar algo sin corregir: para');
        $this->line('  destrabar, corrrelo tal cual dice el paso 3.');
        $this->line('');
        $this->line('  Si sabes lo que estas haciendo y aceptas perder ese historico: --force');
        $this->line('');

        return false;
    }

    /**
     * Cuenta las lineas del cliente que `sale:sanear-costo-de-linea` corregiria por la causa B.
     *
     * El SQL de `acotar_a_candidatas_de_causa_b()` es SOLO un prefiltro —todas sus condiciones son
     * necesarias para que haya correccion por causa B—, y quien decide es `analizar()`, en PHP: el
     * mismo metodo, con los mismos parametros por defecto, que corre el saneo. De ahi sale la
     * paridad.
     *
     * Se recorre en chunks y no se acumula nada: de cada fila solo sobrevive el incremento del
     * contador.
     *
     * @param  int  $user_id
     * @param  int|null  $from_sale_id
     * @return int
     */
    private function contar_lineas_que_el_saneo_corregiria($user_id, $from_sale_id)
    {
        $query = CostoDeLineaDeVentaHelper::acotar_a_candidatas_de_causa_b(
            CostoDeLineaDeVentaHelper::query_de_lineas($user_id)
        );

        /*
         * Estos filtros son los de ESTA corrida, no del criterio: lo que la guarda cuida es el
         * historico que este comando va a tocar. Las ventas en deposito se filtran aca ademas de en
         * `analizar()` —que las descarta igual— para no traerlas a PHP al pedo.
         */
        $query->where('sales.to_check', 0)
            ->where('sales.checked', 0);

        if ($from_sale_id !== null) {
            $query->where('sales.id', '>=', $from_sale_id);
        }

        if ($this->option('solo_ventas_de_hoy')) {
            $query->where('sales.created_at', '>=', Carbon::today()->startOfDay());
        }

        $rotas = 0;

        $query->chunkById(1000, function ($filas) use (&$rotas) {
            foreach ($filas as $fila) {
                $analisis = CostoDeLineaDeVentaHelper::analizar(
                    $fila,
                    true,
                    true,
                    CostoDeLineaDeVentaHelper::K_MAX_POR_DEFECTO
                );

                if (CostoDeLineaDeVentaHelper::corrige_por_causa_b($analisis)) {
                    $rotas++;
                }
            }

            return true;
        }, 'article_sale.id', 'linea_id');

        return $rotas;
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
