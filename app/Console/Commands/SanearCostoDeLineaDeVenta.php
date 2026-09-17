<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\sale\SaleTotalesHelper;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sanea el historico de `article_sale.cost` de UN cliente (mision saneo-ganancia-ventas,
 * 17/9/2026).
 *
 * ─── La convencion que se repara ──────────────────────────────────────────────────────────
 *
 * 🔴 `article_sale.cost` es el costo UNITARIO y `article_sale.ganancia` es el total de la
 * linea. Lo fijan tres lugares que ya estan en produccion y que multiplican por la cantidad
 * DESPUES de leer el costo: `SaleHelper::attachArticle()`, `SaleTotalesHelper::set_total_cost()`
 * y `ContabilidadRepository::costo_mercaderia_vendida()`.
 *
 * ─── Las dos causas que detecta ───────────────────────────────────────────────────────────
 *
 * CAUSA B — costo TOTAL escrito en la columna unitaria por `set_costo_ventas`.
 *
 *   Ese comando hacia `$cost *= $amount` antes de persistir (arreglado el 17/9/2026 en el
 *   mismo commit que nace este). Deja una FIRMA deterministica en la fila, y esa firma es lo
 *   que hace que este saneo no sea adivinanza:
 *
 *     - una linea escrita por `attachArticle`      cumple  ganancia = (price − cost) × amount
 *     - una linea escrita por `set_costo_ventas`   cumple  ganancia = price × amount − cost
 *
 *   Con `amount > 1` y `cost > 0` las dos firmas son mutuamente excluyentes (son iguales solo
 *   si amount = 1 o cost = 0), asi que la segunda identifica sin ambiguedad a la linea que el
 *   comando escribio — sin mirar si el costo "parece" alto. Esto es lo que deja afuera, por
 *   construccion, a las ventas legitimas a perdida: una venta a perdida cargada por el sistema
 *   tiene la firma sana, y este comando no la toca ni la mira.
 *
 *   Lo que la firma NO dice es CUANTAS veces corrio el comando sobre esa linea: la identidad
 *   ganancia = price × amount − cost se cumple igual despues de una corrida (cost = c × amount)
 *   que despues de tres (cost = c × amount³). Para eso, y solo para eso, hay una heuristica:
 *   se busca el MENOR k ≥ 1 tal que `cost / amount^k` deje el costo en un rango coherente con
 *   el precio de esa misma linea (ver "Los limites").
 *
 *   Cuando el articulo ademas tiene `unidades_individuales`, las dos causas pueden estar
 *   apiladas en la misma linea (el comando multiplico un costo que ya era el del bulto). Ahi el
 *   techo de coherencia de esta etapa sube por ese factor y lo que sobra lo divide la causa A:
 *   si no, el buscador de k se comeria la division de las unidades individuales y devolveria un
 *   costo partido de mas.
 *
 * CAUSA A — costo sin dividir por `unidades_individuales`.
 *
 *   Diagnosticada el 1/9/2026 en ferretotal (informe
 *   `20260901-costo-sin-dividir-unidades-individuales-ferretotal.md`): `vender_presupuestos.js`
 *   no mandaba `unidades_individuales`, y `SaleHelper::getCost()` solo divide si esa clave
 *   viene. Resultado: el costo del bulto entero guardado como costo unitario. La causa raiz ya
 *   esta arreglada; lo que falta es el historico.
 *
 *   El criterio de deteccion es el de la consulta que ya vive en el repo de conocimiento
 *   (`herramientas/sql/costo_sin_dividir_unidades_individuales.sql`): el articulo tiene
 *   `unidades_individuales > 1` y la perdida de la linea supera su propia facturacion, o sea
 *   `|ganancia| > price × amount` con `ganancia < 0`, que es exactamente `cost > 2 × price`.
 *   NO se compara contra `articles.costo_real` de hoy: el costo de un articulo cambia con el
 *   tiempo y esa comparacion da falsos positivos y falsos negativos.
 *
 * ─── Los limites, que son parte del criterio ──────────────────────────────────────────────
 *
 * 🔴 Lo que no cierra NO se corrige: se deja afuera y se lista en el respaldo. Es plata de un
 * negocio real y es preferible un numero sin corregir a un numero corregido mal.
 *
 *  1. El k de la causa B es una heuristica. Se elige el menor k coherente, o sea la correccion
 *     MINIMA: si el comando corrio tres veces y con dos divisiones el costo ya entra en el
 *     rango, la linea queda sub-corregida (todavia inflada) en vez de sobre-corregida. Esa
 *     asimetria es deliberada.
 *  2. El rango de coherencia es `0 < cost ≤ price × 2`, el mismo umbral de la consulta del
 *     repo de conocimiento. Una linea cuyo costo REAL fuera mas del doble de su precio (una
 *     venta a perdida muy profunda que ademas paso por el comando) quedaria sobre-dividida.
 *     Por eso "costo mayor que el precio" nunca alcanza por si solo para tocar una linea: hace
 *     falta ademas la firma de la causa B o las `unidades_individuales` de la causa A.
 *  3. Las lineas con `amount < 1` (kilos, metros) que paso el comando quedaron con el costo
 *     DIVIDIDO, no multiplicado. Ningun criterio basado en "el costo quedo alto" las ve, y
 *     dividir por amount^k las empeoraria. Se cuentan y se informan, no se tocan.
 *  4. Las lineas con `amount > 1` que paso el comando pero cuyo costo inflado igual quedo por
 *     debajo del umbral (por ejemplo costo unitario 100 y precio 1000 con amount 2) se
 *     corrigen: la firma dice que el costo se multiplico al menos una vez, no hace falta que
 *     "se note". Es el unico caso en que se corrige una linea que no parece rota.
 *  5. La causa A se repara dividiendo por `articles.unidades_individuales` de HOY. Si ese valor
 *     cambio despues de la venta, la division no reconstruye el costo de entonces. No hay
 *     registro historico de esa columna; la linea se corrige igual porque el costo del bulto es
 *     inequivocamente incorrecto, pero el respaldo permite revertirla.
 *  6. No se toca `article_budget`. El informe del 1/9 lo dejo explicitamente afuera.
 *
 * ─── Como se corre ────────────────────────────────────────────────────────────────────────
 *
 *     php artisan sale:sanear-costo-de-linea --user_id=500                # dry-run (defecto)
 *     php artisan sale:sanear-costo-de-linea --user_id=500 --aplicar      # escribe
 *     php artisan sale:sanear-costo-de-linea --user_id=500 --causa=b --desde=2025-07-01
 *
 * En las dos modalidades deja en `storage/app/saneo-costo-de-linea/` un JSON con todo lo que
 * va a tocar (valores antes y despues, causa y k de cada linea, y lo descartado con su motivo)
 * y un `.sql` de reversion con los valores exactos, tal cual estan en la base. Los dos archivos
 * se escriben ANTES de la primera fila escrita.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class SanearCostoDeLineaDeVenta extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sale:sanear-costo-de-linea
                            {--user_id= : Cliente a sanear. Si falta se usa config(app.USER_ID).}
                            {--aplicar : Escribe los cambios. Sin este flag el comando es dry-run.}
                            {--dry-run : Fuerza el dry-run aunque se haya pasado --aplicar.}
                            {--causa=todas : a, b o todas.}
                            {--sale_id= : Limita el saneo a una sola venta.}
                            {--desde= : Fecha AAAA-MM-DD, sobre sales.created_at.}
                            {--hasta= : Fecha AAAA-MM-DD, sobre sales.created_at.}
                            {--k_max=4 : Tope de divisiones por cantidad que se prueban en la causa B.}
                            {--limite= : Tope de lineas a corregir en esta corrida.}
                            {--max_descartes=5000 : Tope de descartes que se detallan en el JSON.}
                            {--salida= : Carpeta de los respaldos. Por defecto storage/app/saneo-costo-de-linea.}';

    /**
     * @var string
     */
    protected $description = 'Sanea el historico de article_sale.cost (costo unitario) de un cliente, con respaldo y SQL de reversion. Dry-run por defecto.';

    /**
     * Umbral de incoherencia entre costo y precio de una linea: por encima de este multiplo del
     * precio, la perdida de la linea supera su propia facturacion. Es el mismo criterio de
     * `herramientas/sql/costo_sin_dividir_unidades_individuales.sql`.
     */
    const FACTOR_COSTO_INCOHERENTE = 2.0;

    /**
     * Correcciones planificadas, una por linea.
     *
     * @var array
     */
    private $correcciones = [];

    /**
     * Lineas miradas y NO corregidas, con su motivo.
     *
     * @var array
     */
    private $descartes = [];

    /**
     * Conteo de descartes por motivo (cuenta todos, incluso los que no entran en el JSON).
     *
     * @var array
     */
    private $descartes_por_motivo = [];

    /**
     * Costo de articulos proyectado por venta: Σ(costo_unitario_final × cantidad).
     *
     * @var array
     */
    private $costo_de_articulos_por_venta = [];

    /**
     * Contadores generales de la corrida.
     *
     * @var array
     */
    private $contadores = [
        'lineas_miradas' => 0,
        'lineas_sanas' => 0,
        'lineas_sin_costo' => 0,
    ];

    /**
     * @return int
     */
    public function handle()
    {
        $user_id = $this->resolver_user_id();

        if (is_null($user_id)) {
            return 1;
        }

        $aplicar = (bool) $this->option('aplicar') && !$this->option('dry-run');

        $causa = strtolower((string) $this->option('causa'));

        if (!in_array($causa, ['a', 'b', 'todas'], true)) {
            $this->error('--causa acepta a, b o todas. Llego: ' . $causa);

            return 1;
        }

        $hacer_a = ($causa === 'a' || $causa === 'todas');
        $hacer_b = ($causa === 'b' || $causa === 'todas');

        $k_max = (int) $this->option('k_max');

        if ($k_max < 1) {
            $this->error('--k_max tiene que ser 1 o mas.');

            return 1;
        }

        $limite = $this->option('limite');
        $limite = ($limite === null || $limite === '') ? null : (int) $limite;

        $this->line('Cliente (user_id): ' . $user_id . '. Causas: ' . $causa . '. k_max: ' . $k_max . '.');
        $this->line($aplicar ? 'Modo: APLICAR (escribe).' : 'Modo: dry-run (no escribe una sola fila).');

        $this->recorrer_lineas($user_id, $hacer_a, $hacer_b, $k_max, $limite);

        $this->info('Lineas miradas: ' . $this->contadores['lineas_miradas']
            . '. A corregir: ' . count($this->correcciones) . '.');

        $ventas = $this->ventas_afectadas_con_sus_valores_previos();

        /*
         * El respaldo se escribe siempre, incluso en una corrida que no corrige nada: el detalle
         * de lo que se dejo afuera y por que es la mitad del valor de este comando.
         */
        $rutas = $this->escribir_respaldos($user_id, $ventas, $aplicar);

        if (count($this->correcciones) === 0) {
            $this->reportar_descartes();
            $this->info('No hay nada para corregir con los criterios de esta corrida.');
            $this->line('Respaldo JSON:     ' . $rutas['json']);

            return 0;
        }

        $this->reportar_muestra();
        $this->reportar_descartes();

        $this->line('Respaldo JSON:     ' . $rutas['json']);
        $this->line('SQL de reversion:  ' . $rutas['sql']);

        if (!$aplicar) {
            $this->warn('Dry-run: no se escribio ninguna fila. Volve a correrlo con --aplicar para persistir.');

            return 0;
        }

        $this->aplicar_correcciones(array_keys($ventas));

        $this->info('Listo. Lineas corregidas: ' . count($this->correcciones)
            . '. Ventas recalculadas: ' . count($ventas) . '.');
        $this->comment('Para revertir: mysql <base> < ' . $rutas['sql']);

        return 0;
    }

    /**
     * Resuelve el cliente sobre el que trabaja la corrida.
     *
     * @return int|null
     */
    private function resolver_user_id()
    {
        $opcion = $this->option('user_id');

        if ($opcion !== null && $opcion !== '') {
            return (int) $opcion;
        }

        $configurado = config('app.USER_ID');

        if ($configurado !== null && $configurado !== '') {
            $this->line('Usando config(app.USER_ID): ' . $configurado);

            return (int) $configurado;
        }

        $this->error('Falta --user_id (o config(app.USER_ID)). Este comando siempre trabaja sobre UN cliente.');

        return null;
    }

    /**
     * Recorre las lineas de venta del cliente clasificando cada una.
     *
     * @param  int  $user_id
     * @param  bool  $hacer_a
     * @param  bool  $hacer_b
     * @param  int  $k_max
     * @param  int|null  $limite
     * @return void
     */
    private function recorrer_lineas($user_id, $hacer_a, $hacer_b, $k_max, $limite)
    {
        $query = DB::table('article_sale')
            ->join('sales', 'sales.id', '=', 'article_sale.sale_id')
            ->join('articles', 'articles.id', '=', 'article_sale.article_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.user_id', $user_id)
            ->select(
                'article_sale.id as linea_id',
                'article_sale.sale_id',
                'article_sale.article_id',
                'article_sale.amount',
                'article_sale.price',
                'article_sale.cost',
                'article_sale.ganancia',
                'articles.unidades_individuales',
                'sales.num as venta_num',
                'sales.created_at as venta_fecha'
            );

        $sale_id = $this->option('sale_id');

        if ($sale_id !== null && $sale_id !== '') {
            $query->where('sales.id', (int) $sale_id);
        }

        $desde = $this->option('desde');

        if ($desde !== null && $desde !== '') {
            $query->where('sales.created_at', '>=', Carbon::parse($desde)->startOfDay());
        }

        $hasta = $this->option('hasta');

        if ($hasta !== null && $hasta !== '') {
            $query->where('sales.created_at', '<=', Carbon::parse($hasta)->endOfDay());
        }

        $corte = false;

        $query->chunkById(1000, function ($filas) use ($hacer_a, $hacer_b, $k_max, $limite, &$corte) {
                foreach ($filas as $fila) {
                    $this->contadores['lineas_miradas']++;

                    $analisis = $this->analizar_linea($fila, $hacer_a, $hacer_b, $k_max);

                    $this->acumular_costo_de_la_venta($fila, $analisis);

                    if ($analisis['accion'] === 'corregir') {
                        $this->correcciones[] = $this->armar_correccion($fila, $analisis);

                        if (!is_null($limite) && count($this->correcciones) >= $limite) {
                            $corte = true;

                            return false;
                        }

                        continue;
                    }

                    if ($analisis['accion'] === 'descartar') {
                        $this->anotar_descarte($fila, $analisis);
                    }
                }

                return true;
            }, 'article_sale.id', 'linea_id');

        if ($corte) {
            $this->warn('Se corto por --limite: hay mas lineas para corregir de las que entran en esta corrida.');

            /*
             * El recorrido quedo cortado a mitad de una venta, asi que la proyeccion del costo
             * de articulos por venta esta incompleta y seria un numero enganoso en el respaldo.
             * El valor definitivo lo escribe igual SaleTotalesHelper al aplicar.
             */
            $this->costo_de_articulos_por_venta = [];
        }
    }

    /**
     * Clasifica una linea y, si corresponde, calcula el costo unitario correcto.
     *
     * @param  object  $fila
     * @param  bool  $hacer_a
     * @param  bool  $hacer_b
     * @param  int  $k_max
     * @return array
     */
    private function analizar_linea($fila, $hacer_a, $hacer_b, $k_max)
    {
        $sana = ['accion' => 'ninguna', 'cost_final' => null, 'causas' => [], 'k' => null, 'motivo' => null];

        if (is_null($fila->cost)) {
            $this->contadores['lineas_sin_costo']++;

            return $sana;
        }

        $cost = (float) $fila->cost;
        $price = is_null($fila->price) ? null : (float) $fila->price;
        $amount = (float) $fila->amount;
        $ganancia = is_null($fila->ganancia) ? null : (float) $fila->ganancia;
        $unidades = is_null($fila->unidades_individuales) ? null : (float) $fila->unidades_individuales;

        $sana['cost_final'] = $cost;

        /*
         * Una linea con cantidad fraccionada que paso por set_costo_ventas quedo con el costo
         * DIVIDIDO, no multiplicado, y no hay criterio que la distinga de una sana. Se informa.
         */
        if ($amount < 1 && $hacer_b && $this->tiene_firma_del_comando($cost, $price, $amount, $ganancia)) {
            return $this->descartar($sana, 'cantidad_fraccionada_no_evaluable');
        }

        $causas = [];
        $k = null;
        $cost_final = $cost;
        $unidades = (!is_null($unidades) && $unidades > 1) ? $unidades : null;

        /* ── Causa B ─────────────────────────────────────────────────────────────────────── */

        if ($hacer_b && $this->tiene_firma_del_comando($cost, $price, $amount, $ganancia)) {
            if (is_null($price) || $price <= 0) {
                return $this->descartar($sana, 'firma_del_comando_sin_precio_para_acotar_k');
            }

            /*
             * Si el articulo tiene unidades individuales, el costo que el comando multiplico
             * puede ser el del bulto entero (o sea las dos causas encima de la misma linea).
             * En ese caso el techo de coherencia de esta etapa sube por ese factor, o si no el
             * buscador de k se comeria la division de las unidades individuales y devolveria un
             * costo partido de mas. Lo que sobre lo divide despues la causa A.
             */
            $techo = $price * self::FACTOR_COSTO_INCOHERENTE * (is_null($unidades) ? 1 : $unidades);

            $k = $this->buscar_k($cost, $techo, $amount, $k_max);

            if (is_null($k)) {
                return $this->descartar($sana, 'firma_del_comando_sin_k_coherente');
            }

            $cost_final = $cost / pow($amount, $k);
            $causas[] = 'B';
        }

        /* ── Causa A ─────────────────────────────────────────────────────────────────────── */

        $es_incoherente = !is_null($price)
            && $price > 0
            && $cost_final > $price * self::FACTOR_COSTO_INCOHERENTE;

        $tiene_unidades_individuales = !is_null($unidades);

        if ($es_incoherente && $tiene_unidades_individuales && $hacer_a) {
            $candidato = $cost_final / $unidades;

            if ($candidato > 0 && $candidato <= $price * self::FACTOR_COSTO_INCOHERENTE) {
                $cost_final = $candidato;
                $causas[] = 'A';
            } else {
                return $this->descartar($sana, 'dividir_por_unidades_individuales_no_alcanza');
            }
        } elseif ($es_incoherente && $tiene_unidades_individuales) {
            return $this->descartar($sana, 'causa_a_no_pedida_en_esta_corrida');
        } elseif ($es_incoherente) {
            /*
             * El costo supera el doble del precio pero no hay ninguna causa identificada: sin la
             * firma del comando y sin unidades individuales, puede ser perfectamente una venta
             * legitima a perdida. No se toca: se lista.
             */
            return $this->descartar($sana, 'costo_incoherente_sin_causa_identificada');
        }

        if (count($causas) === 0) {
            $this->contadores['lineas_sanas']++;

            return $sana;
        }

        $cost_final = round($cost_final, 2);

        if ($cost_final <= 0) {
            return $this->descartar($sana, 'costo_corregido_no_positivo');
        }

        return [
            'accion' => 'corregir',
            'cost_final' => $cost_final,
            'ganancia_final' => round((is_null($price) ? 0 : $price - $cost_final) * $amount, 2),
            'causas' => $causas,
            'k' => $k,
            'motivo' => null,
        ];
    }

    /**
     * ¿La fila la escribio `set_costo_ventas` con el bug del costo total?
     *
     * Firma: ganancia = price × amount − cost, que es lo que persistia el comando. La linea sana
     * cumple ganancia = (price − cost) × amount. Con amount > 1 y cost > 0 las dos no pueden
     * cumplirse a la vez.
     *
     * @param  float  $cost
     * @param  float|null  $price
     * @param  float  $amount
     * @param  float|null  $ganancia
     * @return bool
     */
    private function tiene_firma_del_comando($cost, $price, $amount, $ganancia)
    {
        /*
         * Con amount = 1 las dos firmas son la misma expresion (y el bug no inflaba nada), asi
         * que la fila no se puede atribuir. Con amount = 0 tampoco hay nada que dividir.
         */
        if (is_null($ganancia) || is_null($price) || $cost <= 0 || $amount <= 0 || $amount == 1.0) {
            return false;
        }

        $tolerancia = $this->tolerancia($amount);

        $firma_del_comando = abs($ganancia - ($price * $amount - $cost)) <= $tolerancia;
        $firma_sana = abs($ganancia - (($price - $cost) * $amount)) <= $tolerancia;

        return $firma_del_comando && !$firma_sana;
    }

    /**
     * Margen de comparacion. Las columnas son decimal(x,2), asi que precio y costo ya vienen
     * redondeados: multiplicar por la cantidad arrastra ese redondeo.
     *
     * @param  float  $amount
     * @return float
     */
    private function tolerancia($amount)
    {
        return 0.02 * max(1.0, abs($amount)) + 0.05;
    }

    /**
     * Menor cantidad de divisiones por la cantidad que deja el costo por debajo del techo de
     * coherencia. Arranca en 1 porque la firma ya probo que el costo se multiplico al menos una
     * vez, y devuelve el MENOR k que entra: la correccion minima, para sub-corregir antes que
     * sobre-corregir.
     *
     * @param  float  $cost
     * @param  float  $techo
     * @param  float  $amount
     * @param  int  $k_max
     * @return int|null
     */
    private function buscar_k($cost, $techo, $amount, $k_max)
    {
        for ($k = 1; $k <= $k_max; $k++) {
            $candidato = $cost / pow($amount, $k);

            if ($candidato > 0 && $candidato <= $techo) {
                return $k;
            }
        }

        return null;
    }

    /**
     * @param  array  $sana
     * @param  string  $motivo
     * @return array
     */
    private function descartar($sana, $motivo)
    {
        $sana['accion'] = 'descartar';
        $sana['motivo'] = $motivo;

        return $sana;
    }

    /**
     * Acumula el costo de articulos proyectado de la venta, con el costo final de cada linea.
     *
     * @param  object  $fila
     * @param  array  $analisis
     * @return void
     */
    private function acumular_costo_de_la_venta($fila, $analisis)
    {
        $cost = $analisis['accion'] === 'corregir'
            ? $analisis['cost_final']
            : (is_null($fila->cost) ? 0.0 : (float) $fila->cost);

        $sale_id = (int) $fila->sale_id;

        if (!isset($this->costo_de_articulos_por_venta[$sale_id])) {
            $this->costo_de_articulos_por_venta[$sale_id] = 0.0;
        }

        $this->costo_de_articulos_por_venta[$sale_id] += $cost * (float) $fila->amount;
    }

    /**
     * @param  object  $fila
     * @param  array  $analisis
     * @return array
     */
    private function armar_correccion($fila, $analisis)
    {
        return [
            'linea_id' => (int) $fila->linea_id,
            'sale_id' => (int) $fila->sale_id,
            'venta_num' => $fila->venta_num,
            'venta_fecha' => $fila->venta_fecha,
            'article_id' => (int) $fila->article_id,
            'amount' => $fila->amount,
            'price' => $fila->price,
            'unidades_individuales' => $fila->unidades_individuales,
            'causas' => $analisis['causas'],
            'k' => $analisis['k'],
            'cost_antes' => $fila->cost,
            'ganancia_antes' => $fila->ganancia,
            'cost_despues' => $analisis['cost_final'],
            'ganancia_despues' => $analisis['ganancia_final'],
        ];
    }

    /**
     * @param  object  $fila
     * @param  array  $analisis
     * @return void
     */
    private function anotar_descarte($fila, $analisis)
    {
        $motivo = $analisis['motivo'];

        if (!isset($this->descartes_por_motivo[$motivo])) {
            $this->descartes_por_motivo[$motivo] = 0;
        }

        $this->descartes_por_motivo[$motivo]++;

        $tope = (int) $this->option('max_descartes');

        if (count($this->descartes) >= $tope) {
            return;
        }

        $this->descartes[] = [
            'linea_id' => (int) $fila->linea_id,
            'sale_id' => (int) $fila->sale_id,
            'venta_num' => $fila->venta_num,
            'article_id' => (int) $fila->article_id,
            'amount' => $fila->amount,
            'price' => $fila->price,
            'cost' => $fila->cost,
            'ganancia' => $fila->ganancia,
            'unidades_individuales' => $fila->unidades_individuales,
            'motivo' => $motivo,
        ];
    }

    /**
     * Ventas tocadas por las correcciones, con sus valores previos tal cual estan en la base.
     *
     * @return array  sale_id => ['total_cost' => string|null, 'ganancia' => string|null]
     */
    private function ventas_afectadas_con_sus_valores_previos()
    {
        $ids = [];

        foreach ($this->correcciones as $correccion) {
            $ids[$correccion['sale_id']] = true;
        }

        $ids = array_keys($ids);
        $ventas = [];

        foreach (array_chunk($ids, 1000) as $tanda) {
            $filas = DB::table('sales')
                ->whereIn('id', $tanda)
                ->select('id', 'num', 'total', 'total_cost', 'ganancia')
                ->get();

            foreach ($filas as $fila) {
                $sale_id = (int) $fila->id;

                $ventas[$sale_id] = [
                    'num' => $fila->num,
                    'total' => $fila->total,
                    'total_cost_antes' => $fila->total_cost,
                    'ganancia_antes' => $fila->ganancia,
                    'costo_de_articulos_proyectado' => isset($this->costo_de_articulos_por_venta[$sale_id])
                        ? round($this->costo_de_articulos_por_venta[$sale_id], 2)
                        : null,
                ];
            }
        }

        return $ventas;
    }

    /**
     * Deja el JSON del respaldo y el .sql de reversion ANTES de escribir una sola fila.
     *
     * @param  int  $user_id
     * @param  array  $ventas
     * @param  bool  $aplicar
     * @return array
     */
    private function escribir_respaldos($user_id, $ventas, $aplicar)
    {
        $carpeta = $this->option('salida');

        if ($carpeta === null || $carpeta === '') {
            $carpeta = storage_path('app/saneo-costo-de-linea');
        }

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0755, true);
        }

        $sello = Carbon::now()->format('Ymd-His');
        $base = rtrim($carpeta, '/\\') . DIRECTORY_SEPARATOR . 'saneo-costo-de-linea-user' . $user_id . '-' . $sello;

        $json = [
            'generado' => Carbon::now()->toDateTimeString(),
            'base_de_datos' => DB::connection()->getDatabaseName(),
            'user_id' => $user_id,
            'modo' => $aplicar ? 'aplicar' : 'dry-run',
            'opciones' => [
                'causa' => $this->option('causa'),
                'k_max' => (int) $this->option('k_max'),
                'sale_id' => $this->option('sale_id'),
                'desde' => $this->option('desde'),
                'hasta' => $this->option('hasta'),
                'limite' => $this->option('limite'),
            ],
            'criterio' => [
                'causa_b' => 'firma ganancia = price x amount - cost (deterministica) + menor k con cost/amount^k <= price x 2 (x unidades_individuales si las tiene)',
                'causa_a' => 'articles.unidades_individuales > 1 y cost > price x 2, se divide por unidades_individuales',
                'factor_de_coherencia' => self::FACTOR_COSTO_INCOHERENTE,
            ],
            'contadores' => array_merge($this->contadores, [
                'lineas_a_corregir' => count($this->correcciones),
                'ventas_afectadas' => count($ventas),
            ]),
            'descartes_por_motivo' => $this->descartes_por_motivo,
            'correcciones' => $this->correcciones,
            'ventas' => $ventas,
            'descartes' => $this->descartes,
        ];

        file_put_contents(
            $base . '.json',
            json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        file_put_contents($base . '-reversion.sql', $this->armar_sql_de_reversion($user_id, $ventas, $sello));

        return ['json' => $base . '.json', 'sql' => $base . '-reversion.sql'];
    }

    /**
     * SQL que devuelve cada fila tocada a su valor exacto anterior.
     *
     * @param  int  $user_id
     * @param  array  $ventas
     * @param  string  $sello
     * @return string
     */
    private function armar_sql_de_reversion($user_id, $ventas, $sello)
    {
        $lineas = [];

        $lineas[] = '-- Reversion del saneo de costo de linea de venta';
        $lineas[] = '-- Cliente (user_id): ' . $user_id;
        $lineas[] = '-- Base: ' . DB::connection()->getDatabaseName();
        $lineas[] = '-- Generado: ' . Carbon::now()->toDateTimeString() . ' (' . $sello . ')';
        $lineas[] = '-- Devuelve article_sale.cost / ganancia y sales.total_cost / ganancia a su valor exacto previo.';
        $lineas[] = '-- Correr entero: no es un reporte, ESCRIBE.';
        $lineas[] = '';
        $lineas[] = 'START TRANSACTION;';
        $lineas[] = '';

        foreach ($this->correcciones as $correccion) {
            $lineas[] = 'UPDATE article_sale SET cost = ' . $this->valor_sql($correccion['cost_antes'])
                . ', ganancia = ' . $this->valor_sql($correccion['ganancia_antes'])
                . ' WHERE id = ' . (int) $correccion['linea_id'] . ';';
        }

        $lineas[] = '';

        foreach ($ventas as $sale_id => $venta) {
            $lineas[] = 'UPDATE sales SET total_cost = ' . $this->valor_sql($venta['total_cost_antes'])
                . ', ganancia = ' . $this->valor_sql($venta['ganancia_antes'])
                . ' WHERE id = ' . (int) $sale_id . ';';
        }

        $lineas[] = '';
        $lineas[] = 'COMMIT;';
        $lineas[] = '';

        return implode("\n", $lineas);
    }

    /**
     * Literal numerico para el SQL, con todos los decimales tal cual los devolvio la base.
     *
     * @param  mixed  $valor
     * @return string
     */
    private function valor_sql($valor)
    {
        if (is_null($valor) || $valor === '' || !is_numeric($valor)) {
            return 'NULL';
        }

        return (string) $valor;
    }

    /**
     * Escribe las correcciones y recalcula los totales de cada venta tocada.
     *
     * @param  array  $sale_ids
     * @return void
     */
    private function aplicar_correcciones($sale_ids)
    {
        $barra = $this->output->createProgressBar(count($this->correcciones));

        foreach (array_chunk($this->correcciones, 500) as $tanda) {
            DB::transaction(function () use ($tanda, $barra) {
                foreach ($tanda as $correccion) {
                    DB::table('article_sale')
                        ->where('id', $correccion['linea_id'])
                        ->update([
                            'cost' => $correccion['cost_despues'],
                            'ganancia' => $correccion['ganancia_despues'],
                        ]);

                    $barra->advance();
                }
            });
        }

        $barra->finish();
        $this->line('');

        /*
         * Los totales de la venta se reescriben con los mismos helpers que usa el sistema en
         * vivo — no con una formula copiada aca — para que el saneo no pueda quedar desalineado
         * de la convencion: set_total_cost suma costo_unitario × cantidad (y las promociones), y
         * set_sale_ganancia deriva la ganancia de la venta de ese total.
         */
        foreach (array_chunk($sale_ids, 100) as $tanda) {
            $ventas = Sale::whereIn('id', $tanda)->get();

            foreach ($ventas as $venta) {
                $venta = SaleTotalesHelper::set_total_cost($venta);
                SaleHelper::set_sale_ganancia($venta);
            }
        }
    }

    /**
     * Muestra unas pocas correcciones para que se vea sobre que se esta por escribir.
     *
     * @return void
     */
    private function reportar_muestra()
    {
        $muestra = array_slice($this->correcciones, 0, 10);
        $filas = [];

        foreach ($muestra as $correccion) {
            $filas[] = [
                $correccion['linea_id'],
                $correccion['sale_id'],
                $correccion['amount'],
                $correccion['price'],
                $correccion['cost_antes'],
                $correccion['cost_despues'],
                implode('+', $correccion['causas']) . (is_null($correccion['k']) ? '' : ' k=' . $correccion['k']),
            ];
        }

        $this->table(
            ['linea', 'venta', 'cant', 'precio', 'costo antes', 'costo despues', 'causa'],
            $filas
        );

        if (count($this->correcciones) > count($muestra)) {
            $this->line('  ... y ' . (count($this->correcciones) - count($muestra)) . ' lineas mas (estan todas en el JSON).');
        }
    }

    /**
     * @return void
     */
    private function reportar_descartes()
    {
        $this->line('Lineas sanas: ' . $this->contadores['lineas_sanas']
            . '. Sin costo cargado: ' . $this->contadores['lineas_sin_costo'] . '.');

        if (count($this->descartes_por_motivo) === 0) {
            return;
        }

        $this->warn('Lineas miradas y NO corregidas (van con su detalle en el JSON):');

        foreach ($this->descartes_por_motivo as $motivo => $cantidad) {
            $this->line('  ' . $motivo . ': ' . $cantidad);
        }
    }
}
