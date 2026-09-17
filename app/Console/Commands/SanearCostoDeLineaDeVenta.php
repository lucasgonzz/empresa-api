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
 *  4. 🔴 La firma sola NO alcanza para escribir: hace falta ademas que el costo guardado sea
 *     implausible como costo unitario (`cost > price`). Sin esa segunda condicion, una linea SANA
 *     a la que le editaron el precio despues de la venta (`SaleHelper::updateItemsPrices()`, que
 *     reescribe `price` sin recalcular `ganancia`) cae justo sobre la firma y el saneo le partia
 *     el costo al medio. El detalle y el caso medido estan en `es_plausible_como_costo_unitario()`.
 *     Lo que esto deja afuera son las lineas genuinamente rotas de margen alto, donde el costo
 *     inflado igual quedo por debajo del precio (costo unitario 100, cantidad 2, precio 1000):
 *     salen listadas con motivo propio, sin corregir.
 *  5. Las ventas en deposito (`to_check` o `checked`) no se tocan: `set_total_cost()` les devuelve
 *     NULL a proposito, asi que corregir una linea les BLANQUEARIA el total_cost a la venta entera.
 *  6. La causa A se repara dividiendo por `articles.unidades_individuales` de HOY. Si ese valor
 *     cambio despues de la venta, la division no reconstruye el costo de entonces. No hay
 *     registro historico de esa columna; la linea se corrige igual porque el costo del bulto es
 *     inequivocamente incorrecto, pero el respaldo permite revertirla. La excepcion: si
 *     `article_sale.unidades_individuales` viniera cargada (hoy no la escribe nada) ESE seria el
 *     valor historico exacto — el comando lo denuncia y deja sin tocar las lineas donde difiera.
 *  7. No se toca `article_budget`. El informe del 1/9 lo dejo explicitamente afuera.
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
     * Cantidad de entradas (correcciones + descartes) por encima de la cual el JSON del respaldo
     * se escribe compacto. Indentar 45.000 entradas —el volumen de golonorte— son decenas de MB
     * que ademas viven enteras en memoria mientras se serializan.
     */
    const TOPE_JSON_INDENTADO = 2000;

    /**
     * Ventas por transaccion en la escritura. Cada transaccion cierra ventas COMPLETAS: sus lineas
     * corregidas y su total recalculado. Ver `aplicar_correcciones()`.
     */
    const VENTAS_POR_TRANSACCION = 50;

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
        /*
         * `article_sale.unidades_individuales` existe como columna del pivot y hoy no la escribe
         * nada. Si apareciera cargada seria el valor historico exacto del articulo al momento de
         * la venta, que es mejor dato que el `articles.unidades_individuales` de hoy — pero nadie
         * midio nunca que haya adentro, asi que el comando lo denuncia en vez de usarlo.
         */
        'lineas_con_unidades_individuales_en_el_pivot' => 0,
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

        /*
         * 🔴 Sin respaldo no se escribe una sola fila. Es la unica forma de volver atras y el
         * comando toca la plata de un negocio real.
         */
        if ($rutas === false) {
            $this->error('No se pudo dejar el respaldo, asi que NO se toco la base. Revisa permisos y espacio en disco y volve a correrlo.');

            return 1;
        }

        if (count($this->correcciones) === 0) {
            $this->reportar_descartes();
            $this->info('No hay nada para corregir con los criterios de esta corrida.');
            $this->line('Respaldo JSON:     ' . $rutas['json']);
            $this->avisar_donde_queda_el_respaldo($rutas);

            return 0;
        }

        $this->reportar_muestra();
        $this->reportar_descartes();

        $this->line('Respaldo JSON:     ' . $rutas['json']);
        $this->line('SQL de reversion:  ' . $rutas['sql']);
        $this->avisar_donde_queda_el_respaldo($rutas);

        if (!$aplicar) {
            $this->warn('Dry-run: no se escribio ninguna fila. Volve a correrlo con --aplicar para persistir.');

            return 0;
        }

        $this->aplicar_correcciones();

        $this->info('Listo. Lineas corregidas: ' . count($this->correcciones)
            . '. Ventas recalculadas: ' . count($ventas) . '.');
        $this->comment('Para revertir: mysql <base> < ' . $rutas['sql']);
        $this->line('Memoria maxima de la corrida: ' . round(memory_get_peak_usage(true) / 1024 / 1024, 1) . ' MB.');

        return 0;
    }

    /**
     * El respaldo queda en el disco del SERVIDOR donde corrio el comando, no en la maquina de
     * quien lo lanzo por SSH. Si nadie lo baja, el dia que haya que revertir no va a estar.
     *
     * @param  array  $rutas
     * @return void
     */
    private function avisar_donde_queda_el_respaldo($rutas)
    {
        $this->warn('🔴 Los dos archivos quedaron en el disco DEL SERVIDOR (storage/app/), no en tu maquina.');
        $this->warn('   Bajalos ahora, antes de seguir: son lo unico que permite revertir esta corrida.');
        $this->line('   scp/sftp desde: ' . dirname($rutas['json']));
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
                /*
                 * Las dos columnas se llaman igual y hay que distinguirlas: la del articulo es el
                 * valor de HOY, la del pivot —si tuviera algo— seria el valor historico exacto.
                 */
                'articles.unidades_individuales as unidades_del_articulo',
                'article_sale.unidades_individuales as unidades_de_la_linea',
                'sales.num as venta_num',
                'sales.created_at as venta_fecha',
                'sales.to_check as venta_to_check',
                'sales.checked as venta_checked'
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
        $unidades = is_null($fila->unidades_del_articulo) ? null : (float) $fila->unidades_del_articulo;

        if (!is_null($fila->unidades_de_la_linea)) {
            $this->contadores['lineas_con_unidades_individuales_en_el_pivot']++;
        }

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
             * 🔴 La firma sola NO alcanza: hay un camino en vivo que la produce sobre una linea
             * PERFECTAMENTE SANA. Ver `es_plausible_como_costo_unitario()`.
             */
            if ($this->es_plausible_como_costo_unitario($cost, $price)) {
                return $this->descartar($sana, 'firma_del_comando_con_costo_plausible_como_unitario');
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

        /*
         * El pivot tiene su propia columna `unidades_individuales`. Si trae un valor distinto del
         * que tiene el articulo hoy, ese es el valor historico y la division de la causa A —que se
         * hizo con el de hoy— estaria corrigiendo con el numero equivocado. Nadie midio nunca que
         * guarda esa columna, asi que no se adivina: se deja la linea sin tocar y se denuncia.
         */
        if (!is_null($fila->unidades_de_la_linea)) {
            $de_la_linea = (float) $fila->unidades_de_la_linea;
            $del_articulo = is_null($fila->unidades_del_articulo) ? null : (float) $fila->unidades_del_articulo;

            if (is_null($del_articulo) || abs($de_la_linea - $del_articulo) > 0.005) {
                return $this->descartar($sana, 'pivot_con_unidades_individuales_historicas_distintas');
            }
        }

        /*
         * 🔴 Venta en deposito (`to_check` o `checked`): `SaleTotalesHelper::set_total_cost()`
         * devuelve NULL para estas ventas a proposito. Corregir una sola de sus lineas obliga a
         * recalcular la venta, y ese recalculo le BLANQUEA el `total_cost` a la venta entera. Se
         * gana un costo de linea y se pierde el total de la venta: no vale la pena.
         */
        if ((int) $fila->venta_to_check === 1 || (int) $fila->venta_checked === 1) {
            return $this->descartar($sana, 'venta_en_deposito_el_recalculo_blanquearia_su_total_cost');
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
     * 🔴 LA FIRMA SOLA NO ALCANZA PARA ESCRIBIR. Una linea sana a la que le editaron el precio
     * despues de la venta (`SaleHelper::updateItemsPrices()`) cumple esta misma firma sin estar
     * rota. Todo lo que cumpla la firma tiene que pasar ademas por
     * `es_plausible_como_costo_unitario()`, que es donde vive esa guarda y donde esta el caso
     * medido.
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
     * 🔴 El costo guardado, ¿todavia sirve como costo unitario de esa misma linea?
     *
     * Esta es la guarda que separa una linea ROTA de una linea SANA a la que alguien le edito el
     * precio, porque las dos pueden cumplir la firma de la causa B.
     *
     * El camino del falso positivo: `SaleHelper::updateItemsPrices()` (llamado desde
     * `SaleController::updatePrices()`) reescribe `price` y `price_sin_iva` de la linea y NO
     * recalcula `ganancia`. La linea queda con la ganancia del precio VIEJO y el precio NUEVO, y
     * eso cae justo sobre la firma cada vez que `price_viejo − price_nuevo = cost × (amount−1) / amount`:
     *
     *     cost 100, amount 2, precio 300 editado a 250  →  ganancia = (300 − 100) × 2 = 400
     *     firma de la causa B:  250 × 2 − 100 = 400  ✓   (y la linea esta perfectamente sana)
     *
     * Sin esta guarda el saneo le escribia `cost = 50`: el costo de un negocio real partido al
     * medio. El mismo vector abre `HelperController::set_sales_cost()`, que pisa `cost` sin tocar
     * `ganancia`.
     *
     * Lo que los separa es que en la linea rota el valor guardado es un TOTAL metido en una columna
     * unitaria —o sea `costo_unitario × cantidad`, con cantidad > 1—, mientras que en el falso
     * positivo el valor guardado sigue siendo el costo unitario de verdad. Un costo unitario que no
     * llega al precio unitario de su propia linea es un costo unitario plausible, y sobre eso no se
     * escribe.
     *
     * Lo que esta guarda deja afuera, y se acepta: las lineas genuinamente rotas de margen alto,
     * donde el costo inflado igual quedo por debajo del precio (`costo_unitario × cantidad ≤ price`,
     * por ejemplo costo 100, cantidad 2 y precio 1000). Salen listadas con el motivo
     * `firma_del_comando_con_costo_plausible_como_unitario` y se pueden mirar a mano. Corregir de
     * menos se arregla despues; corregir de mas ya rompio el dato.
     *
     * ⚠️ El otro criterio que se evaluo —descartar las lineas cuyo `updated_at` no coincida con el
     * `created_at`— NO SIRVE EN ESTE REPO: la relacion `Sale::articles()` no declara
     * `withTimestamps()`, asi que ni `attach()` ni `updateExistingPivot()` escriben
     * `article_sale.updated_at`, y `set_costo_ventas` escribe con `DB::table()->update()`, que
     * tampoco lo toca. Medido el 17/9/2026 sobre `empresa_testing_s23`: 24 filas, 24 con
     * `created_at` y 0 con `updated_at`. La columna esta siempre en NULL, asi que el criterio
     * habria descartado todo o nada segun como se lo escribiera.
     *
     * @param  float  $cost
     * @param  float  $price
     * @return bool
     */
    private function es_plausible_como_costo_unitario($cost, $price)
    {
        return $cost <= $price;
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
            'unidades_individuales' => $fila->unidades_del_articulo,
            'unidades_individuales_de_la_linea' => $fila->unidades_de_la_linea,
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
            'unidades_individuales' => $fila->unidades_del_articulo,
            'unidades_individuales_de_la_linea' => $fila->unidades_de_la_linea,
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
     * 🔴 Si cualquiera de los dos archivos no se puede escribir, esto devuelve false y la corrida
     * se corta sin tocar la base. Antes no se miraba el retorno de `mkdir()` ni el de los
     * `file_put_contents()`: con `storage/app/` sin permiso de escritura (el caso tipico en el
     * shared hosting, con el ownership mal puesto) o el disco lleno, el comando imprimia la ruta
     * de un archivo que no existia y seguia derecho a escribir produccion SIN RESPALDO.
     *
     * @param  int  $user_id
     * @param  array  $ventas
     * @param  bool  $aplicar
     * @return array|false
     */
    private function escribir_respaldos($user_id, $ventas, $aplicar)
    {
        $carpeta = $this->option('salida');

        if ($carpeta === null || $carpeta === '') {
            $carpeta = storage_path('app/saneo-costo-de-linea');
        }

        if (!is_dir($carpeta) && !@mkdir($carpeta, 0755, true) && !is_dir($carpeta)) {
            $this->error('No se pudo crear la carpeta del respaldo: ' . $carpeta);

            return false;
        }

        if (!is_writable($carpeta)) {
            $this->error('La carpeta del respaldo existe pero no se puede escribir: ' . $carpeta);

            return false;
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
                'causa_b' => 'firma ganancia = price x amount - cost, ADEMAS cost > price (si no, un precio editado despues de la venta cumple la misma firma sin estar roto), + menor k con cost/amount^k <= price x 2 (x unidades_individuales si las tiene)',
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

        /*
         * El JSON indentado de 45.000 correcciones (el volumen de golonorte) es varias decenas de
         * MB que ademas viven en memoria enteros mientras se escriben. Por encima del tope va
         * compacto, y el comando lo dice.
         */
        $indentar = (count($this->correcciones) + count($this->descartes)) <= self::TOPE_JSON_INDENTADO;

        $banderas = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($indentar) {
            $banderas = $banderas | JSON_PRETTY_PRINT;
        } else {
            $this->comment('El JSON del respaldo va compacto (sin indentar): son '
                . (count($this->correcciones) + count($this->descartes)) . ' entradas y indentarlo se come la memoria.');
        }

        $contenido = json_encode($json, $banderas);

        if ($contenido === false) {
            $this->error('No se pudo serializar el respaldo a JSON: ' . json_last_error_msg());

            return false;
        }

        $escritos = @file_put_contents($base . '.json', $contenido);

        if ($escritos === false || $escritos !== strlen($contenido)) {
            $this->error('No se pudo escribir el respaldo JSON completo en ' . $base . '.json');

            return false;
        }

        unset($contenido);

        if (!$this->escribir_sql_de_reversion($base . '-reversion.sql', $user_id, $ventas, $sello)) {
            return false;
        }

        return ['json' => $base . '.json', 'sql' => $base . '-reversion.sql'];
    }

    /**
     * Escribe el SQL que devuelve cada fila tocada a su valor exacto anterior.
     *
     * Va derecho al archivo, linea por linea, en vez de armar un string gigante en memoria: con
     * las 45.000 lineas de golonorte el array intermedio mas el implode duplicaban el mismo texto
     * en RAM al pedo. Cada `fwrite` se chequea: un disco lleno a mitad del archivo deja un SQL de
     * reversion truncado, que es peor que no tener ninguno porque parece completo.
     *
     * @param  string  $ruta
     * @param  int  $user_id
     * @param  array  $ventas
     * @param  string  $sello
     * @return bool
     */
    private function escribir_sql_de_reversion($ruta, $user_id, $ventas, $sello)
    {
        $manejador = @fopen($ruta, 'w');

        if ($manejador === false) {
            $this->error('No se pudo abrir para escritura el SQL de reversion: ' . $ruta);

            return false;
        }

        $encabezado = [
            '-- Reversion del saneo de costo de linea de venta',
            '-- Cliente (user_id): ' . $user_id,
            '-- Base: ' . DB::connection()->getDatabaseName(),
            '-- Generado: ' . Carbon::now()->toDateTimeString() . ' (' . $sello . ')',
            '-- Devuelve article_sale.cost / ganancia y sales.total_cost / ganancia a su valor exacto previo.',
            '-- Correr entero: no es un reporte, ESCRIBE.',
            '',
            'START TRANSACTION;',
            '',
        ];

        $ok = $this->volcar($manejador, implode("\n", $encabezado) . "\n");

        foreach ($this->correcciones as $correccion) {
            if (!$ok) {
                break;
            }

            $ok = $this->volcar($manejador, 'UPDATE article_sale SET cost = ' . $this->valor_sql($correccion['cost_antes'])
                . ', ganancia = ' . $this->valor_sql($correccion['ganancia_antes'])
                . ' WHERE id = ' . (int) $correccion['linea_id'] . ";\n");
        }

        if ($ok) {
            $ok = $this->volcar($manejador, "\n");
        }

        foreach ($ventas as $sale_id => $venta) {
            if (!$ok) {
                break;
            }

            $ok = $this->volcar($manejador, 'UPDATE sales SET total_cost = ' . $this->valor_sql($venta['total_cost_antes'])
                . ', ganancia = ' . $this->valor_sql($venta['ganancia_antes'])
                . ' WHERE id = ' . (int) $sale_id . ";\n");
        }

        if ($ok) {
            $ok = $this->volcar($manejador, "\nCOMMIT;\n");
        }

        if (!fflush($manejador)) {
            $ok = false;
        }

        fclose($manejador);

        if (!$ok) {
            $this->error('El SQL de reversion quedo incompleto (disco lleno o sin permiso): ' . $ruta);
        }

        return $ok;
    }

    /**
     * fwrite que no acepta una escritura parcial ni un false.
     *
     * @param  resource  $manejador
     * @param  string  $texto
     * @return bool
     */
    private function volcar($manejador, $texto)
    {
        $escritos = @fwrite($manejador, $texto);

        return $escritos !== false && $escritos === strlen($texto);
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
     * 🔴 LA UNIDAD DE TRABAJO ES LA VENTA, NO LA LINEA, y por eso esto se ve raro para lo que
     * hace. Antes las lineas se escribian en transacciones de 500 y los totales de venta se
     * recalculaban DESPUES, fuera de toda transaccion. Si el proceso se moria en el medio —un
     * timeout de SSH, un OOM, un Ctrl+C, que es exactamente como muere una corrida de 45.000
     * lineas sobre produccion— las lineas quedaban corregidas y los totales de venta viejos. Y no
     * habia vuelta atras: corregida la linea, la firma de la causa B desaparece, la segunda
     * corrida encuentra 0 correcciones y el comando informa "no hay nada para corregir" sobre una
     * venta permanentemente inconsistente.
     *
     * Con la venta como unidad, cada transaccion deja ventas TERMINADAS: sus lineas corregidas y
     * su total recalculado, o nada de las dos cosas. Una corrida cortada a la mitad deja unas
     * ventas listas y las otras intactas —todavia con su firma, o sea todavia detectables—, y
     * volver a correr el comando la termina. Es lo que el test de la doble corrida verifica.
     *
     * Los totales se reescriben con los mismos helpers que usa el sistema en vivo —no con una
     * formula copiada aca— para que el saneo no pueda quedar desalineado de la convencion:
     * set_total_cost suma costo_unitario × cantidad (y las promociones), y set_sale_ganancia
     * deriva la ganancia de la venta de ese total.
     *
     * @return void
     */
    private function aplicar_correcciones()
    {
        $por_venta = [];

        foreach ($this->correcciones as $correccion) {
            $por_venta[(int) $correccion['sale_id']][] = $correccion;
        }

        $barra = $this->output->createProgressBar(count($this->correcciones));

        foreach (array_chunk($por_venta, self::VENTAS_POR_TRANSACCION, true) as $tanda) {
            DB::transaction(function () use ($tanda, $barra) {
                foreach ($tanda as $correcciones_de_la_venta) {
                    foreach ($correcciones_de_la_venta as $correccion) {
                        DB::table('article_sale')
                            ->where('id', $correccion['linea_id'])
                            ->update([
                                'cost' => $correccion['cost_despues'],
                                'ganancia' => $correccion['ganancia_despues'],
                            ]);

                        $barra->advance();
                    }
                }

                /*
                 * Con eager load de las dos relaciones que lee set_total_cost: sin esto eran dos
                 * consultas por venta, y son decenas de miles de ventas.
                 */
                $ventas = Sale::whereIn('id', array_keys($tanda))
                    ->with('articles', 'promocion_vinotecas')
                    ->get();

                foreach ($ventas as $venta) {
                    $venta = SaleTotalesHelper::set_total_cost($venta);
                    SaleHelper::set_sale_ganancia($venta);
                }
            });
        }

        $barra->finish();
        $this->line('');
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

        /*
         * Caso no previsto: si `article_sale.unidades_individuales` tiene datos de alguna version
         * vieja, ese es el valor historico exacto y la causa A se estaria corrigiendo con el valor
         * de HOY. No se adivina: se denuncia para que lo mire una persona.
         */
        if ($this->contadores['lineas_con_unidades_individuales_en_el_pivot'] > 0) {
            $this->warn('🔴 ' . $this->contadores['lineas_con_unidades_individuales_en_el_pivot']
                . ' lineas tienen cargada `article_sale.unidades_individuales`, que hoy no la escribe nada.');
            $this->warn('   Si ese valor viene de una version vieja es el historico EXACTO y hay que usarlo en');
            $this->warn('   vez del `articles.unidades_individuales` de hoy. Las que difieren quedaron sin tocar');
            $this->warn('   (motivo pivot_con_unidades_individuales_historicas_distintas). Mirá el JSON antes de aplicar.');
        }

        if (count($this->descartes_por_motivo) === 0) {
            return;
        }

        $this->warn('Lineas miradas y NO corregidas (van con su detalle en el JSON):');

        foreach ($this->descartes_por_motivo as $motivo => $cantidad) {
            $this->line('  ' . $motivo . ': ' . $cantidad);
        }
    }
}
