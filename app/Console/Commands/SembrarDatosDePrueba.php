<?php

namespace App\Console\Commands;

use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\DeleteModelsHelper;
use App\Http\Controllers\Helpers\Seeders\ActividadTiendaHelper;
use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use App\Http\Controllers\Helpers\Seeders\SemillaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\caja\CajaCierreHelper;
use App\Http\Controllers\Helpers\caja\CajaLiquidacionHelper;
use App\Http\Controllers\Helpers\caja\MovimientoEntreCajaHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Http\Controllers\SaleController;
use App\Models\Address;
use App\Models\AfipTicket;
use App\Models\AperturaCaja;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Caja;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\CompanyPerformance;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\MovimientoCaja;
use App\Models\MovimientoEntreCaja;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\Sale;
use Carbon\Carbon;
use Database\Seeders\FerreteriaArticlesSeeder;
use Database\Seeders\ReportesMesSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * `php artisan semilla:datos` -- grupo 321, prompt 05: el guion mensual y la planilla de control.
 *
 * El prompt 04 dejó el MOTOR (`SemillaHelper`, primitivas que ejecutan una operación en una fecha
 * dada, por el camino real del sistema). Este comando es el GUION: decide cuántas operaciones, de
 * qué monto y en qué fecha, con una aritmética elegida a propósito para que se pueda verificar un
 * reporte mirándolo, sin abrir una planilla aparte. Pedido textual de Lucas: "que todo siga un
 * patrón, que yo sepa que si corro los seeders tengo que tener tanta plata en caja, tanta plata
 * vendida, tanta ganancia".
 */
class SembrarDatosDePrueba extends Command
{
    protected $signature = 'semilla:datos '
        .'{--meses= : Pisa config(\'semilla.meses_atras\') para esta corrida puntual.} '
        .'{--reset : Borra lo que dejó una corrida anterior antes de empezar. SIN esta bandera, correr el comando dos veces DUPLICA los datos.}';

    protected $description = 'Siembra datos de prueba (ventas, cobros, compras, pagos, gastos, cheques, presupuestos) '
        .'con una aritmética verificable a mano, y escribe storage/app/semilla/control.json. '
        .'Solo corre en local o en la instancia demo. SIN --reset, correr el comando dos veces DUPLICA los datos.';

    // ------------------------------------------------------------------------------------------
    // Aritmética (grupo 321, prompt 05). Constantes de la clase, NO configurables por .env a
    // propósito: si se pudieran cambiar, el número dejaría de ser deducible de cabeza y habría que
    // ir a mirar la configuración -- que es justo lo que hace verificable la semilla.
    // ------------------------------------------------------------------------------------------

    /**
     * Ventas por mes, del mes MÁS VIEJO al ACTUAL. Rampa geométrica de razón ~1,39.
     *
     * Es una CANTIDAD de ventas, no un monto: el monto de cada mes sale de multiplicarla por
     * TICKET_PROMEDIO. Se define así, y no con un porcentaje de crecimiento aplicado a un monto
     * base, porque lo que tiene que crecer mes a mes es la cantidad de operaciones (un negocio que
     * crece vende MÁS VECES, no la misma cantidad de ventas cada vez más caras) y porque un
     * porcentaje compuesto deja meses con montos que no se pueden verificar de cabeza.
     *
     * Suma de la serie = 523 ventas · 52.300.000 de ventas brutas en los doce meses.
     */
    const CADENCIA_VENTAS_POR_MES = [4, 6, 8, 11, 15, 21, 29, 40, 56, 77, 107, 149];

    /** Ticket promedio constante, en pesos. Redondo a propósito, para que los totales se verifiquen de cabeza. */
    const TICKET_PROMEDIO = 100000;

    /**
     * Unidades por renglón de venta/compra, rotando por operación.
     *
     * Todos son divisores de TICKET_PROMEDIO para que `price_vender = monto / unidades` dé un
     * número exacto. Cuando el monto de la operación NO es múltiplo de las unidades que le tocan,
     * `renglon_rotativo()` cae a 1 unidad: `article_sale.cost` es `decimal(25,2)`, así que un costo
     * unitario con más de dos decimales se trunca en la base y `SUM(cost * amount)` se desvía de
     * `monto / 2` hasta medio centavo por unidad. Sobre las 523 ventas del año eso se pasa del
     * DELTA de 0,01 con el que compara el test 1 de `tests/Feature/Reportes/4_Semilla_Test.php`.
     */
    const UNIDADES_POR_VENTA = [1, 2, 4, 5, 2, 1, 5, 4];

    /** Costo de cada artículo vendido, como fracción del precio. */
    const COSTO_FRACCION = 0.50;

    /** Devoluciones, como fracción de las ventas brutas. */
    const DEVOLUCIONES_FRACCION = 0.10;

    /** Gastos operativos, como fracción de las ventas NETAS. */
    const GASTOS_FRACCION = 0.20;

    /** Parte de las ventas brutas que es de mostrador (el resto es cuenta corriente). */
    const MOSTRADOR_FRACCION = 0.50;

    /** Cobranza del mes, como fracción de las ventas a cuenta corriente del mismo mes. */
    const COBRANZA_CC_FRACCION = 0.80;

    /**
     * Perfil de pago de cada cliente: qué fracción de su deuda pendiente es capaz de pagar en UN
     * mes. Rota por posición en el catálogo de clientes (`$this->clientes`, ordenado por `num`), o
     * sea que el cliente N° 1 paga todo lo que debe, el N° 3 paga el 40%, el N° 8 no paga nunca, el
     * N° 9 vuelve a pagar todo, y así.
     *
     * 🔴 POR QUÉ EXISTE, Y NO ES UN ADORNO (medido en `empresa_testing_s1` el 17/8/2026). Hasta
     * esta corrida la cobranza del mes se repartía por ROTACIÓN: todo el balde de un método de pago
     * iba a UN cliente elegido por `cliente_rotativo($metodo_id + $offset)`, sin mirar un solo peso
     * de lo que ese cliente debía. Con 25 clientes comprando y 7 cobrados por mes, había clientes a
     * los que se les cobraba cuatro veces lo que habían comprado: 8 de los 25 terminaban el año con
     * saldo A FAVOR (hasta 3.434.436 pesos), se registraban 30.354.011 de cobranzas contra
     * 26.158.000 de ventas, y el reporte de deudores no tenía una sola fila que mostrar.
     *
     * Y no alcanzaba con repartir bien: con `COBRANZA_CC_FRACCION` 0,80 más el arrastre del 20% del
     * mes anterior se cobra, a lo largo del año, EXACTAMENTE todo lo vendido a cuenta corriente
     * menos el 20% del último mes -- que es justo lo que se devuelve en notas de crédito
     * (`DEVOLUCIONES_FRACCION` 0,10 de las brutas = 0,20 de las de cuenta corriente). O sea: aun
     * con un reparto perfecto, el modelo converge a CERO deuda viva y el módulo de cuentas
     * corrientes queda vacío. Lo que hace que quede deuda es que no todos los clientes pagan igual,
     * que es además lo que pasa en un comercio de verdad.
     *
     * De cada ocho clientes: dos pagan todo lo que deben cada mes, uno no paga nunca (el deudor
     * incobrable que el reporte tiene que mostrar) y los otros cinco pagan una parte.
     *
     * 🔴 Los dos primeros valores son 1,0 a propósito: `4_Semilla_Test.php` siembra un mes suelto
     * de 400.000 con solo dos ventas a cuenta corriente (a las posiciones 0 y 1), y con esos dos
     * clientes pagando al día la capacidad del mes (180.000) supera la cobranza pedida (160.000) --
     * o sea que ese test sigue viendo exactamente los mismos números que antes de este cambio.
     */
    const PERFIL_DE_PAGO = [1.0, 0.8, 0.4, 1.0, 0.15, 0.6, 0.9, 0.0];

    /** Compras a proveedores, como fracción de las ventas brutas. */
    const COMPRAS_FRACCION = 0.50;

    /** Pagos a proveedores del mes, como fracción de las compras del mismo mes. */
    const PAGOS_PROVEEDORES_FRACCION = 0.80;

    /** IIBB: alícuota del SaleTax sembrado en el prompt 01, replicada acá para el cálculo de la planilla. */
    const IIBB_FRACCION = 0.03;

    /**
     * Alícuota de IVA de los artículos de la semilla, en PORCENTAJE (21, no 0,21).
     *
     * `FerreteriaArticlesSeeder` siembra el catálogo con `iva_id => 2`, que en `IvaSeeder` es la
     * segunda alícuota de la lista: 21%. Se replica acá porque de este número dependen dos
     * renglones de la planilla: el `importe_iva` de cada comprobante y -- sobre todo -- la base
     * gravada del IIBB, que el reporte calcula NETA de IVA (ver `iibb_estimado` en
     * `planificar_mes()`).
     */
    const IVA_ALICUOTA = 21;

    /**
     * Mezcla de cobro: cómo se reparte TODO lo que entra a caja (mostrador + cobranzas) entre los
     * métodos de pago. Clave = id de CurrentAcountPaymentMethod (ver CurrentAcountPaymentMethodSeeder:
     * 1 Cheque, 3 Efectivo, 4 Transferencia, 6 Mercado Pago).
     */
    const MEZCLA_COBRO = [
        3 => 0.40, // efectivo
        6 => 0.30, // mercado pago
        4 => 0.20, // banco (transferencia)
        1 => 0.10, // cheques
    ];

    /**
     * Nombre con el que cada método de pago aparece en `saldo_por_caja` de la planilla de control.
     *
     * 🔴 El método 4 sigue diciendo 'banco' aunque la caja física que lo recibe pasó a llamarse
     * "Transferencias" (la caja "Banco Nación" existe pero ya no es el default de ningún método).
     * NO lo renombres a 'transferencias' para que "coincida" con el nombre de la caja: la clave de
     * la planilla es un contrato con `4_Semilla_Test.php`, que tiene
     * `const METODOS_DE_PAGO = [3 => 'efectivo', 6 => 'mercado_pago', 4 => 'banco', 1 => 'cheques']`
     * y hace `assertArrayHasKey()` contra estas claves. Ese archivo está prohibido tocarlo.
     */
    const CLAVE_POR_METODO = [
        3 => 'efectivo',
        6 => 'mercado_pago',
        4 => 'banco',
        1 => 'cheques',
    ];

    /**
     * Reparto de TODO lo que se mueve en EFECTIVO entre las 4 sucursales (índice 0 a 3). Solo el
     * efectivo se reparte por sucursal: es la única caja que es exclusiva de cada local (las demás
     * son compartidas, ver prompt 02), y es lo que hace que la sucursal 1 facture el cuádruple que
     * la 4 en el desglose por caja.
     *
     * 🔴 Se aplica a los INGRESOS y a los EGRESOS con los mismos pesos, y eso no es simetría por
     * prolijidad: es lo que mantiene a las cuatro cajas de mostrador fuera del rojo. Hasta el
     * 17/8/2026 el mostrador repartía el efectivo 40/30/20/10 pero los gastos pagados y los pagos a
     * proveedor en efectivo salían TODOS de `caja_para(3, $primer_address_id)` -- la sucursal 1
     * cobraba el 40% y pagaba el 100%, y su caja terminaba cada mes en negativo (medido: −428.000
     * en el mes de 10.700.000). Con los mismos pesos de los dos lados, cada caja es una copia a
     * escala del flujo de efectivo del comercio entero: si el agregado da positivo, las cuatro dan
     * positivo. Ver `metodos_de_efectivo_por_sucursal()`.
     */
    const REPARTO_SUCURSAL = [0.40, 0.30, 0.20, 0.10];

    /** Cantidad de registros por categoría al distribuir un total (mismo criterio que ReportesMesSeeder). */
    const CANT_REGISTROS = 4;

    /**
     * Barrido de efectivo del cierre de mes: qué fracción del saldo acumulado de cada caja de
     * efectivo viaja a la Caja Fuerte, y qué fracción de la Caja Fuerte sigue viaje al Banco
     * Nación. Ver `planificar_barrido_de_efectivo()` para el porqué de las dos cajas y por qué es
     * un PORCENTAJE del saldo y no un monto fijo.
     */
    const BARRIDO_A_CAJA_FUERTE_FRACCION = 0.60;
    const BARRIDO_A_BANCO_FRACCION = 0.70;

    /**
     * Nombres con los que `CajaSeeder::una_caja_para_cada_direccion_y_por_cada_metodo_de_pago()`
     * crea las dos cajas del barrido, y claves con las que aparecen en `saldo_esperado_por_caja`.
     *
     * Se resuelven por NOMBRE, a diferencia de todas las demás cajas de este comando (que salen de
     * `DefaultPaymentMethodCaja` vía `caja_para()`), justamente porque estas dos NO son la caja por
     * defecto de ningún método de pago: ese es el motivo por el que quedaban sin un solo
     * movimiento y no hay otro identificador estable para agarrarlas.
     */
    const CAJA_FUERTE_NOMBRE = 'Caja Fuerte';
    const CAJA_BANCO_NOMBRE = 'Banco Nación';
    const CLAVE_CAJA_FUERTE = 'caja_fuerte';
    const CLAVE_CAJA_BANCO = 'banco_nacion';

    /** @var \App\Http\Controllers\Helpers\Seeders\SemillaHelper */
    protected $semilla;

    protected $user_id;

    /** @var \Illuminate\Support\Collection */
    protected $clientes;

    /** @var \Illuminate\Support\Collection */
    protected $proveedores;

    /** @var \Illuminate\Support\Collection */
    protected $addresses;

    /**
     * Deuda de cuenta corriente que cada cliente arrastra EN LA PLANIFICACIÓN, mes a mes
     * (client_id => saldo en pesos, siempre >= 0). No se consulta la base: la planificación de los
     * doce meses corre entera antes de que se escriba una sola fila (ver `planificar_mes()`), así
     * que la única forma de saber cuánto debe un cliente cuando se decide una cobranza es llevar la
     * cuenta acá.
     *
     * Es lo que hace que no se le cobre a un cliente plata que nunca debió. Ver `PERFIL_DE_PAGO`
     * para el porqué de todo esto.
     *
     * @var array<int,float>
     */
    protected $deuda_por_cliente = [];

    protected $expense_concept_id;

    protected $articulo_id;

    /**
     * Catálogo completo del usuario, ordenado por id. Es sobre esta lista que rota
     * `renglon_rotativo()` para que las ventas no caigan todas sobre el mismo artículo.
     *
     * @var \Illuminate\Support\Collection
     */
    protected $articulos;

    /**
     * Contador de renglones (ventas y compras) de TODA la corrida, no de cada mes.
     *
     * Es global a propósito: si se reiniciara mes a mes, todos los meses le venderían los mismos
     * primeros artículos con las mismas unidades y el catálogo entero quedaría partido en
     * "artículos que se venden" y "artículos que nunca se tocaron".
     *
     * @var int
     */
    protected $contador_renglones = 0;

    /**
     * Orden de planificación de cada operación. Es el desempate del ordenamiento cronológico:
     * dos operaciones del mismo instante tienen que ejecutarse siempre en el mismo orden, o dos
     * corridas con la misma semilla dejan saldos corridos entre sí.
     *
     * @var int
     */
    protected $orden_operacion = 0;

    /**
     * Resumen de las aperturas de caja que hizo `ejecutar_operaciones()`, para la planilla.
     *
     * `sembradas` son las que crea este comando (`dias * cajas`); `previas` son las que ya estaban
     * en la base cuando arrancó (`CajaSeeder` deja una por caja, y `preparar_aperturas_previas()`
     * les mueve el `created_at` pero NO las borra). `total` es lo que Lucas va a contar en la base:
     * la suma de las dos. Informar solo `dias * cajas` dejaba la planilla corta por exactamente la
     * cantidad de cajas del usuario.
     *
     * @var array<string,int>
     */
    protected $resumen_aperturas = ['dias' => 0, 'cajas' => 0, 'sembradas' => 0, 'previas' => 0, 'total' => 0];

    /**
     * Aperturas de caja que ya existían antes de esta corrida, contadas por
     * `preparar_aperturas_previas()`.
     *
     * @var int
     */
    protected $aperturas_previas = 0;

    /**
     * Resumen del barrido de efectivo hacia Caja Fuerte y Banco Nación, para la planilla.
     *
     * @var array<string,int>
     */
    protected $resumen_barrido = [
        'movimientos'               => 0,
        'de_efectivo_a_caja_fuerte' => 0,
        'de_caja_fuerte_a_banco'    => 0,
    ];

    /**
     * Memo de `cajas_del_barrido()`: null mientras no se resolvió.
     *
     * @var array<string,int|null>|null
     */
    protected $memo_cajas_del_barrido = null;

    /**
     * Ids de los Cheque tipo "recibido" creados durante la corrida (para el ciclo de estados del
     * paso 4). Se acumulan en orden de creación.
     *
     * @var array<int,int>
     */
    protected $cheques_recibidos_ids = [];

    /**
     * @return int
     */
    public function handle()
    {
        // Guarda de entorno -- PRIMERA línea del handle(), config('app.env') y NUNCA env('APP_ENV'):
        // con config:cache activo, env() fuera de config/ devuelve null y la guarda dejaría pasar.
        // Este comando borra y crea datos masivamente; que no pueda correr contra un cliente no es
        // opcional.
        if (config('app.env') !== 'local' && config('app.FOR_USER') !== 'demo') {
            $this->error('semilla:datos solo corre en local o en la instancia demo.');
            return 1;
        }

        $this->user_id = config('semilla.user_id');

        // Sin sesión ni Auth, varios helpers (ProviderOrderHelper, ArticleHelper) fallan al
        // recalcular -- mismo criterio que ReportesMesSeeder.
        DeleteModelsHelper::setup_auth_context($this->user_id);

        if ($this->option('reset')) {
            $this->info('--reset: borrando los datos que dejó una corrida anterior...');
            $this->resetear();
        } else {
            $this->info('Sin --reset: lo que ya exista de una corrida anterior NO se toca (se duplica lo que se siembre ahora).');
        }

        $this->cargar_catalogo();

        // ANTES del primer reparto: sin esto, dos corridas del comando dan datos distintos y
        // comparar una con otra es imposible.
        mt_srand((int) config('semilla.semilla_aleatoria'));

        /*
         * 🔴 Todo lo que sigue va adentro de un try/finally SOLO para poder devolver el generador
         * aleatorio global a un estado impredecible al terminar (ver el `finally`). No es paranoia
         * de consola: `DemoSetupHelper::sembrar_datos()` invoca este comando con
         * `Artisan::call('semilla:datos')` DENTRO de un request, y dejar `mt_rand()` sembrado con
         * una constante conocida (`semilla.semilla_aleatoria`, que además está en el repo) vuelve
         * predecible cualquier número aleatorio que se pida después en ese mismo proceso.
         */
        try {
            $this->semilla = new SemillaHelper();

            $meses_atras = !is_null($this->option('meses'))
                ? (int) $this->option('meses')
                : (int) config('semilla.meses_atras');

            if ($meses_atras < 1) {
                $this->error('meses_atras tiene que ser al menos 1 (el mes actual).');
                return 1;
            }

            $cadencia = $this->cadencia_para($meses_atras);

            $control_meses = [];
            $operaciones = [];

            // Arrastre de cobranza: el 20% de las ventas a cuenta corriente que el mes anterior NO
            // cobró se cobra en éste. El mes más viejo arranca en 0 porque no tiene mes anterior.
            $cobranza_arrastrada = 0.0;

            // Saldo planificado de CADA caja real (no por método de pago), acumulado mes a mes. Es
            // lo que le permite al barrido de efectivo sacar un porcentaje de lo que esa caja
            // puntual tiene, y no de lo que tienen las cuatro sumadas.
            $saldo_por_caja_id = [];

            // m = meses_atras - 1 es el mes más VIEJO; m = 0 es el mes ACTUAL (parcial, hasta hoy).
            // Acá SOLO se planifica: no se escribe una sola fila. La ejecución va toda junta y en
            // orden cronológico después del loop, que es lo que permite abrir y cerrar la caja de cada
            // día en el momento que corresponde (ver ejecutar_operaciones()).
            for ($m = $meses_atras - 1; $m >= 0; $m--) {
                $ventas_brutas_mes = (float) ($cadencia[$meses_atras - 1 - $m] * self::TICKET_PROMEDIO);

                $es_mes_actual = ($m === 0);
                $this->info('Planificando mes '.($meses_atras - $m).'/'.$meses_atras.' (meses_atras='.$m.', '.$cadencia[$meses_atras - 1 - $m].' ventas, brutas '.$ventas_brutas_mes.')'.($es_mes_actual ? ' -- mes actual, hasta hoy' : ''));

                $planificado = $this->planificar_mes($m, $ventas_brutas_mes, $es_mes_actual, $cobranza_arrastrada);

                $operaciones = array_merge($operaciones, $planificado['operaciones']);
                $control_meses[] = $planificado['control'];

                foreach ($planificado['saldo_por_caja_id'] as $caja_id => $monto) {
                    $saldo_por_caja_id[$caja_id] = (isset($saldo_por_caja_id[$caja_id]) ? $saldo_por_caja_id[$caja_id] : 0) + $monto;
                }

                // 🔴 El barrido se planifica ACÁ y no adentro de `planificar_mes()` a propósito: es
                // una política de tesorería de la corrida completa, no parte de la aritmética de un
                // mes. El efecto práctico es que `sembrar_mes()` -- el punto de entrada público que
                // usa `4_Semilla_Test.php` -- no siembra ni un movimiento entre cajas y su
                // `saldo_por_caja` sigue teniendo exactamente las mismas cuatro claves (efectivo,
                // mercado_pago, banco, cheques). Adentro de `planificar_mes()` le habría agregado
                // `caja_fuerte` y `banco_nacion`, y ese test recorre las claves con
                // `assertArrayHasKey()` contra un fixture que no tiene esas dos cajas.
                //
                // Solo al cierre de un mes CERRADO: el mes en curso todavía no cerró (su efectivo
                // sigue en el mostrador), y además fechar el barrido al final del mes actual lo
                // dejaría en el futuro respecto del `Carbon::now()` con el que corren `sembrar_hoy()`
                // y la actividad de tienda.
                if (!$es_mes_actual) {
                    $operaciones = array_merge($operaciones, $this->planificar_barrido_de_efectivo(
                        Carbon::now()->startOfMonth()->subMonths($m)->endOfMonth(),
                        $saldo_por_caja_id
                    ));
                }

                $cobranza_arrastrada = round(
                    $planificado['control']['ventas_cuenta_corriente'] * (1 - self::COBRANZA_CC_FRACCION),
                    2
                );
            }

            $this->info('Ejecutando '.count($operaciones).' operaciones en orden cronológico, día por día...');
            $this->ejecutar_operaciones($operaciones, Carbon::now()->startOfDay());

            $this->info('Sembrando el bloque especial de hoy...');
            $control_hoy = $this->sembrar_hoy();

            $this->info('Aplicando ciclo de estados a los cheques recibidos...');
            $ciclo_cheques = $this->sembrar_cheques_con_ciclo();

            $this->info('Sembrando presupuestos en los tres estados...');
            $presupuestos = $this->sembrar_presupuestos();

            // 🔴 ÚLTIMO PASO, y el orden no es negociable: ActividadTiendaHelper necesita las ventas ya
            // sembradas (calcula el saldo a cancelar y los artículos que cada cliente NO compró) y
            // re-siembra el generador aleatorio global, así que cualquier reparto de plata posterior
            // dejaría de reproducirse igual entre corridas.
            $this->info('Sembrando la actividad de la tienda y cancelando el saldo de sus clientes...');
            $actividad_tienda = (new ActividadTiendaHelper())->sembrar($this->user_id);

            $this->escribir_planilla_de_control(
                $control_meses,
                $control_hoy,
                $meses_atras,
                $ciclo_cheques,
                $presupuestos,
                $cadencia,
                $actividad_tienda
            );

            $this->info('Listo.');

            return 0;
        } finally {
            /*
             * PHP no deja guardar y restaurar el estado del Mersenne Twister global, así que lo más
             * cerca que se puede estar de "dejarlo como estaba" es volver a sembrarlo al azar:
             * `mt_srand()` SIN argumento usa `GENERATE_SEED()` (verificado en PHP 7.4.33: dos
             * llamadas seguidas dan flujos distintos). Sin esto, todo lo que corra después en el
             * mismo proceso -- y en el camino de demo eso es el resto del request -- hereda un
             * generador con semilla conocida.
             */
            mt_srand();
        }
    }

    /**
     * La serie de ventas por mes que le toca a esta corrida.
     *
     * Se toman las ÚLTIMAS `$meses_atras` entradas de `CADENCIA_VENTAS_POR_MES`, no las primeras:
     * así el mes actual siempre es el pico de la rampa (149 ventas) y una corrida corta
     * (`--meses=3`) muestra el tramo reciente del negocio, que es lo que uno quiere mirar en una
     * demo. Si se piden más meses que los que tiene la serie, los más viejos repiten el primer
     * valor en vez de extrapolar hacia abajo (extrapolar daría meses de menos de una venta).
     *
     * @param int $meses_atras
     * @return array<int,int> Ventas por mes, del más viejo al actual.
     */
    protected function cadencia_para($meses_atras)
    {
        $serie = self::CADENCIA_VENTAS_POR_MES;
        $largo = count($serie);

        if ($meses_atras >= $largo) {
            return array_merge(array_fill(0, $meses_atras - $largo, $serie[0]), $serie);
        }

        return array_values(array_slice($serie, $largo - $meses_atras));
    }

    /**
     * Carga el catálogo sembrado por los prompts 01/02 (clientes, proveedores, sucursales, un
     * concepto de gasto, un artículo) UNA sola vez, antes de sembrar nada. Fallar ruidoso si falta
     * algo: es exactamente el modo de falla silencioso que este grupo entero viene a cerrar.
     *
     * @return void
     */
    protected function cargar_catalogo()
    {
        $this->clientes = Client::where('user_id', $this->user_id)->orderBy('num')->get();
        $this->proveedores = Provider::where('user_id', $this->user_id)->orderBy('num')->get();
        $this->addresses = Address::where('user_id', $this->user_id)->orderBy('num')->get();

        // El ledger de deuda arranca en cero en cada corrida. Se resetea acá y no en el constructor
        // porque éste es el único punto por el que pasan los DOS caminos de entrada (`handle()` y
        // `preparar_para_test()`), y porque un mismo proceso puede instanciar el comando dos veces
        // (el `tearDown()` de `4_Semilla_Test.php` lo hace).
        $this->deuda_por_cliente = [];

        if ($this->clientes->isEmpty()) {
            throw new \Exception('semilla:datos: no hay ningún Client para el usuario '.$this->user_id.'. ¿Corrió el prompt 01 (db:seed)?');
        }
        if ($this->proveedores->isEmpty()) {
            throw new \Exception('semilla:datos: no hay ningún Provider para el usuario '.$this->user_id.'.');
        }
        if ($this->addresses->isEmpty()) {
            throw new \Exception('semilla:datos: no hay ninguna Address para el usuario '.$this->user_id.'. ¿Corrió el prompt 01 (AddressSeeder)?');
        }

        $expense_concept = ExpenseConcept::where('user_id', $this->user_id)->first();
        if (is_null($expense_concept)) {
            throw new \Exception('semilla:datos: no hay ningún ExpenseConcept para el usuario '.$this->user_id.'.');
        }
        $this->expense_concept_id = $expense_concept->id;

        // Ordenado por id y no por `first()`: `renglon_rotativo()` recorre esta lista por posición,
        // así que el orden tiene que ser el mismo en todas las corridas o la venta N° 37 le pega a
        // un artículo distinto cada vez y dos control.json dejan de ser comparables.
        $this->articulos = Article::where('user_id', $this->user_id)->orderBy('id')->get();

        if ($this->articulos->isEmpty()) {
            throw new \Exception('semilla:datos: no hay ningún Article para el usuario '.$this->user_id.'.');
        }

        // Se conserva para los pocos lugares que todavía piden "un artículo cualquiera"
        // (`sembrar_hoy()`, `sembrar_presupuestos()`), que no forman parte de la aritmética del mes.
        $this->articulo_id = $this->articulos->first()->id;
    }

    /**
     * Grupo 321 · Prompt 06 — arma el estado interno mínimo que `handle()` prepara antes del loop
     * de meses (usuario, catálogo, generador aleatorio determinista y el motor `SemillaHelper`),
     * SIN la guarda de entorno, sin `--reset` y sin loguear nada por consola. Único punto de
     * entrada pensado para que un test de aceptación pueda invocar `sembrar_mes()` sobre un solo
     * mes, sin correr el comando `semilla:datos` completo (que sembraría varios meses y chocaría
     * con los rangos de fecha de otros tests de la suite).
     *
     * @return void
     */
    public function preparar_para_test()
    {
        $this->user_id = config('semilla.user_id');

        $this->cargar_catalogo();

        mt_srand((int) config('semilla.semilla_aleatoria'));

        $this->semilla = new SemillaHelper();
    }

    /**
     * Siembra un mes completo: ventas de mostrador (repartidas por sucursal y por método de
     * pago), ventas a cuenta corriente, cobranzas, compras a proveedores, pagos a proveedores,
     * gastos, devoluciones y el comprobante de venta/compra correspondiente a cada operación
     * cobrada. Devuelve el renglón de control de ESTE mes, calculado desde estos mismos números
     * -- nunca consultando la base después.
     *
     * Público desde el grupo 321 · Prompt 06, para que un test de aceptación pueda invocarlo
     * directamente sobre un solo mes (ver `preparar_para_test()`).
     *
     * Desde la semilla de datos de demo hace las dos cosas en dos pasos: `planificar_mes()` calcula
     * TODO sin escribir una fila, y `ejecutar_operaciones()` ejecuta lo planificado en orden
     * cronológico. `handle()` usa los dos por separado (para poder ordenar los doce meses juntos);
     * este método los encadena para el único mes que le pide un llamador externo.
     *
     * @param int $meses_atras 0 = mes actual.
     * @param float $ventas_brutas_mes
     * @param bool $es_mes_actual Si es true, el mes se siembra solo hasta HOY (no hasta fin de mes).
     * @param float $cobranza_arrastrada Deuda de cuenta corriente del mes ANTERIOR que se cobra en
     *                                    éste. Cuarto parámetro OPCIONAL: `4_Semilla_Test.php:102`
     *                                    llama con tres argumentos y no se puede tocar.
     * @return array<string,mixed>
     */
    public function sembrar_mes($meses_atras, $ventas_brutas_mes, $es_mes_actual, $cobranza_arrastrada = 0)
    {
        $planificado = $this->planificar_mes($meses_atras, $ventas_brutas_mes, $es_mes_actual, $cobranza_arrastrada);

        $this->ejecutar_operaciones($planificado['operaciones']);

        return $planificado['control'];
    }

    /**
     * Calcula el mes entero -- montos, fechas, métodos de pago, artículos, unidades y comprobantes
     * -- y devuelve la lista de operaciones a ejecutar más el renglón de control. NO escribe nada
     * en la base.
     *
     * Existe separado de la ejecución por el pedido 8 (que cada movimiento de caja cuelgue de la
     * apertura del día en que sucedió): para eso hay que ejecutar los doce meses mezclados y en
     * orden cronológico, y eso solo se puede si antes se sabe qué operaciones hay y en qué fecha,
     * sin haberlas ejecutado todavía.
     *
     * @param int $meses_atras
     * @param float $ventas_brutas_mes
     * @param bool $es_mes_actual
     * @param float $cobranza_arrastrada
     * @return array{operaciones: array<int,array<string,mixed>>, control: array<string,mixed>, saldo_por_caja_id: array<int,float>}
     */
    protected function planificar_mes($meses_atras, $ventas_brutas_mes, $es_mes_actual, $cobranza_arrastrada = 0)
    {
        $operaciones = [];

        $inicio_mes = Carbon::now()->startOfMonth()->subMonths($meses_atras);
        $fin_mes = $es_mes_actual ? Carbon::now()->copy() : $inicio_mes->copy()->endOfMonth();
        // Cantidad de días disponibles para repartir las operaciones de este mes (el mes actual
        // parcial tiene menos días que un mes cerrado).
        $dias_disponibles = max(1, $inicio_mes->diffInDays($fin_mes));

        /*
         * 🔴 Fecha ÚNICA de todo lo que SACA plata de una caja este mes (pagos a proveedor y gastos
         * pagados): el último día del mes a las 20:00. No es cosmético, es lo que vuelve imposible
         * que una caja pase por rojo.
         *
         * El saldo de una caja es corriente y se arma día por día, así que repartir bien los montos
         * no alcanza: un mes que CIERRA en positivo puede haber pasado por negativo a mitad de
         * camino si el pago cayó antes que las ventas. Y las cajas arrancan en 0
         * (`CajaSeeder`), o sea que en el primer mes sembrado no hay colchón previo: cualquier
         * egreso anterior al primer ingreso deja la caja negativa, sin importar los pesos.
         *
         * Con esta fecha el orden queda garantizado sin depender del azar: `fecha_en_rango()` nunca
         * pasa de las 19:59 y nunca llega al último día de un mes cerrado (`dias_disponibles` sale
         * de `diffInDays`, que redondea para abajo), así que cuando se paga, TODO el efectivo del
         * mes ya entró. El barrido de fin de mes va después, a las 20:30, y el cierre de cajas a
         * las 22:00.
         *
         * Además es el orden real de un comercio -- primero entra la plata del mes, después se paga
         * -- y arregla de paso un desorden que ya existía: hasta ahora un pago a proveedor podía
         * quedar fechado ANTES que la compra que estaba pagando.
         *
         * El mes en curso es la excepción: se usa la hora real, porque fechar a las 20:00 de hoy
         * cuando son las 10:00 dejaría movimientos en el futuro. Ahí el orden estricto se pierde
         * solo si la corrida cae un día 1 (único caso en que `fecha_en_rango()` puede devolver el
         * día de hoy), y para entonces la caja arrastra el saldo de los once meses anteriores.
         */
        $fecha_egresos_de_caja = $es_mes_actual
            ? $fin_mes->copy()
            : $fin_mes->copy()->setTime(20, 0);

        // Desplaza a qué cliente/proveedor le toca cada índice, mes a mes -- sin esto, todos los
        // meses arrancan la rotación en el mismo punto y con CANT_REGISTROS=4 nunca se alcanzan
        // los 10 clientes/proveedores sembrados en el prompt 01 (hallazgo del checker, 3/8/2026).
        $offset_rotacion = $meses_atras * 3;

        // --- La aritmética del mes, toda derivada de $ventas_brutas_mes -----------------------
        $mostrador_total = round($ventas_brutas_mes * self::MOSTRADOR_FRACCION, 2);
        $ventas_cc_total = round($ventas_brutas_mes - $mostrador_total, 2);
        // Notas de crédito SOLO en el mes en curso (pedido 12): una devolución es una operación
        // reciente, no algo que aparezca repartido parejo a lo largo de un año. El test 2 de
        // `4_Semilla_Test.php` sigue viéndolas porque llama con $meses_atras = 0 -- no ates esta
        // condición al índice del loop de handle(), que dejaría al test sin devoluciones.
        $devoluciones_total = ($meses_atras === 0)
            ? round($ventas_brutas_mes * self::DEVOLUCIONES_FRACCION, 2)
            : 0.0;
        $ventas_netas = round($ventas_brutas_mes - $devoluciones_total, 2);
        $costo_total = round($ventas_netas * self::COSTO_FRACCION, 2);
        $resultado_bruto = round($ventas_netas - $costo_total, 2);
        $gastos_total = round($ventas_netas * self::GASTOS_FRACCION, 2);
        $resultado_operativo = round($resultado_bruto - $gastos_total, 2);
        // El arrastre es lo que hace que la deuda de cuenta corriente no se acumule doce meses:
        // sin él, después del año TODOS los clientes tienen una venta impaga vencida y
        // `CriteriosDeOfertaService::excluir_malos_pagadores()` los descarta enteros, con lo cual
        // `ofertas:generar` devuelve cero líneas sin un solo error a la vista.
        $cobranza_arrastrada = round((float) $cobranza_arrastrada, 2);
        /*
         * 🔴 Esto es lo que la aritmética del mes PIDE cobrar, no lo que se cobra. Lo que se cobra
         * es `$cobranza_cc_total`, y sale de cruzar este número con la deuda que los clientes
         * REALMENTE tienen (`capacidad_de_cobro_del_mes()`), más abajo -- después de planificar las
         * ventas a cuenta corriente del mes, que son parte de esa deuda.
         *
         * Antes del 17/8/2026 este número se cobraba tal cual, sin mirar deuda. Ver `PERFIL_DE_PAGO`
         * para qué desastre producía eso.
         */
        $cobranza_cc_pedida = round($ventas_cc_total * self::COBRANZA_CC_FRACCION + $cobranza_arrastrada, 2);
        $compras_total = round($ventas_brutas_mes * self::COMPRAS_FRACCION, 2);
        $pagos_proveedores_total = round($compras_total * self::PAGOS_PROVEEDORES_FRACCION, 2);
        /*
         * IIBB, con la MISMA base que usa el reporte, no con el total facturado.
         *
         * `ContabilidadRepository::iibb_determinado()` no aplica la alícuota sobre lo vendido: la
         * aplica sobre `SUM(article_sale.price_sin_iva * article_sale.amount)`, o sea sobre la base
         * NETA de IVA. Y `price_sin_iva` lo escribe `SaleHelper::get_price_sin_iva()`, que hace
         * `price / (1 + iva/100)` con el `iva_id` del artículo -- los del catálogo de la semilla van
         * con `iva_id => 2`, que es 21% (ver `IVA_ALICUOTA`). O sea: la base gravada del mes es
         * `ventas_brutas / 1,21`, no `ventas_brutas`.
         *
         * Y es sobre las BRUTAS, no sobre las netas: la devolución de esta semilla es una nota de
         * crédito (`SemillaHelper::devolucion()` -> `CurrentAcountHelper::notaCredito()`), que
         * escribe `article_current_acount`; las líneas de `article_sale` de la venta original
         * quedan intactas y siguen entrando enteras en la base de IIBB.
         *
         * Lo único que queda de aproximación es el redondeo: `get_price_sin_iva()` redondea el
         * precio unitario a 2 decimales antes de multiplicar por las unidades, así que sobre las
         * ~500 ventas del año el reporte puede quedar unos pocos pesos por debajo de este número.
         * Lo que había antes no era eso: era un 21% fijo de más, todos los meses.
         */
        $iibb_estimado = round(
            ($ventas_brutas_mes / (1 + (self::IVA_ALICUOTA / 100))) * self::IIBB_FRACCION,
            2
        );

        $saldo_por_caja = []; // nombre_metodo => monto (ingresos MENOS egresos)
        /*
         * Ingresos brutos por método de pago, sin restar un solo egreso. Va aparte de
         * `$saldo_por_caja` porque la comisión de cobro se cobra SOLO sobre lo que entra:
         * `MovimientoCajaHelper::crear_movimiento()` enciende `aplica_liquidacion` únicamente
         * cuando `$data['ingreso'] > 0` (línea 37 de ese archivo), así que un egreso -- por
         * ejemplo un pago a proveedor por Mercado Pago -- nunca liquida ni genera comisión.
         */
        $ingresos_por_caja = []; // nombre_metodo => ingresos del mes
        /*
         * Lo mismo pero indexado por caja REAL. Lo necesita el barrido de efectivo de `handle()`:
         * las cuatro cajas de efectivo comparten la clave 'efectivo' de la planilla, y para no
         * barrer más plata de la que hay en el mostrador de una sucursal puntual hace falta saber
         * cuánto tiene ESA caja, no las cuatro sumadas.
         */
        $saldo_por_caja_id = []; // caja_id => saldo (ingresos MENOS egresos)
        $saldo_por_cliente_delta = []; // client_id => delta de deuda (ventas_cc - cobranzas - devoluciones)
        $saldo_por_proveedor_delta = []; // provider_id => delta de deuda (compras - pagos)

        $primer_address_id = $this->addresses->first()->id;

        // --- Cuántas ventas tiene el mes (pedido 4: cadencia en rampa) -------------------------
        // La cantidad sale del monto dividido el ticket promedio, y no al revés, para que
        // `sembrar_mes()` siga aceptando un monto suelto (que es como lo llama el test) sin tener
        // que conocer la serie.
        $cantidad_mes = max(1, (int) round($ventas_brutas_mes / self::TICKET_PROMEDIO));
        $cant_mostrador = max(1, (int) round($cantidad_mes * self::MOSTRADOR_FRACCION));
        $cant_cc = max(1, $cantidad_mes - $cant_mostrador);

        // Índice de la venta DENTRO DEL MES: la mitad de las ventas se factura (pedido 12), y la
        // mitad se resuelve por paridad de este índice en vez de por azar, para que la cantidad de
        // comprobantes del mes sea deducible de cabeza igual que el resto de la aritmética.
        $indice_venta_mes = 0;
        $ventas_facturadas = 0;

        // --- Mostrador: los baldes de plata por caja (reparto exacto, no aleatorio) ------------
        //
        // 🔴 El cheque NO se reparte acá, a propósito: ChequeHelper::get_tipo() decide
        // 'recibido' vs 'emitido' mirando si el modelo tiene client_id, y una venta de mostrador
        // SIEMPRE tiene client_id null -- un cheque nacido de una venta de mostrador quedaría
        // 'emitido' (como si la empresa lo hubiera emitido a un proveedor), no 'recibido' (como
        // si un cliente lo hubiera entregado), y ChequeController::index() solo agrupa como
        // recibido/endosado los que son 'recibido'. El 10% que le tocaría a cheque en el
        // mostrador se suma acá al efectivo, para que mostrador_total se siga cubriendo al 100%
        // entre los métodos que sí se usan. Los cheques "recibidos" de este guion salen todos de
        // cobranza_cuenta_corriente(), más abajo, que siempre tiene un client_id real.
        $baldes = $this->baldes_de_mostrador($mostrador_total);

        // Las ventas del mostrador y los baldes son DOS PARTICIONES EXACTAS del mismo entero
        // ((int) round($mostrador_total)). Esa igualdad es lo que hace que repartir_metodos_en_ventas()
        // pueda llenar balde por balde y la suma por caja del mes salga EXACTA, sin importar
        // cuántas ventas tenga el mes.
        $montos_mostrador = $this->repartir_monto_en_ventas($mostrador_total, $cant_mostrador);
        $metodos_por_venta = $this->repartir_metodos_en_ventas($montos_mostrador, $baldes);

        foreach ($montos_mostrador as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }

            $metodos = [];
            $address_id = null;

            foreach ($metodos_por_venta[$indice] as $parte) {
                $metodos[] = [
                    'current_acount_payment_method_id' => $parte['metodo_id'],
                    'amount'                           => $parte['monto'],
                    'caja_id'                          => $parte['caja_id'],
                ];

                // La venta se asienta en la sucursal del primer balde de EFECTIVO que consumió: es
                // la única caja atada a un local, así que es la única que dice dónde pasó la venta.
                if (is_null($address_id) && $parte['clave'] === 'efectivo') {
                    $address_id = $parte['address_id'];
                }

                $saldo_por_caja[$parte['clave']] = ($saldo_por_caja[$parte['clave']] ?? 0) + $parte['monto'];
                $ingresos_por_caja[$parte['clave']] = ($ingresos_por_caja[$parte['clave']] ?? 0) + $parte['monto'];
                $saldo_por_caja_id[$parte['caja_id']] = ($saldo_por_caja_id[$parte['caja_id']] ?? 0) + $parte['monto'];
            }

            if (is_null($address_id)) {
                // Venta que cayó entera en Mercado Pago o en banco: no hay sucursal que la reclame,
                // se rotan las que haya para que no queden todas en la primera.
                $address_id = $this->addresses->get($indice % $this->addresses->count())->id;
            }

            $renglon = $this->renglon_rotativo($monto);
            $facturar = ($indice_venta_mes % 2 === 0);

            if ($facturar) {
                $ventas_facturadas++;
            }
            $indice_venta_mes++;

            $operaciones[] = $this->operacion($this->fecha_en_rango($inicio_mes, $dias_disponibles), 'venta_mostrador', [
                'monto'       => $monto,
                'address_id'  => $address_id,
                'metodos'     => $metodos,
                'articulo_id' => $renglon['articulo_id'],
                'unidades'    => $renglon['unidades'],
                'facturar'    => $facturar,
                'importe_iva' => round($monto * (self::IVA_ALICUOTA / 100), 2),
                // Sin cliente no hay condición de IVA que mirar: una venta de mostrador se factura B.
                'cbte_letra'  => 'B',
                'cbte_tipo'   => '6',
            ]);
        }

        // --- Ventas a cuenta corriente, repartidas entre los clientes --------------------------
        // Reparto exacto en partes iguales (no `distribuir()`, que sortea un factor por parte): la
        // cantidad de ventas del mes ahora la manda la cadencia, así que el monto de cada una tiene
        // que salir de dividir el total por esa cantidad.
        $montos_cc = $this->repartir_monto_en_ventas($ventas_cc_total, $cant_cc);

        foreach ($montos_cc as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            $cliente = $this->cliente_rotativo($indice + $offset_rotacion);
            $address = $this->addresses->get($indice % $this->addresses->count());

            $renglon = $this->renglon_rotativo($monto);
            $facturar = ($indice_venta_mes % 2 === 0);
            $comprobante = $this->comprobante_para($cliente);

            if ($facturar) {
                $ventas_facturadas++;
            }
            $indice_venta_mes++;

            $operaciones[] = $this->operacion($this->fecha_en_rango($inicio_mes, $dias_disponibles), 'venta_cuenta_corriente', [
                'monto'       => $monto,
                'client_id'   => $cliente->id,
                'address_id'  => $address->id,
                'articulo_id' => $renglon['articulo_id'],
                'unidades'    => $renglon['unidades'],
                'facturar'    => $facturar,
                'importe_iva' => round($monto * (self::IVA_ALICUOTA / 100), 2),
                'cbte_letra'  => $comprobante['cbte_letra'],
                'cbte_tipo'   => $comprobante['cbte_tipo'],
            ]);

            $saldo_por_cliente_delta[$cliente->id] = ($saldo_por_cliente_delta[$cliente->id] ?? 0) + $monto;
            // La venta que se acaba de planificar ya es deuda cobrable de este mismo mes.
            $this->deuda_por_cliente[$cliente->id] = round((isset($this->deuda_por_cliente[$cliente->id]) ? $this->deuda_por_cliente[$cliente->id] : 0) + $monto, 2);
        }

        // --- Cuánto se puede cobrar este mes, y a quién ----------------------------------------
        // Se arma DESPUÉS de las ventas del mes (la venta de hoy ya es deuda cobrable) y ANTES de
        // las cobranzas. `$cobranza_cc_total` es el mínimo entre lo que la aritmética pide y lo que
        // los clientes pueden pagar: nunca se cobra plata que nadie debe. Todo lo que se apoya en
        // la cobranza -- el desglose por caja, `entra_a_caja`, la comisión de Mercado Pago -- se
        // calcula desde acá, así que la planilla de control sigue describiendo exactamente lo que
        // se sembró.
        $capacidad_de_cobro = $this->capacidad_de_cobro_del_mes();
        $cobranza_cc_total = min($cobranza_cc_pedida, round(array_sum($capacidad_de_cobro), 2));
        $entra_a_caja_total = round($mostrador_total + $cobranza_cc_total, 2);

        // --- Cobranzas de cuenta corriente, repartidas por método de pago y por cliente --------
        foreach (self::MEZCLA_COBRO as $metodo_id => $fraccion) {
            $monto_metodo = round($cobranza_cc_total * $fraccion, 2);
            if ($monto_metodo <= 0) {
                continue;
            }

            if ($metodo_id === 1) {
                // Cheque: TODOS los cheques "recibidos" del guion salen de acá (ver el comentario
                // de la sección de mostrador arriba, sobre por qué mostrador no los usa). Se
                // reparte en varios registros chicos, no uno solo por mes, para que
                // sembrar_cheques_con_ciclo() tenga suficientes cheques para sus 4 estados aun
                // con --meses=1: un único cheque por mes no alcanza para cobrado + rechazado +
                // endosado + en cartera.
                $montos_cheque = $this->repartir_en_registros($monto_metodo, self::CANT_REGISTROS);

                foreach ($montos_cheque as $indice_cheque => $monto_cheque) {
                    if ($monto_cheque <= 0) {
                        continue;
                    }

                    // 🔴 Cada registro de cheque se reparte entre los clientes que TIENEN esa
                    // deuda, en vez de ir entero a un cliente rotativo. Lo normal es que el
                    // registro entre completo en el primer cliente de la lista (es el 2,5% de la
                    // cobranza del mes); si no entra, sigue con el siguiente. Ver `PERFIL_DE_PAGO`.
                    foreach ($this->tomar_de_la_capacidad($monto_cheque, $capacidad_de_cobro) as $indice_parte => $parte) {
                        $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);
                        $caja_id = $this->semilla->caja_para(1, $primer_address_id);

                        $operaciones[] = $this->operacion($fecha, 'cobro_cuenta_corriente', [
                            'monto'             => $parte['monto'],
                            'client_id'         => $parte['client_id'],
                            'registrar_cheque'  => true,
                            'metodos'           => [
                                [
                                    'current_acount_payment_method_id' => 1,
                                    'amount'                             => $parte['monto'],
                                    'caja_id'                            => $caja_id,
                                    // El índice de la parte entra en el número para que dos cheques
                                    // del mismo registro no puedan salir con el mismo número.
                                    'numero'                             => 'COB-'.$meses_atras.'-'.$indice_cheque.'-'.$indice_parte.'-'.mt_rand(10000, 99999),
                                    'banco'                               => 'Banco Nación',
                                    'fecha_emision'                       => $fecha->format('Y-m-d'),
                                    'fecha_pago'                           => $fecha->copy()->addDays(30)->format('Y-m-d'),
                                ],
                            ],
                        ]);

                        $saldo_por_caja['cheques'] = ($saldo_por_caja['cheques'] ?? 0) + $parte['monto'];
                        $ingresos_por_caja['cheques'] = ($ingresos_por_caja['cheques'] ?? 0) + $parte['monto'];
                        $saldo_por_caja_id[$caja_id] = ($saldo_por_caja_id[$caja_id] ?? 0) + $parte['monto'];
                        $saldo_por_cliente_delta[$parte['client_id']] = ($saldo_por_cliente_delta[$parte['client_id']] ?? 0) - $parte['monto'];
                    }
                }

                continue;
            }

            // Mismo criterio que los cheques: el balde del método se reparte entre los clientes que
            // realmente deben, de mayor a menor deuda cobrable, y nunca por encima de lo que cada
            // uno debe. La suma de las partes es EXACTAMENTE `$monto_metodo` (salvo que se acabe la
            // capacidad del mes, que no puede pasar porque `$cobranza_cc_total` ya está topeado por
            // ella), así que el desglose por caja del mes sigue siendo el que dice la planilla.
            foreach ($this->tomar_de_la_capacidad($monto_metodo, $capacidad_de_cobro) as $parte) {

                // El efectivo se reparte entre las cuatro cajas de mostrador; los otros métodos van
                // a una caja compartida y no hay nada que repartir. Antes esta cobranza entraba
                // entera a la caja de la sucursal 1, que es la misma que pagaba todos los egresos
                // de efectivo.
                $metodos_cobro = ($metodo_id === 3)
                    ? $this->metodos_de_efectivo_por_sucursal($parte['monto'])
                    : [
                        [
                            'current_acount_payment_method_id' => $metodo_id,
                            'amount'                           => $parte['monto'],
                            'caja_id'                          => $this->semilla->caja_para($metodo_id, $primer_address_id),
                        ],
                    ];

                $operaciones[] = $this->operacion($this->fecha_en_rango($inicio_mes, $dias_disponibles), 'cobro_cuenta_corriente', [
                    'monto'     => $parte['monto'],
                    'client_id' => $parte['client_id'],
                    'metodos'   => $metodos_cobro,
                ]);

                $nombre = self::CLAVE_POR_METODO[$metodo_id];
                $saldo_por_caja[$nombre] = ($saldo_por_caja[$nombre] ?? 0) + $parte['monto'];
                $ingresos_por_caja[$nombre] = ($ingresos_por_caja[$nombre] ?? 0) + $parte['monto'];
                $saldo_por_cliente_delta[$parte['client_id']] = ($saldo_por_cliente_delta[$parte['client_id']] ?? 0) - $parte['monto'];

                foreach ($metodos_cobro as $movimiento) {
                    $saldo_por_caja_id[$movimiento['caja_id']] = ($saldo_por_caja_id[$movimiento['caja_id']] ?? 0) + $movimiento['amount'];
                }
            }
        }

        // --- Devoluciones, repartidas entre clientes que tengan deuda que absorberlas -----------
        //
        // 🔴 Una nota de crédito es un HABER en la cuenta corriente del cliente, igual que un cobro:
        // si cae sobre un cliente que no debe nada, lo deja con saldo a favor. Antes se elegía por
        // rotación (`cliente_rotativo($indice + 2 + $offset)`) y eso era la mitad de los saldos
        // negativos medidos el 17/8/2026 (4 de los 8 clientes en rojo tenían nota de crédito). Se
        // reparte con la misma máquina que las cobranzas, pero contra la deuda ENTERA del cliente
        // (perfil 1,0): la devolución no depende de la voluntad de pago de nadie, solo de que haya
        // algo que devolver.
        $montos_devoluciones = $devoluciones_total > 0
            ? $this->repartir_en_registros($devoluciones_total, self::CANT_REGISTROS)
            : [];

        $deuda_para_devoluciones = $devoluciones_total > 0
            ? $this->capacidad_de_cobro_del_mes(1.0)
            : [];

        foreach ($montos_devoluciones as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }

            foreach ($this->repartir_devolucion($monto, $deuda_para_devoluciones) as $parte) {
                $operaciones[] = $this->operacion($this->fecha_en_rango($inicio_mes, $dias_disponibles), 'devolucion', [
                    'monto'     => $parte['monto'],
                    'client_id' => $parte['client_id'],
                ]);

                $saldo_por_cliente_delta[$parte['client_id']] = ($saldo_por_cliente_delta[$parte['client_id']] ?? 0) - $parte['monto'];
            }
        }

        // --- Compras a proveedores, repartidas entre los proveedores ----------------------------
        $montos_compras = $this->repartir_en_registros($compras_total, self::CANT_REGISTROS);
        $compras_facturadas = 0;

        foreach ($montos_compras as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            $proveedor = $this->proveedor_rotativo($indice + $offset_rotacion);
            $renglon = $this->renglon_rotativo($monto);
            // Misma regla que las ventas: se factura la mitad, por paridad del índice.
            $facturar = ($indice % 2 === 0);

            if ($facturar) {
                $compras_facturadas++;
            }

            $operaciones[] = $this->operacion($this->fecha_en_rango($inicio_mes, $dias_disponibles), 'compra_a_proveedor', [
                'monto'       => $monto,
                'provider_id' => $proveedor->id,
                'articulo_id' => $renglon['articulo_id'],
                'unidades'    => $renglon['unidades'],
                'facturar'    => $facturar,
                // Comprobante de compra: base para iva_credito()/percepciones/retenciones. IVA al
                // 21% sobre el monto (alícuota general, no configurable acá -- esta semilla no
                // modela percepciones/retenciones reales, quedan en 0 a propósito).
                'importe_iva' => round($monto * (self::IVA_ALICUOTA / 100), 2),
            ]);

            $saldo_por_proveedor_delta[$proveedor->id] = ($saldo_por_proveedor_delta[$proveedor->id] ?? 0) + $monto;
        }

        // --- Pagos a proveedores, repartidos entre los 10 proveedores y por método de pago -----
        $montos_pagos_prov = $this->repartir_en_registros($pagos_proveedores_total, self::CANT_REGISTROS);

        foreach ($montos_pagos_prov as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            $proveedor = $this->proveedor_rotativo($indice + 1 + $offset_rotacion);
            // Los pagos a proveedor rotan entre efectivo, banco y Mercado Pago (no cheque acá: el
            // cheque emitido a proveedor es otro camino, `endosar()`, ya cubierto en el paso 4).
            $metodo_id = [3, 4, 6][$indice % 3];

            // Efectivo: se reparte entre las cuatro cajas de mostrador, con los mismos pesos con
            // que entra (ver REPARTO_SUCURSAL). Antes salía entero de la caja de la sucursal 1.
            $metodos_pago = ($metodo_id === 3)
                ? $this->metodos_de_efectivo_por_sucursal($monto)
                : [
                    [
                        'current_acount_payment_method_id' => $metodo_id,
                        'amount'                           => $monto,
                        'caja_id'                          => $this->semilla->caja_para($metodo_id, $primer_address_id),
                    ],
                ];

            // Fecha de cierre de mes, no una al azar dentro del mes: ver `$fecha_egresos_de_caja`.
            $operaciones[] = $this->operacion($fecha_egresos_de_caja, 'pago_a_proveedor', [
                'monto'       => $monto,
                'provider_id' => $proveedor->id,
                'metodos'     => $metodos_pago,
            ]);

            $saldo_por_proveedor_delta[$proveedor->id] = ($saldo_por_proveedor_delta[$proveedor->id] ?? 0) - $monto;

            // 🔴 Sale plata de caja de verdad: CurrentAcountPagoHelper::attachPaymentMethods()
            // llama CurrentAcountCajaHelper::guardar_pago(..., 'provider', ...), que registra un
            // EGRESO. Sin restar acá, la planilla ignoraba los pagos a proveedores por completo
            // (bug encontrado por el checker, 3/8/2026): "Sale de caja" del ejemplo del prompt
            // incluye explícitamente "4.000.000 a proveedores".
            $nombre_metodo_pago = self::CLAVE_POR_METODO[$metodo_id];
            $saldo_por_caja[$nombre_metodo_pago] = ($saldo_por_caja[$nombre_metodo_pago] ?? 0) - $monto;
            // 🔴 A propósito NO toca `$ingresos_por_caja`: esto es un EGRESO y un egreso nunca paga
            // comisión (ver el comentario de la declaración de esa variable).

            foreach ($metodos_pago as $parte) {
                $saldo_por_caja_id[$parte['caja_id']] = ($saldo_por_caja_id[$parte['caja_id']] ?? 0) - $parte['amount'];
            }
        }

        // --- Gastos operativos, repartidos entre los 4 registros --------------------------------
        $montos_gastos = $this->repartir_en_registros($gastos_total, self::CANT_REGISTROS);

        foreach ($montos_gastos as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            // Se sortea la fecha SIEMPRE, aunque el gasto pagado después la pise, para no alterar el
            // flujo del generador aleatorio según la paridad del índice (los gastos sin pagar
            // tienen que seguir cayendo en los mismos días que antes de este cambio).
            $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);
            // La mitad de los gastos se paga (mueve caja); la otra mitad queda cargada sin pagar
            // (pasa a formar parte de "deuda" del negocio, no de un cliente/proveedor puntual) --
            // variación deliberada para que no todos los gastos toquen caja.
            $metodos_gasto = [];

            if ($indice % 2 === 0) {
                // Se paga en efectivo repartido entre las cuatro cajas de mostrador (antes salía
                // entero de la sucursal 1) y a fin de mes, después de que entró la plata del mes:
                // las dos cosas juntas son las que sacan a estas cajas del rojo. Ver
                // `metodos_de_efectivo_por_sucursal()` y `$fecha_egresos_de_caja`.
                $metodos_gasto = $this->metodos_de_efectivo_por_sucursal($monto);
                $fecha = $fecha_egresos_de_caja;

                $saldo_por_caja['efectivo'] = ($saldo_por_caja['efectivo'] ?? 0) - $monto;

                foreach ($metodos_gasto as $parte) {
                    $saldo_por_caja_id[$parte['caja_id']] = ($saldo_por_caja_id[$parte['caja_id']] ?? 0) - $parte['amount'];
                }
            }

            $operaciones[] = $this->operacion($fecha, 'gasto', [
                'monto'   => $monto,
                'metodos' => $metodos_gasto,
            ]);
        }

        $control = [
            'meses_atras'          => $meses_atras,
            'mes'                  => $inicio_mes->format('Y-m'),
            'es_mes_actual'        => $es_mes_actual,
            'ventas_brutas'        => $ventas_brutas_mes,
            'cantidad_ventas'                  => $cantidad_mes,
            'cantidad_ventas_mostrador'        => $cant_mostrador,
            'cantidad_ventas_cuenta_corriente' => $cant_cc,
            'ventas_mostrador'     => $mostrador_total,
            'ventas_cuenta_corriente' => $ventas_cc_total,
            'devoluciones'         => $devoluciones_total,
            'ventas_netas'         => $ventas_netas,
            'costo'                => $costo_total,
            'resultado_bruto'      => $resultado_bruto,
            'gastos'               => $gastos_total,
            'resultado_operativo'  => $resultado_operativo,
            'cobranza_arrastrada'  => $cobranza_arrastrada,
            'cobranza_cuenta_corriente' => $cobranza_cc_total,
            'compras_proveedores'  => $compras_total,
            'pagos_proveedores'    => $pagos_proveedores_total,
            'ventas_facturadas'    => $ventas_facturadas,
            'compras_facturadas'   => $compras_facturadas,
            // La comisión NO se resta de `saldo_por_caja` ni se suma a `gastos`, a propósito:
            // `MovimientoCajaHelper::crear_gasto_comision()` crea el Expense con `caja_id => null`
            // y sin movimiento de caja (el saldo sigue siendo el bruto), y
            // `ContabilidadRepository::query_gastos()` excluye por id los gastos referenciados
            // desde `movimiento_cajas.comision_expense_id`. Va como renglón informativo aparte.
            //
            // Se calcula sobre los INGRESOS del mes (no sobre el saldo neto) y CON IVA, que es lo
            // que informa `ContabilidadRepository::comisiones_de_cobro()`. Ver `comision_estimada()`.
            'comision_mercado_pago' => $this->comision_estimada(6, $ingresos_por_caja),
            'entra_a_caja'         => $entra_a_caja_total,
            'iibb_estimado'        => $iibb_estimado,
            'saldo_por_caja'       => $saldo_por_caja,
            'delta_deuda_por_cliente'   => $saldo_por_cliente_delta,
            'delta_deuda_por_proveedor' => $saldo_por_proveedor_delta,
        ];

        // `saldo_por_caja_id` va FUERA de `$control` a propósito: es un dato de trabajo para el
        // barrido de efectivo de `handle()` (que necesita el saldo de cada caja física), no un
        // renglón que Lucas vaya a verificar contra un reporte. La planilla sigue mostrando el
        // desglose por MÉTODO, que es como se lee un reporte de caja.
        return [
            'operaciones'       => $operaciones,
            'control'           => $control,
            'saldo_por_caja_id' => $saldo_por_caja_id,
        ];
    }

    /**
     * Los baldes de plata del mostrador del mes: cuánto entra a cada caja, en el orden en que se
     * van a ir llenando las ventas.
     *
     * El efectivo absorbe el redondeo (y también el 10% que le tocaría al cheque, ver el comentario
     * de `planificar_mes()`), así que la suma de los baldes es EXACTAMENTE
     * `(int) round($mostrador_total)`. Ese "exactamente" es el que hace verificable el desglose por
     * caja: si un balde se calculara con `round()` por separado, el mes cerraría con uno o dos
     * pesos de diferencia contra la planilla.
     *
     * @param float $mostrador_total
     * @return array<int,array<string,mixed>>
     */
    protected function baldes_de_mostrador($mostrador_total)
    {
        $total = (int) round($mostrador_total);

        $mercado_pago = (int) round($mostrador_total * self::MEZCLA_COBRO[6]);
        $banco = (int) round($mostrador_total * self::MEZCLA_COBRO[4]);
        $efectivo = $total - $mercado_pago - $banco;

        $sucursales = $this->addresses->values();
        $cantidad = $sucursales->count();
        $pesos = $this->pesos_por_sucursal($cantidad);

        $baldes = [];
        $repartido = 0;

        foreach ($sucursales as $indice => $address) {
            if ($indice === $cantidad - 1) {
                $monto = $efectivo - $repartido; // La última absorbe el resto.
            } else {
                $monto = (int) round($efectivo * $pesos[$indice]);
                $repartido += $monto;
            }

            $baldes[] = [
                'clave'      => 'efectivo',
                'metodo_id'  => 3,
                'address_id' => $address->id,
                'caja_id'    => $this->semilla->caja_para(3, $address->id),
                'monto'      => $monto,
            ];
        }

        $primer_address_id = $sucursales->first()->id;

        // Cajas compartidas: no están atadas a un local, pero `caja_para()` igual necesita una
        // sucursal porque `CajaSeeder` crea una fila de `DefaultPaymentMethodCaja` por sucursal
        // apuntando todas al mismo caja_id (ver su PHPDoc). Cualquiera da el mismo resultado.
        $baldes[] = [
            'clave'      => 'mercado_pago',
            'metodo_id'  => 6,
            'address_id' => null,
            'caja_id'    => $this->semilla->caja_para(6, $primer_address_id),
            'monto'      => $mercado_pago,
        ];

        $baldes[] = [
            'clave'      => 'banco',
            'metodo_id'  => 4,
            'address_id' => null,
            'caja_id'    => $this->semilla->caja_para(4, $primer_address_id),
            'monto'      => $banco,
        ];

        return $baldes;
    }

    /**
     * Arma las filas del array `metodos` de una operación en EFECTIVO, repartiendo el monto entre
     * las cajas de mostrador de todas las sucursales con los pesos de `REPARTO_SUCURSAL`.
     *
     * 🔴 Para qué existe: los ingresos de mostrador ya se repartían por sucursal
     * (`baldes_de_mostrador()`), pero la cobranza de cuenta corriente en efectivo, los gastos
     * pagados y los pagos a proveedor en efectivo salían TODOS de `caja_para(3, $primer_address_id)`.
     * La sucursal 1 cobraba el 40% del mostrador y pagaba el 100% de los egresos, y su caja quedaba
     * en negativo todos los meses (−428.000 en el mes de 10.700.000, medido sobre la aritmética del
     * guion). Una caja de mostrador en rojo se ve apenas se abre la pantalla de cajas, sin abrir un
     * solo reporte.
     *
     * Repartir con los MISMOS pesos de los dos lados convierte a cada caja en una copia a escala
     * del flujo de efectivo del comercio entero: `saldo(sucursal s) = peso_s × saldo(agregado)`. Si
     * el agregado nunca es negativo, ninguna de las cuatro lo es.
     *
     * Se devuelven varias filas para UNA sola operación (no varias operaciones), que es lo que ya
     * hace el mostrador cuando una venta cae entre dos baldes: `PaymentMethodHelper::attach_payment_methods()`
     * hace un `attach()` por fila -- sin `sync` ni deduplicación --, y tanto
     * `CurrentAcountPagoHelper::attachPaymentMethods()` como `SemillaHelper::gasto()` recorren
     * `current_acount_payment_methods` y llaman al helper de caja UNA VEZ POR FILA DEL PIVOT. O sea
     * que cuatro filas del método 3 con cuatro `caja_id` distintos generan cuatro movimientos de
     * caja, uno en cada sucursal.
     *
     * La suma de las partes es EXACTAMENTE `$monto` (la última absorbe el redondeo, mismo criterio
     * que `baldes_de_mostrador()`): si cada parte se redondeara por su cuenta, el desglose por caja
     * de la planilla se iría uno o dos centavos contra la base y el test 3 de `4_Semilla_Test.php`
     * -- que compara caja por caja con un delta de 0,01 -- empezaría a titilar.
     *
     * Las partes se agrupan POR CAJA antes de devolverlas, no por sucursal: si varias sucursales
     * comparten la misma caja de efectivo, sus partes se suman en una sola fila. No es una
     * optimización cosmética -- en una instalación sin caja de efectivo por sucursal (y en el
     * fixture de `4_Semilla_Test.php`, que arma UNA caja compartida entre las cuatro) el reparto no
     * tiene ningún sentido físico, y cuatro movimientos consecutivos en la misma caja por el mismo
     * concepto son ruido en la pantalla de caja y trabajo de más en `set_apertura_caja_ingresos_egresos()`,
     * que recorre todos los movimientos de la apertura en cada movimiento nuevo. Agrupando, el
     * comportamiento en ese caso queda idéntico al de antes de este cambio.
     *
     * @param float $monto
     * @return array<int,array<string,mixed>>
     */
    protected function metodos_de_efectivo_por_sucursal($monto)
    {
        $sucursales = $this->addresses->values();
        $cantidad = $sucursales->count();
        $pesos = $this->pesos_por_sucursal($cantidad);

        $por_caja = [];
        $repartido = 0.0;

        foreach ($sucursales as $indice => $address) {
            if ($indice === $cantidad - 1) {
                $parte = round($monto - $repartido, 2);
            } else {
                $parte = round($monto * $pesos[$indice], 2);
                $repartido = round($repartido + $parte, 2);
            }

            // Una sucursal con peso 0 (hay más de 4 y `pesos_por_sucursal()` las completa con ceros)
            // no aporta ninguna fila: un movimiento de caja de 0 es ruido en la pantalla.
            if ($parte <= 0) {
                continue;
            }

            $caja_id = $this->semilla->caja_para(3, $address->id);

            $por_caja[$caja_id] = round((isset($por_caja[$caja_id]) ? $por_caja[$caja_id] : 0) + $parte, 2);
        }

        $metodos = [];

        foreach ($por_caja as $caja_id => $parte) {
            $metodos[] = [
                'current_acount_payment_method_id' => 3,
                'amount'                           => $parte,
                'caja_id'                          => $caja_id,
            ];
        }

        return $metodos;
    }

    /**
     * `REPARTO_SUCURSAL` renormalizado a la cantidad de sucursales que realmente tiene el usuario.
     *
     * Sin esto, con menos de 4 sucursales la semilla siembra MENOS plata de la que dice la planilla
     * (con 2 sucursales se repartiría el 70% del efectivo y el 30% restante desaparecería), y el
     * reporte deja de dar lo que promete. Pasa de verdad: en una demo con depósitos marcados,
     * `crear_depositos()` crea 1 a 3 sucursales antes del `AddressSeeder`, y el guard de ese seeder
     * hace que no complete hasta 4.
     *
     * Con más de 4 sucursales las de más entran con peso 0: no facturan mostrador en efectivo, pero
     * tampoco se pierde plata.
     *
     * @param int $cantidad
     * @return array<int,float>
     */
    protected function pesos_por_sucursal($cantidad)
    {
        $pesos = array_slice(self::REPARTO_SUCURSAL, 0, min($cantidad, count(self::REPARTO_SUCURSAL)));

        while (count($pesos) < $cantidad) {
            $pesos[] = 0.0;
        }

        $suma = array_sum($pesos);

        foreach ($pesos as $indice => $peso) {
            $pesos[$indice] = $peso / $suma;
        }

        return $pesos;
    }

    /**
     * Reparte un monto en exactamente $cantidad partes de pesos ENTEROS cuya suma es el monto.
     *
     * En pesos enteros y con el resto repartido de a un peso, porque un `round()` por parte deja
     * centavos sueltos y el reporte deja de coincidir con la planilla de control -- que es lo único
     * que hace verificable a esta semilla.
     *
     * @param float $monto_total
     * @param int $cantidad
     * @return array<int,int>
     */
    protected function repartir_monto_en_ventas($monto_total, $cantidad)
    {
        $cantidad = max(1, (int) $cantidad);
        $total = (int) round($monto_total);

        $base = intdiv($total, $cantidad);
        $resto = $total - ($base * $cantidad);

        $partes = [];

        for ($indice = 0; $indice < $cantidad; $indice++) {
            $partes[] = $indice < $resto ? $base + 1 : $base;
        }

        return $partes;
    }

    /**
     * Asigna los métodos de pago de las ventas de mostrador llenando baldes en orden.
     *
     * Se llena balde por balde y no se sortea: es la única forma de que la suma por caja del mes sea
     * EXACTAMENTE la fracción que dice `MEZCLA_COBRO`, sin importar cuántas ventas tenga el mes. Como
     * efecto, la venta que cae en el límite entre dos baldes queda con pago mixto (mitad efectivo,
     * mitad transferencia), que es un caso realista y además el único que ejercita una venta con más
     * de un método de pago. Son a lo sumo `cantidad_de_baldes - 1` ventas por mes.
     *
     * Invariante que lo hace exacto: `array_sum($montos_venta) === array_sum(columna monto de $baldes)`.
     * Las dos cosas son particiones exactas del mismo entero.
     *
     * @param array<int,int> $montos_venta
     * @param array<int,array<string,mixed>> $baldes
     * @return array<int,array<int,array<string,mixed>>>
     */
    protected function repartir_metodos_en_ventas($montos_venta, $baldes)
    {
        $resultado = [];

        $cantidad_baldes = count($baldes);
        $indice_balde = 0;
        $restante = $cantidad_baldes > 0 ? (int) $baldes[0]['monto'] : 0;

        foreach ($montos_venta as $indice => $monto_venta) {
            $resultado[$indice] = [];
            $pendiente = (int) $monto_venta;

            while ($pendiente > 0 && $indice_balde < $cantidad_baldes) {
                if ($restante <= 0) {
                    $indice_balde++;
                    $restante = $indice_balde < $cantidad_baldes ? (int) $baldes[$indice_balde]['monto'] : 0;
                    continue;
                }

                $toma = min($pendiente, $restante);

                $parte = $baldes[$indice_balde];
                $parte['monto'] = $toma;
                $resultado[$indice][] = $parte;

                $pendiente -= $toma;
                $restante -= $toma;
            }
        }

        return $resultado;
    }

    /**
     * Artículo y unidades que le tocan al próximo renglón.
     *
     * 🔴 Si el monto no es múltiplo exacto de las unidades, cae a 1 unidad. No es cosmético:
     * `article_sale.cost` es `decimal(25,2)`, así que un costo unitario de `monto / unidades / 2` con
     * más de dos decimales se trunca al guardarse, y `SUM(cost * amount)` -- que es como
     * `ContabilidadRepository::costo_mercaderia_vendida()` calcula el costo -- se aparta de
     * `monto / 2` hasta medio centavo por unidad. Sobre las 523 ventas del año la desviación se pasa
     * del DELTA de 0,01 del test 1 y el ancla "resultado bruto = mitad de las ventas netas" se cae.
     *
     * @param float|int $monto Monto del renglón, para verificar que las unidades lo dividan exacto.
     * @return array{articulo_id: int, unidades: int}
     */
    protected function renglon_rotativo($monto)
    {
        $articulo = $this->articulos->get($this->contador_renglones % $this->articulos->count());
        $unidades = self::UNIDADES_POR_VENTA[$this->contador_renglones % count(self::UNIDADES_POR_VENTA)];

        $this->contador_renglones++;

        $entero = (int) round($monto);

        if (abs((float) $monto - $entero) > 0.00001 || $entero % $unidades !== 0) {
            $unidades = 1;
        }

        return ['articulo_id' => $articulo->id, 'unidades' => $unidades];
    }

    /**
     * Letra y tipo de comprobante que le corresponde a una venta según el cliente.
     *
     * `afip_tickets` NO tiene una FK a un catálogo de tipos de comprobante: son dos columnas string
     * sueltas (`cbte_letra`, `cbte_tipo`) que en el camino real escribe `AfipWsfeHelper` con lo que
     * contesta AFIP. Acá las escribe el guion, para que la pantalla de comprobantes y el PDF no
     * muestren la letra vacía.
     *
     * @param \App\Models\Client|null $cliente
     * @return array{cbte_letra: string, cbte_tipo: string}
     */
    protected function comprobante_para($cliente)
    {
        if (!is_null($cliente) && (int) $cliente->iva_condition_id === 1) {
            return ['cbte_letra' => 'A', 'cbte_tipo' => '1'];
        }

        return ['cbte_letra' => 'B', 'cbte_tipo' => '6'];
    }

    /**
     * Comisión que le va a cobrar la caja de un método de pago sobre lo que ENTRÓ por él este mes,
     * IVA incluido -- o sea, exactamente el número que después va a mostrar el reporte.
     *
     * Tres correspondencias con el código de producción, cada una verificada en su archivo:
     *
     * 1) La base son los INGRESOS del mes, no el saldo neto de la caja.
     *    `MovimientoCajaHelper::crear_movimiento()` arma `$aplica_liquidacion` exigiendo
     *    `$data['ingreso'] > 0` (línea 37): un egreso no liquida y no genera comisión. Como el
     *    guion paga proveedores por Mercado Pago, calcular la comisión sobre el saldo (ingresos
     *    menos esos pagos) la informaba muy de menos.
     *
     * 2) El número lleva IVA. `crear_gasto_comision()` (MovimientoCajaHelper:165-171) crea el
     *    `Expense` con `amount = comision + calcular_iva_comision(...)` cuando la comisión está
     *    configurada NETA -- y neta es lo que hay: `CajaSeeder` no lista `comision_iva_incluido`
     *    en el insert justamente para que quede el default de columna (0 = neta, alícuota 21).
     *    `ContabilidadRepository::comisiones_de_cobro()` suma `expenses.amount`, así que el
     *    reporte muestra la comisión CON IVA. Si mañana alguien configura la caja con IVA
     *    incluido, `crear_gasto_comision()` deja `amount = comision` pelada y este método también:
     *    por eso se pregunta por la configuración en vez de multiplicar por 1,21 a mano.
     *
     * 3) Sin `expense_concept_id` no hay `Expense` (MovimientoCajaHelper:159-163 solo loguea un
     *    warning), y entonces el reporte informa CERO por más que el movimiento tenga su
     *    `comision_calculada`. La planilla tiene que decir lo mismo que el reporte, incluso
     *    cuando el reporte dice cero.
     *
     * La configuración se lee con los mismos dos métodos del camino real
     * (`CajaLiquidacionHelper::tiene_configuracion()` para decidir si corresponde,
     * `resolve_config()` para los valores, con su cascada de overrides por método de pago), nunca
     * del 5% hardcodeado ni leyendo las columnas de la caja a mano: una demo con otra
     * configuración tiene que ver su propio número.
     *
     * Única aproximación que queda: acá se redondea una vez sobre el total del mes y en producción
     * se redondea movimiento por movimiento, así que sobre las ~150 operaciones de un mes puede
     * haber unos centavos de diferencia.
     *
     * @param int $metodo_id
     * @param array<string,float> $ingresos_por_caja Ingresos del mes, por clave de la planilla.
     * @return float
     */
    protected function comision_estimada($metodo_id, $ingresos_por_caja)
    {
        $clave = self::CLAVE_POR_METODO[$metodo_id];
        $ingresos = isset($ingresos_por_caja[$clave]) ? (float) $ingresos_por_caja[$clave] : 0.0;

        if ($ingresos <= 0) {
            return 0.0;
        }

        $caja = Caja::find($this->semilla->caja_para($metodo_id, $this->addresses->first()->id));

        if (is_null($caja) || !CajaLiquidacionHelper::tiene_configuracion($caja, $metodo_id)) {
            return 0.0;
        }

        $config = CajaLiquidacionHelper::resolve_config($caja, $metodo_id);

        if (is_null($config['comision_porcentaje']) || (float) $config['comision_porcentaje'] <= 0) {
            return 0.0;
        }

        if (is_null($config['expense_concept_id'])) {
            return 0.0;
        }

        $comision = round($ingresos * ((float) $config['comision_porcentaje'] / 100), 2);

        if (!empty($config['comision_iva_incluido'])) {
            return $comision;
        }

        $iva = CajaLiquidacionHelper::calcular_iva_comision($comision, $config['comision_iva_alicuota'], false);

        return round($comision + $iva, 2);
    }

    /**
     * Reparte un total entre `$cantidad` partes con la variación aleatoria de
     * `ReportesMesSeeder::distribuir()`, garantizando que ninguna parte salga NEGATIVA.
     *
     * 🔴 El problema: `distribuir()` sortea un factor en [0,60; 1,40] para las primeras `n-1`
     * partes y le da a la última la DIFERENCIA contra el total. Si los tres primeros factores suman
     * más de 4,0, la última parte sale NEGATIVA -- y como los cinco loops que la consumen la
     * saltean (`if ($monto <= 0) continue;`), lo que queda sembrado son las tres partes positivas,
     * o sea MÁS plata que el total que informa la planilla. Peor caso medido con los tres factores
     * en 1,40 sobre las devoluciones del mes actual (1.490.000): partes
     * [521.500, 521.500, 521.500, −74.500], se siembran 1.564.500 y la planilla sigue diciendo
     * 1.490.000. Son 1.540 de las 531.441 combinaciones de `mt_rand(60, 140)` = 0,29% por llamada;
     * con ~45 llamadas por corrida, 12% de las corridas pegan al menos una. Y con
     * `semilla.semilla_aleatoria` fija es determinista: o le pega siempre o nunca.
     *
     * De las dos salidas posibles se elige NORMALIZAR el reparto, y no informar en el control lo
     * efectivamente sembrado, porque el daño no es solo de la planilla: en el ejemplo de arriba el
     * mes siembra 1.564.500 de devoluciones en vez del 10% de las brutas que promete
     * `DEVOLUCIONES_FRACCION`, y el ancla "ventas_netas = 90% de ventas_brutas" se cae igual -- esa
     * la mide `4_Semilla_Test.php::ventas_netas_son_el_noventa_por_ciento_de_las_brutas()`
     * comparando el REPORTE contra sí mismo, sin mirar la planilla, así que un control honesto no
     * la salva. Normalizar arregla las dos cosas de una.
     *
     * La normalización cae a `repartir_monto_en_ventas()`, el reparto exacto en partes iguales que
     * este mismo archivo ya usa para las ventas: suma exactamente el mismo entero, nunca da una
     * parte negativa y -- clave -- no consume ni un `mt_rand()` de más, porque `distribuir()` ya
     * sorteó los suyos antes de que miremos el resultado. O sea que el resto de la corrida sigue
     * siendo bit a bit la misma. Se pierde la variación entre partes en esa única llamada de cada
     * ~400, que es un precio barato.
     *
     * El arreglo va del lado del consumidor porque `ReportesMesSeeder` está fuera de alcance (lo
     * comparten los seeders viejos).
     *
     * @param float|int $total
     * @param int $cantidad
     * @return array<int,int>
     */
    protected function repartir_en_registros($total, $cantidad)
    {
        $total = (int) $total;

        $partes = ReportesMesSeeder::distribuir($total, $cantidad);

        foreach ($partes as $parte) {
            if ($parte < 0) {
                return $this->repartir_monto_en_ventas($total, $cantidad);
            }
        }

        return $partes;
    }

    /**
     * Barrido de efectivo del cierre de mes: parte del efectivo de cada sucursal viaja a la Caja
     * Fuerte, y parte de la Caja Fuerte sigue al Banco Nación.
     *
     * 🔴 Por qué existe: `MEZCLA_COBRO` usa los métodos 3/4/6/1 y el método 4 pasó a la caja
     * "Transferencias" (ver `CajaSeeder`), así que "Banco Nación" y "Caja Fuerte" -- dos cajas que
     * Lucas pidió por nombre -- terminaban la corrida con saldo 0 y ~350 aperturas vacías. En una
     * demo se ven como cajas muertas. La plata llega por el camino real
     * (`MovimientoEntreCajaHelper`, que es como se mueve plata entre cajas en producción) y NO
     * metiéndolas en `MEZCLA_COBRO`, que le cambiaría la aritmética a lo que verifica
     * `4_Semilla_Test.php`. Por el mismo motivo la caja "Tarjetas" queda como está: nadie la pidió.
     *
     * 🔴 Se saca un PORCENTAJE del saldo acumulado de cada caja, nunca un monto fijo: con un monto
     * fijo, los primeros meses de la rampa (4 ventas, 400.000 brutas) dejarían la caja de la
     * sucursal en negativo. Y si el saldo planificado de una caja viniera en cero o en negativo, esa
     * caja no barre nada ese mes (el `continue` de abajo).
     *
     * Ese `continue` es una red, no la defensa principal. La garantía de que ninguna caja de
     * efectivo pasa por rojo la dan `metodos_de_efectivo_por_sucursal()` (reparte ingresos y
     * egresos con los mismos pesos) y `$fecha_egresos_de_caja` (los egresos van al cierre del mes,
     * después de que entró todo el efectivo). Con esas dos, el saldo al momento del barrido es el
     * saldo de cierre del mes, que es positivo, y sacarle el 60% lo deja en el 40% -- también
     * positivo. Por inducción, el mes siguiente arranca en positivo y la cuenta se repite.
     *
     * Las dos operaciones se planifican con `operacion()`, o sea que entran al mismo flujo
     * cronológico que el resto y cuelgan de la apertura del día que corresponde. El
     * `orden_operacion` creciente garantiza además que el tramo Caja Fuerte -> Banco se ejecute
     * DESPUÉS de los cuatro tramos que la llenaron, aunque compartan el mismo instante.
     *
     * @param \Carbon\Carbon $fin_de_mes Último día del mes que cierra. El barrido va a las 20:30:
     *                                    después de la última operación posible del día
     *                                    (`fecha_en_rango()` nunca pasa de las 19:59) y antes del
     *                                    cierre de cajas de las 22:00.
     * @param array<int,float> $saldo_por_caja_id Saldo planificado hasta acá, por caja. Se ACTUALIZA
     *                                             con lo que mueve este barrido (por referencia).
     * @return array<int,array<string,mixed>>
     */
    protected function planificar_barrido_de_efectivo($fin_de_mes, &$saldo_por_caja_id)
    {
        $cajas = $this->cajas_del_barrido();

        if (is_null($cajas['caja_fuerte']) || is_null($cajas['banco'])) {
            return [];
        }

        $operaciones = [];
        $fecha = $fin_de_mes->copy()->setTime(20, 30);
        $caja_fuerte_id = $cajas['caja_fuerte'];
        $a_caja_fuerte = 0;

        foreach ($this->addresses as $address) {
            $caja_id = $this->semilla->caja_para(3, $address->id);
            $saldo = isset($saldo_por_caja_id[$caja_id]) ? (float) $saldo_por_caja_id[$caja_id] : 0.0;
            $monto = (int) round($saldo * self::BARRIDO_A_CAJA_FUERTE_FRACCION);

            if ($monto <= 0) {
                continue;
            }

            $operaciones[] = $this->operacion($fecha, 'movimiento_entre_cajas', [
                'from_caja_id' => $caja_id,
                'to_caja_id'   => $caja_fuerte_id,
                'monto'        => $monto,
            ]);

            $saldo_por_caja_id[$caja_id] = $saldo - $monto;
            $saldo_por_caja_id[$caja_fuerte_id] = (isset($saldo_por_caja_id[$caja_fuerte_id]) ? $saldo_por_caja_id[$caja_fuerte_id] : 0) + $monto;
            $a_caja_fuerte += $monto;
        }

        $saldo_caja_fuerte = isset($saldo_por_caja_id[$caja_fuerte_id]) ? (float) $saldo_por_caja_id[$caja_fuerte_id] : 0.0;
        $a_banco = (int) round($saldo_caja_fuerte * self::BARRIDO_A_BANCO_FRACCION);

        if ($a_banco > 0) {
            $operaciones[] = $this->operacion($fecha, 'movimiento_entre_cajas', [
                'from_caja_id' => $caja_fuerte_id,
                'to_caja_id'   => $cajas['banco'],
                'monto'        => $a_banco,
            ]);

            $saldo_por_caja_id[$caja_fuerte_id] = $saldo_caja_fuerte - $a_banco;
            $saldo_por_caja_id[$cajas['banco']] = (isset($saldo_por_caja_id[$cajas['banco']]) ? $saldo_por_caja_id[$cajas['banco']] : 0) + $a_banco;
        } else {
            $a_banco = 0;
        }

        $this->resumen_barrido['movimientos'] += count($operaciones);
        $this->resumen_barrido['de_efectivo_a_caja_fuerte'] += $a_caja_fuerte;
        $this->resumen_barrido['de_caja_fuerte_a_banco'] += $a_banco;

        return $operaciones;
    }

    /**
     * Los `caja_id` de "Caja Fuerte" y "Banco Nación", resueltos por NOMBRE y memoizados.
     *
     * Por nombre, y no con `caja_para()` como todo el resto del comando, porque estas dos son
     * justamente las cajas que NO son el default de ningún método de pago -- ese es el motivo por
     * el que quedaban sin un solo movimiento, y no hay otro identificador estable para agarrarlas.
     * Los nombres son los que escribe `CajaSeeder::una_caja_para_cada_direccion_y_por_cada_metodo_de_pago()`.
     *
     * Si la instalación no las tiene (otro `FOR_USER`, o la base de testing, que arma sus propias
     * cajas) no se barre nada y se avisa: esto es una mejora de cómo se ve la demo, no una
     * precondición de la aritmética.
     *
     * @return array{caja_fuerte: int|null, banco: int|null}
     */
    protected function cajas_del_barrido()
    {
        if (!is_null($this->memo_cajas_del_barrido)) {
            return $this->memo_cajas_del_barrido;
        }

        $caja_fuerte = Caja::where('user_id', $this->user_id)->where('name', self::CAJA_FUERTE_NOMBRE)->first();
        $banco = Caja::where('user_id', $this->user_id)->where('name', self::CAJA_BANCO_NOMBRE)->first();

        if (is_null($caja_fuerte) || is_null($banco)) {
            $this->avisar('No existen las cajas "'.self::CAJA_FUERTE_NOMBRE.'" y/o "'.self::CAJA_BANCO_NOMBRE
                .'" para el usuario '.$this->user_id.': no se siembra el barrido de efectivo y esas cajas van a quedar sin movimientos.');
        }

        $this->memo_cajas_del_barrido = [
            'caja_fuerte' => is_null($caja_fuerte) ? null : (int) $caja_fuerte->id,
            'banco'       => is_null($banco) ? null : (int) $banco->id,
        ];

        return $this->memo_cajas_del_barrido;
    }

    /**
     * Envuelve una operación planificada con su fecha y su orden de desempate.
     *
     * @param \Carbon\Carbon $fecha
     * @param string $tipo
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    protected function operacion($fecha, $tipo, $datos)
    {
        $this->orden_operacion++;

        return [
            'fecha' => $fecha,
            'orden' => $this->orden_operacion,
            'tipo'  => $tipo,
            'datos' => $datos,
        ];
    }

    /**
     * Ejecuta las operaciones planificadas en ORDEN CRONOLÓGICO ESTRICTO, abriendo la caja de cada
     * día antes de sus movimientos y cerrándola después.
     *
     * 🔴 Por qué el día por día y no ejecutar mes a mes de corrido (pedido 8):
     * `MovimientoCaja.apertura_caja_id` es NOT NULL y
     * `MovimientoCajaHelper::get_current_aperutra_caja()` resuelve la ÚLTIMA `AperturaCaja` de esa
     * caja por `created_at DESC` -- no acepta que le pasen una desde afuera, y los tres helpers de
     * caja del camino real (`SaleCajaHelper`, `CurrentAcountCajaHelper`, `ExpenseCajaHelper`) arman
     * el `$data` puertas adentro. Con una sola apertura por caja, los ~1.500 movimientos del año
     * quedarían colgados todos de ella. La única forma de arreglarlo sin tocar un helper de
     * producción es mover el reloj (que es lo que ya hace toda la clase `SemillaHelper`) y crear la
     * apertura del día antes de los movimientos de ese día.
     *
     * Efecto secundario bueno: `set_apertura_caja_ingresos_egresos()` recorre TODOS los movimientos
     * de la apertura en CADA movimiento nuevo. Con una apertura única eso es O(n²) sobre 1.500
     * movimientos; con apertura diaria pasa a recorrer solo los del día.
     *
     * @param array<int,array<string,mixed>> $operaciones
     * @param \Carbon\Carbon|null $ultimo_dia Hasta qué día llega el loop de aperturas. Null = hasta
     *                                         el día de la última operación.
     * @return void
     */
    protected function ejecutar_operaciones($operaciones, $ultimo_dia = null)
    {
        if (empty($operaciones)) {
            return;
        }

        // Desempate por el orden de planificación: dos operaciones del mismo instante tienen que
        // ejecutarse siempre en el mismo orden, o `MovimientoCajaHelper::set_saldos()` (que lleva un
        // saldo corriente) deja los movimientos con saldos distintos entre dos corridas iguales.
        usort($operaciones, function ($una, $otra) {
            $diferencia = $una['fecha']->getTimestamp() - $otra['fecha']->getTimestamp();

            if ($diferencia !== 0) {
                return $diferencia < 0 ? -1 : 1;
            }

            return $una['orden'] - $otra['orden'];
        });

        $por_dia = [];

        foreach ($operaciones as $operacion) {
            $por_dia[$operacion['fecha']->format('Y-m-d')][] = $operacion;
        }

        $cajas = Caja::where('user_id', $this->user_id)->orderBy('id')->get();

        $primer_dia = $operaciones[0]['fecha']->copy()->startOfDay();
        $ultima_operacion = $operaciones[count($operaciones) - 1]['fecha']->copy()->startOfDay();

        $fin = $ultima_operacion;

        if (!is_null($ultimo_dia) && $ultimo_dia->copy()->startOfDay()->greaterThan($fin)) {
            $fin = $ultimo_dia->copy()->startOfDay();
        }

        $this->preparar_aperturas_previas($cajas, $primer_dia);

        $dias = 0;
        $dia = $primer_dia->copy();

        while ($dia->lessThanOrEqualTo($fin)) {
            $this->abrir_cajas_del_dia($cajas, $dia);

            $clave = $dia->format('Y-m-d');

            if (isset($por_dia[$clave])) {
                foreach ($por_dia[$clave] as $operacion) {
                    $this->ejecutar_operacion($operacion);
                }
            }

            // 🔴 El último día queda ABIERTO: `sembrar_hoy()`, el ciclo de cheques y la actividad de
            // tienda corren después con el reloj real, y una demo que arranca con las 10 cajas
            // cerradas no puede vender nada hasta que alguien las abra a mano.
            if ($dia->lessThan($fin)) {
                $this->cerrar_cajas_del_dia($cajas, $dia);
            }

            $dias++;
            $dia = $dia->copy()->addDay();
        }

        $sembradas = $dias * $cajas->count();

        // 🔴 `total` NO es `dias * cajas`: `preparar_aperturas_previas()` no BORRA las aperturas que
        // ya estaban (las de `CajaSeeder`, una por caja), solo les mueve el `created_at` al día
        // anterior al primer día sembrado. Siguen en la base, así que Lucas cuenta más filas de las
        // que decía este renglón. Se informan las tres cosas por separado para que la diferencia se
        // pueda leer sin ir al código.
        $this->resumen_aperturas = [
            'dias'      => $dias,
            'cajas'     => $cajas->count(),
            'sembradas' => $sembradas,
            'previas'   => $this->aperturas_previas,
            'total'     => $sembradas + $this->aperturas_previas,
        ];

        $this->avisar_stock_negativo();

        $this->avisar_caja_en_rojo();
    }

    /**
     * Ejecuta UNA operación planificada por el camino real de `SemillaHelper`.
     *
     * @param array<string,mixed> $operacion
     * @return void
     */
    protected function ejecutar_operacion($operacion)
    {
        $fecha = $operacion['fecha'];
        $datos = $operacion['datos'];

        switch ($operacion['tipo']) {

            case 'venta_mostrador':
                $sale = $this->semilla->venta_mostrador(
                    $fecha,
                    $datos['monto'],
                    $datos['address_id'],
                    $datos['metodos'],
                    $datos['articulo_id'],
                    $datos['unidades']
                );
                // El comprobante va en la misma operación y con la misma fecha que la venta:
                // `iva_debito()` filtra por `afip_fecha_emision`, no por el `created_at` de la venta.
                if ($datos['facturar']) {
                    $this->semilla->comprobante_de_venta($fecha, $sale->id, $datos['importe_iva'], $datos['cbte_letra'], $datos['cbte_tipo']);
                }
                break;

            case 'venta_cuenta_corriente':
                $sale = $this->semilla->venta_cuenta_corriente(
                    $fecha,
                    $datos['monto'],
                    $datos['client_id'],
                    $datos['address_id'],
                    $datos['articulo_id'],
                    $datos['unidades']
                );
                if ($datos['facturar']) {
                    $this->semilla->comprobante_de_venta($fecha, $sale->id, $datos['importe_iva'], $datos['cbte_letra'], $datos['cbte_tipo']);
                }
                break;

            case 'cobro_cuenta_corriente':
                $cobro = $this->semilla->cobro_cuenta_corriente($fecha, $datos['monto'], $datos['client_id'], $datos['metodos']);

                if (!empty($datos['registrar_cheque'])) {
                    $this->registrar_cheque_recibido_de($cobro);
                }
                break;

            case 'devolucion':
                $this->semilla->devolucion($fecha, $datos['monto'], $datos['client_id']);
                break;

            case 'compra_a_proveedor':
                $order = $this->semilla->compra_a_proveedor(
                    $fecha,
                    $datos['monto'],
                    $datos['provider_id'],
                    $datos['articulo_id'],
                    $datos['unidades']
                );
                if ($datos['facturar']) {
                    $this->semilla->comprobante_de_compra($fecha, $order->id, ['total_iva' => $datos['importe_iva']]);
                }
                break;

            case 'pago_a_proveedor':
                $this->semilla->pago_a_proveedor($fecha, $datos['monto'], $datos['provider_id'], $datos['metodos']);
                break;

            case 'gasto':
                $this->semilla->gasto($fecha, $datos['monto'], $this->expense_concept_id, $datos['metodos']);
                break;

            case 'movimiento_entre_cajas':
                $this->ejecutar_movimiento_entre_cajas(
                    $fecha,
                    $datos['from_caja_id'],
                    $datos['to_caja_id'],
                    $datos['monto']
                );
                break;

            default:
                throw new \Exception('semilla:datos: tipo de operación desconocido "'.$operacion['tipo'].'".');
        }
    }

    /**
     * Mueve plata de una caja a otra por el camino real: `MovimientoEntreCaja::create()` +
     * `MovimientoEntreCajaHelper::mover()`, que es exactamente lo que hace
     * `MovimientoEntreCajaController::store()`. El helper crea los DOS movimientos de caja (egreso
     * en la de origen, ingreso en la de destino) con el concepto 5, "Movimiento entre Cajas" de
     * `ConceptoMovimientoCajaSeeder`, así que los saldos de las dos cajas quedan consistentes sin
     * armar un `MovimientoCaja` a mano.
     *
     * No está en `SemillaHelper` como el resto de las primitivas porque `SemillaHelper` está fuera
     * de alcance de este arreglo; si mañana hace falta mover plata entre cajas desde otro lado,
     * este método es el candidato obvio a mudarse allá.
     *
     * El reloj se mueve igual que en las primitivas de `SemillaHelper`, y se restaura en un
     * `finally`: `MovimientoCajaHelper::crear_movimiento()` no acepta una fecha, fecha con
     * `Carbon::now()`, y sin esto los dos movimientos colgarían de la apertura de HOY en vez de la
     * del día del barrido -- justo el invariante que el pedido 8 vino a arreglar.
     *
     * @param \Carbon\Carbon $fecha
     * @param int $from_caja_id
     * @param int $to_caja_id
     * @param float|int $monto
     * @return void
     */
    protected function ejecutar_movimiento_entre_cajas($fecha, $from_caja_id, $to_caja_id, $monto)
    {
        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $ultimo_num = DB::table('movimiento_entre_cajas')->where('user_id', $this->user_id)->max('num');

            $movimiento = MovimientoEntreCaja::create([
                'num'          => is_null($ultimo_num) ? 1 : ((int) $ultimo_num) + 1,
                'from_caja_id' => $from_caja_id,
                'to_caja_id'   => $to_caja_id,
                'amount'       => $monto,
                // La columna es NOT NULL (migración 2024_09_20_093603) y el controller real le pasa
                // `$this->userId(false)`. En consola no hay empleado logueado, pero
                // `DeleteModelsHelper::setup_auth_context()` ya dejó al owner autenticado y
                // `UserHelper::userId(false)` cae igual a `config('app.USER_ID')` si no lo hubiera.
                'employee_id'  => UserHelper::userId(false),
                'user_id'      => $this->user_id,
                'created_at'   => Carbon::now(),
            ]);

            (new MovimientoEntreCajaHelper())->mover($movimiento);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Manda las aperturas que ya existían al día anterior al primer día sembrado y las deja cerradas.
     *
     * 🔴 Sin esto no sirve de nada abrir la caja de cada día: `CajaSeeder` deja cada caja abierta con
     * una apertura fechada HOY, y como `get_current_aperutra_caja()` va por `created_at DESC`, esa
     * apertura le gana a TODAS las diarias que sembramos en el pasado -- los ~1.500 movimientos
     * históricos quedarían colgados de ella igual, que es exactamente lo que el pedido 8 viene a
     * arreglar.
     *
     * Solo se tocan las aperturas que caerían dentro (o después) del rango sembrado: una apertura
     * genuinamente vieja no molesta a nadie y no hay por qué reescribirla.
     *
     * @param \Illuminate\Support\Collection $cajas
     * @param \Carbon\Carbon $primer_dia
     * @return void
     */
    protected function preparar_aperturas_previas($cajas, $primer_dia)
    {
        $momento = $primer_dia->copy()->subDay()->setTime(8, 0)->format('Y-m-d H:i:s');

        // Se cuentan ANTES de tocar nada y sin filtrar por fecha: son las filas que van a quedar en
        // la base ADEMÁS de las que siembra esta corrida, y `resumen_aperturas.total` tiene que
        // incluirlas o la planilla informa menos aperturas de las que Lucas va a contar.
        $this->aperturas_previas = AperturaCaja::whereIn('caja_id', $cajas->pluck('id')->all())->count();

        foreach ($cajas as $caja) {
            AperturaCaja::where('caja_id', $caja->id)
                ->where('created_at', '>=', $momento)
                ->update([
                    'created_at'   => $momento,
                    'updated_at'   => $momento,
                    'cerrada_at'   => $momento,
                    'saldo_cierre' => $caja->saldo,
                ]);

            $caja->abierta = 0;
            $caja->cerrada_at = $momento;
            $caja->current_apertura_caja_id = null;
            $caja->save();
        }
    }

    /**
     * Abre todas las cajas del usuario a las 08:00 del día que se está por sembrar.
     *
     * El reloj se mueve con `Carbon::setTestNow()` en try/finally: `AperturaCaja` usa
     * `$table->timestamps()`, así que la apertura queda con `created_at` en el pasado, que es lo que
     * después va a resolver `get_current_aperutra_caja()` para los movimientos de ese mismo día. El
     * `finally` no es opcional: si una excepción dejara el reloj fijado, todo lo que venga después
     * (incluidos los otros tests de la suite) quedaría fechado en el pasado.
     *
     * @param \Illuminate\Support\Collection $cajas
     * @param \Carbon\Carbon $dia
     * @return void
     */
    protected function abrir_cajas_del_dia($cajas, $dia)
    {
        Carbon::setTestNow($dia->copy()->setTime(8, 0));

        try {
            foreach ($cajas as $caja) {
                $helper = new CajaAperturaHelper($caja->id);
                $helper->abrir_caja();
            }
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Cierra todas las cajas del usuario a las 22:00, después de los movimientos del día.
     *
     * Se saltea la caja que no tenga `current_apertura_caja_id`: `CajaCierreHelper::cerrar_apertura()`
     * hace `AperturaCaja::find(null)` y escribe sobre el resultado sin chequearlo, así que cerrar una
     * caja ya cerrada tira un fatal.
     *
     * @param \Illuminate\Support\Collection $cajas
     * @param \Carbon\Carbon $dia
     * @return void
     */
    protected function cerrar_cajas_del_dia($cajas, $dia)
    {
        Carbon::setTestNow($dia->copy()->setTime(22, 0));

        try {
            foreach ($cajas as $caja) {
                $helper = new CajaCierreHelper($caja->id);

                if (is_null($helper->caja) || is_null($helper->caja->current_apertura_caja_id)) {
                    continue;
                }

                $helper->cerrar_caja();
            }
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Red que avisa si la cuenta de stock se movió: los perfiles de stock de
     * `FerreteriaArticlesSeeder` están dimensionados para que ninguna sucursal termine el año en
     * negativo, y si esto salta es que cambió la cadencia, las unidades por venta o los perfiles.
     *
     * Es un aviso, NO una excepción: el resto de la semilla sigue siendo válido y tirar acá dejaría
     * la base a medio sembrar después de quince minutos de corrida.
     *
     * @return void
     */
    protected function avisar_stock_negativo()
    {
        $negativos = DB::table('address_article')
            ->join('articles', 'articles.id', '=', 'address_article.article_id')
            ->where('articles.user_id', $this->user_id)
            ->where('address_article.amount', '<', 0)
            ->count();

        if ($negativos > 0) {
            $this->avisar($negativos.' pares (artículo, sucursal) quedaron con stock NEGATIVO. '
                .'Revisar los perfiles de FerreteriaArticlesSeeder::PLANES_DE_STOCK contra la '
                .'cadencia y las unidades por venta de este comando.');
        }
    }

    /**
     * Red que avisa si alguna caja PASÓ por saldo negativo en algún momento de la corrida.
     *
     * Es la hermana de `avisar_stock_negativo()` y existe por el mismo motivo: el reparto de plata
     * está dimensionado para que ninguna caja entre en rojo, y si esto salta es que cambió algo del
     * dimensionamiento. Lucas pidió para el stock "suficiente stock para que luego de hacer las
     * ventas no queden en negativo"; para las cajas vale igual, y con el agravante de que una caja
     * de mostrador en rojo se ve apenas se abre la pantalla de cajas, sin abrir un solo reporte.
     *
     * 🔴 Mira el MÍNIMO de `movimiento_cajas.saldo`, no `cajas.saldo`. La diferencia es todo el
     * punto: `cajas.saldo` es el saldo final, y una caja que pasó por −4.000 en el segundo mes y
     * cerró el año en millones tiene el saldo final impecable. `movimiento_cajas.saldo` es el saldo
     * corriente que `MovimientoCajaHelper::set_saldos()` deja grabado en CADA movimiento, y como
     * este comando siembra en orden cronológico estricto, su mínimo por caja es exactamente el peor
     * momento que atravesó esa caja.
     *
     * Por qué puede saltar, si el reparto está pensado para que no: los montos de los gastos y de
     * los pagos a proveedor los sortea `ReportesMesSeeder::distribuir()`. En el reparto MÁS
     * desfavorable posible, el primer mes de la rampa -- el único que no tiene cobranza arrastrada
     * y el único que arranca sin saldo previo -- queda corto por hasta el 1% de sus ventas brutas.
     * Está acotado y es muy improbable, pero es mejor que el que corre el comando se entere acá.
     *
     * Es un aviso y NO una excepción, mismo criterio que el de stock: el resto de la semilla sigue
     * siendo válida y tirar acá dejaría la base a medio sembrar.
     *
     * @return void
     */
    protected function avisar_caja_en_rojo()
    {
        $minimos = DB::table('movimiento_cajas')
            ->join('cajas', 'cajas.id', '=', 'movimiento_cajas.caja_id')
            ->where('cajas.user_id', $this->user_id)
            ->where('movimiento_cajas.saldo', '<', 0)
            ->groupBy('cajas.id', 'cajas.name')
            ->selectRaw('cajas.id as caja_id, cajas.name as nombre, MIN(movimiento_cajas.saldo) as minimo, COUNT(*) as movimientos')
            ->get();

        foreach ($minimos as $fila) {
            $this->avisar('La caja "'.$fila->nombre.'" (id '.$fila->caja_id.') pasó por saldo NEGATIVO: '
                .'tocó '.$fila->minimo.' y estuvo en rojo durante '.$fila->movimientos.' movimientos. '
                .'Revisar el reparto de egresos de efectivo (metodos_de_efectivo_por_sucursal()) y la '
                .'fecha de cierre de mes de los egresos contra los ingresos de ese mes.');
        }
    }

    /**
     * Aviso que sirve igual cuando el comando no corre desde la consola.
     *
     * `sembrar_mes()` es público y lo invoca un test que construye el comando con `new`, sin
     * `OutputInterface`: llamar a `$this->warn()` ahí revienta con "call to a member function
     * writeln() on null". Por eso el aviso va al log siempre y a la consola solo si hay consola.
     *
     * @param string $mensaje
     * @return void
     */
    protected function avisar($mensaje)
    {
        Log::warning('semilla:datos: '.$mensaje);

        if (!is_null($this->output)) {
            $this->warn($mensaje);
        }
    }

    /**
     * Bloque especial del día de hoy: unas pocas ventas de mostrador con la caja abierta, un par
     * de presupuestos pendientes (sembrados en `sembrar_presupuestos()`) y una venta sin
     * terminar. Es lo que permite probar la vista de caja del día y el dashboard, que con datos
     * solo del pasado se ven vacíos.
     *
     * @return array{saldo_por_caja: array<string,float>, delta_deuda_por_cliente: array<int,float>}
     */
    protected function sembrar_hoy()
    {
        $hoy = Carbon::now();
        $primer_address_id = $this->addresses->first()->id;

        $saldo_por_caja = [];
        $saldo_por_cliente_delta = [];

        // Un par de ventas de mostrador cobradas en efectivo, hoy.
        for ($i = 0; $i < 2; $i++) {
            $address = $this->addresses->get($i % $this->addresses->count());
            $monto = 15000 + ($i * 5000);
            $caja_id = $this->semilla->caja_para(3, $address->id);

            $this->semilla->venta_mostrador($hoy->copy(), $monto, $address->id, [
                ['current_acount_payment_method_id' => 3, 'amount' => $monto, 'caja_id' => $caja_id],
            ]);

            $saldo_por_caja['efectivo'] = ($saldo_por_caja['efectivo'] ?? 0) + $monto;
        }

        // Una venta SIN TERMINAR (terminada = 0, sin métodos de pago): no pasa por
        // SemillaHelper porque ninguna de sus primitivas cubre este caso -- es un estado
        // exclusivo de "hoy", no una operación cerrada. Mismo camino real que usan las
        // primitivas (SaleSeederHelper::create_sales()), solo que con terminada = 0.
        //
        // 🔴 client_id tiene que ser un cliente REAL, no null. Verificado: cuando client_id es
        // null (o omitir_en_cuenta_corriente=1) Y selected_payment_methods viene vacío,
        // SaleHelper::attachSelectedPaymentMethods() cae en una rama de fallback vieja que
        // intenta adjuntar un único método de pago leyendo $request->current_acount_payment_method_id
        // (null) y $request->caja_id (propiedad que SaleSeederHelper::setRequest() nunca define)
        // -- error real, ajeno a este prompt, que no corresponde arreglar acá. Con un client_id
        // real (mismo patrón que venta_cuenta_corriente()), esa rama entera se saltea: el
        // guard es is_null(client_id) || omitir_en_cuenta_corriente, y con las dos cosas en
        // false/0 no entra.
        Carbon::setTestNow($hoy->copy());
        try {
            $num = (int) (DB::table('sales')->where('user_id', config('app.USER_ID'))->max('num') ?? 0) + 1;
            $cliente = $this->cliente_rotativo(0);

            SaleSeederHelper::create_sales([
                [
                    'num'             => $num,
                    'total'           => 8000,
                    'address_id'      => $primer_address_id,
                    'employee_id'     => null,
                    'client_id'       => $cliente->id,
                    'created_at'      => Carbon::now(),
                    'terminada'       => 0,
                    'confirmed'       => 0,
                    'articles'        => [
                        [
                            'id'           => $this->articulo_id,
                            'price_vender' => 8000,
                            'cost'         => 4000,
                            'amount'       => 1,
                        ],
                    ],
                    'payment_methods' => [],
                ],
            ]);

            // SaleHelper::create_current_acount() corre para toda venta con client_id +
            // save_current_acount (que SaleSeederHelper::create_sales() deja siempre en 1), sin
            // mirar terminada -- esta venta sin terminar SÍ genera deuda en la cuenta corriente
            // del cliente, aunque todavía no esté cerrada.
            $saldo_por_cliente_delta[$cliente->id] = ($saldo_por_cliente_delta[$cliente->id] ?? 0) + 8000;
        } finally {
            Carbon::setTestNow(null);
        }

        return [
            'saldo_por_caja'          => $saldo_por_caja,
            'delta_deuda_por_cliente' => $saldo_por_cliente_delta,
        ];
    }

    /**
     * Recorre los cheques "recibidos" creados durante la corrida y les da un ciclo de vida real:
     * uno cobrado, uno rechazado, uno endosado (entregado) a un proveedor, y el resto queda "en
     * cartera" (sin tocar, con `fecha_pago` a 30 días de su fecha de emisión). Mismo camino que
     * usa `ChequeController` (`cobrar()`, `rechazar()`, `endosar()`), replicado acá porque este
     * comando corre en consola, no HTTP.
     *
     * @return array<string,int> Conteo por estado, para la planilla de control.
     */
    protected function sembrar_cheques_con_ciclo()
    {
        $conteo = ['en_cartera' => 0, 'cobrado' => 0, 'rechazado' => 0, 'endosado' => 0];

        $cheques = Cheque::whereIn('id', $this->cheques_recibidos_ids)->get();

        foreach ($cheques as $indice => $cheque) {
            if ($indice === 0) {
                // Cobrado: mismo camino que ChequeController::cobrar().
                $cheque->estado_manual = 'cobrado';
                $cheque->cobrado_en = Carbon::now();
                $cheque->cobrado_por_id = config('semilla.user_id');
                $cheque->save();

                if (!is_null($cheque->caja_id)) {
                    CurrentAcountCajaHelper::guardar_pago(
                        $cheque->amount,
                        $cheque->caja_id,
                        'client',
                        $cheque->current_acount,
                        'Cobro cheque N° '.$cheque->numero
                    );
                }
                $conteo['cobrado']++;
            } elseif ($indice === 1) {
                // Rechazado: mismo camino que ChequeController::rechazar().
                $cheque->estado_manual = 'rechazado';
                $cheque->rechazado_en = Carbon::now();
                $cheque->rechazado_por_id = config('semilla.user_id');
                $cheque->rechazado_observaciones = null;
                $cheque->save();
                $conteo['rechazado']++;
            } elseif ($indice === 2) {
                // Endosado (entregado) a un proveedor: mismo camino que ChequeController::endosar(),
                // sin la creación de la cuenta corriente del proveedor asociada (fuera de alcance de
                // este prompt -- lo que importa acá es que el cheque quede en estado "entregado").
                $proveedor = $this->proveedor_rotativo(0);
                $cheque->endosado_a_provider_id = $proveedor->id;
                $cheque->fecha_endoso = Carbon::now();
                $cheque->save();
                $conteo['endosado']++;
            } else {
                // En cartera: no se toca. Queda con estado_manual null y su fecha_pago original.
                $conteo['en_cartera']++;
            }
        }

        return $conteo;
    }

    /**
     * Presupuestos en tres estados: pendientes (sin confirmar, vencimiento futuro), vencidos
     * (sin confirmar, vencimiento pasado) y aprobados (confirmados). La conversión de un
     * presupuesto aprobado a venta real queda deliberadamente fuera de alcance de este prompt
     * (no hay un "camino real" de conversión mapeado en el prompt 04, y agregarlo a mano violaría
     * la regla de no esquivar helpers) -- lo que importa para el criterio de éxito es que existan
     * presupuestos identificables en los tres estados, no la conversión en sí.
     *
     * @return array<string,int> Conteo por estado.
     */
    protected function sembrar_presupuestos()
    {
        $conteo = ['pendiente' => 0, 'vencido' => 0, 'aprobado' => 0];

        $definiciones = [
            ['budget_status_id' => 1, 'finish_at' => Carbon::now()->addDays(15), 'clave' => 'pendiente'],
            ['budget_status_id' => 1, 'finish_at' => Carbon::now()->addDays(20), 'clave' => 'pendiente'],
            ['budget_status_id' => 1, 'finish_at' => Carbon::now()->subDays(10), 'clave' => 'vencido'],
            ['budget_status_id' => 2, 'finish_at' => Carbon::now()->addDays(30), 'clave' => 'aprobado'],
        ];

        foreach ($definiciones as $indice => $def) {
            $cliente = $this->cliente_rotativo($indice + 3);
            $articulo = Article::find($this->articulo_id);
            $monto = 5000 + ($indice * 1000);

            $budget = Budget::create([
                'num'              => 900000 + $indice,
                'client_id'        => $cliente->id,
                'budget_status_id' => $def['budget_status_id'],
                'finish_at'        => $def['finish_at'],
                'observations'     => 'Presupuesto sembrado ('.$def['clave'].')',
                'total'            => $monto,
                'user_id'          => $this->user_id,
            ]);

            BudgetHelper::attachArticles($budget, [
                [
                    'id'            => $articulo->id,
                    'name'          => $articulo->name,
                    'bar_code'      => $articulo->bar_code,
                    'provider_code' => $articulo->provider_code,
                    'status'        => $articulo->status,
                    'pivot'         => [
                        'amount'   => 1,
                        'price'    => $monto,
                        'bonus'    => 0,
                        'location' => null,
                    ],
                ],
            ]);

            $conteo[$def['clave']]++;
        }

        return $conteo;
    }

    /**
     * Escribe `storage/app/semilla/control.json` y una tabla resumen en consola. Todos los
     * valores vienen de lo que YA se calculó y se le pidió a `SemillaHelper` que sembrara -- no
     * se consulta la base acá. Consultarla convertiría el test del prompt 06 en una verificación
     * del sistema contra sí mismo.
     *
     * @param array $control_meses
     * @param array{saldo_por_caja: array<string,float>, delta_deuda_por_cliente: array<int,float>} $control_hoy
     * @param int $meses_atras
     * @param array $ciclo_cheques
     * @param array $presupuestos
     * @param array<int,int> $cadencia Ventas por mes efectivamente usadas en esta corrida.
     * @param array<string,mixed> $actividad_tienda Resumen de `ActividadTiendaHelper::sembrar()`.
     * @return void
     */
    protected function escribir_planilla_de_control($control_meses, $control_hoy, $meses_atras, $ciclo_cheques, $presupuestos, $cadencia = [], $actividad_tienda = [])
    {
        $saldo_por_caja_total = [];
        $deuda_por_cliente = [];
        $deuda_por_proveedor = [];

        foreach ($control_meses as $mes) {
            foreach ($mes['saldo_por_caja'] as $caja => $monto) {
                $saldo_por_caja_total[$caja] = ($saldo_por_caja_total[$caja] ?? 0) + $monto;
            }
            foreach ($mes['delta_deuda_por_cliente'] as $client_id => $delta) {
                $deuda_por_cliente[$client_id] = ($deuda_por_cliente[$client_id] ?? 0) + $delta;
            }
            foreach ($mes['delta_deuda_por_proveedor'] as $provider_id => $delta) {
                $deuda_por_proveedor[$provider_id] = ($deuda_por_proveedor[$provider_id] ?? 0) + $delta;
            }
        }

        // El bloque de "hoy" también mueve caja y genera deuda real -- tiene que entrar en el
        // mismo total, o la planilla queda corta contra lo que el comando efectivamente sembró
        // (hallazgo del checker, 3/8/2026).
        foreach ($control_hoy['saldo_por_caja'] as $caja => $monto) {
            $saldo_por_caja_total[$caja] = ($saldo_por_caja_total[$caja] ?? 0) + $monto;
        }
        foreach ($control_hoy['delta_deuda_por_cliente'] as $client_id => $delta) {
            $deuda_por_cliente[$client_id] = ($deuda_por_cliente[$client_id] ?? 0) + $delta;
        }

        // 🔴 `ActividadTiendaHelper` cobra las cuentas corrientes de los clientes de tienda POR EL
        // CAMINO REAL y con la fecha de hoy, o sea que mete plata en las cajas de efectivo DESPUÉS
        // de que este comando calculó todos sus números. Sin sumarlo acá, `control.json` queda
        // corto contra la base por el monto de esas cobranzas y el desglose por caja deja de cerrar.
        // El resumen indexa por `caja_id` (no por método), así que el mapa se resuelve preguntándole
        // a `caja_para()` a qué caja apunta cada método -- nunca adivinando por el nombre de la caja.
        $claves_por_caja = $this->claves_por_caja_id();

        if (isset($actividad_tienda['cobranzas_de_cierre']['por_caja'])) {
            foreach ($actividad_tienda['cobranzas_de_cierre']['por_caja'] as $caja_id => $monto) {
                if (isset($claves_por_caja[(int) $caja_id])) {
                    $clave = $claves_por_caja[(int) $caja_id];
                } else {
                    // No debería pasar (el helper cobra siempre por el método 3), pero si pasara la
                    // plata tiene que aparecer en algún renglón en vez de desaparecer de la planilla.
                    $clave = 'caja_'.$caja_id;
                    $this->avisar('La caja '.$caja_id.' de las cobranzas de cierre de tienda no es la caja por defecto de ningún método de pago: va a la planilla como "'.$clave.'".');
                }

                $saldo_por_caja_total[$clave] = ($saldo_por_caja_total[$clave] ?? 0) + $monto;
            }
        }

        // 🔴 Esas cobranzas de cierre además CANCELAN deuda: `cancelar_saldos()` cobra el saldo
        // pendiente de cada cliente que también es Buyer, por el camino real. Sumarlas a la caja y
        // no restarlas de la deuda dejaba a la planilla informando deuda de clientes que en la base
        // ya están en cero (12 de 25 en la corrida completa). El detalle por cliente ahora viene en
        // el resumen del helper (`por_cliente`), que es el dato que antes faltaba.
        if (isset($actividad_tienda['cobranzas_de_cierre']['por_cliente'])) {
            foreach ($actividad_tienda['cobranzas_de_cierre']['por_cliente'] as $client_id => $monto) {
                $deuda_por_cliente[$client_id] = ($deuda_por_cliente[$client_id] ?? 0) - $monto;
            }
        }

        // Se redondea recién acá, después de todas las sumas y restas: son montos en pesos y la
        // planilla es para leerla. Sin esto, un cliente que quedó en cero pero cuya deuda pasó por
        // doce sumas y doce restas de floats aparece con "4.5474735088646E-12" en vez de "0".
        foreach ($deuda_por_cliente as $client_id => $monto) {
            $deuda_por_cliente[$client_id] = round((float) $monto, 2);
        }
        foreach ($deuda_por_proveedor as $provider_id => $monto) {
            $deuda_por_proveedor[$provider_id] = round((float) $monto, 2);
        }

        // El barrido de efectivo del cierre de mes (ver `planificar_barrido_de_efectivo()`) mueve
        // plata ENTRE cajas: baja el efectivo del mostrador y sube Caja Fuerte y Banco Nación. Va
        // acá y no en el renglón de cada mes porque se planifica en `handle()`, fuera de la
        // aritmética mensual.
        if ($this->resumen_barrido['movimientos'] > 0) {
            $saldo_por_caja_total['efectivo'] = ($saldo_por_caja_total['efectivo'] ?? 0)
                - $this->resumen_barrido['de_efectivo_a_caja_fuerte'];

            $saldo_por_caja_total[self::CLAVE_CAJA_FUERTE] = ($saldo_por_caja_total[self::CLAVE_CAJA_FUERTE] ?? 0)
                + $this->resumen_barrido['de_efectivo_a_caja_fuerte']
                - $this->resumen_barrido['de_caja_fuerte_a_banco'];

            $saldo_por_caja_total[self::CLAVE_CAJA_BANCO] = ($saldo_por_caja_total[self::CLAVE_CAJA_BANCO] ?? 0)
                + $this->resumen_barrido['de_caja_fuerte_a_banco'];
        }

        $control = [
            'parametros' => [
                'meses_atras'              => $meses_atras,
                'ticket_promedio'          => self::TICKET_PROMEDIO,
                'cadencia_ventas_por_mes'  => array_values($cadencia),
                'semilla_aleatoria'        => (int) config('semilla.semilla_aleatoria'),
                'user_id'                  => $this->user_id,
                'fecha_de_corrida'         => Carbon::now()->format('Y-m-d H:i:s'),
            ],
            'meses'                    => $control_meses,
            'hoy'                      => $control_hoy,
            'saldo_esperado_por_caja'  => $saldo_por_caja_total,
            // Ya descuenta las cobranzas de cierre de tienda, cliente por cliente (ver más arriba).
            'deuda_esperada_por_cliente'   => $deuda_por_cliente,
            'deuda_esperada_por_proveedor' => $deuda_por_proveedor,
            'cheques_por_estado'       => $ciclo_cheques,
            'presupuestos_por_estado'  => $presupuestos,
            'aperturas_de_caja'        => $this->resumen_aperturas,
            'barrido_de_efectivo'      => $this->resumen_barrido,
            'perfiles_de_stock'        => $this->resumen_de_perfiles_de_stock(),
            'actividad_tienda'         => $actividad_tienda,
        ];

        Storage::disk('local')->put(
            'semilla/control.json',
            json_encode($control, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->info('Planilla de control escrita en storage/app/semilla/control.json');

        $this->table(
            ['Mes', 'Brutas', 'Netas', 'Resultado bruto', 'Gastos', 'Resultado operativo', 'IIBB'],
            array_map(function ($mes) {
                return [
                    $mes['mes'].($mes['es_mes_actual'] ? ' (actual)' : ''),
                    number_format($mes['ventas_brutas'], 0, ',', '.'),
                    number_format($mes['ventas_netas'], 0, ',', '.'),
                    number_format($mes['resultado_bruto'], 0, ',', '.'),
                    number_format($mes['gastos'], 0, ',', '.'),
                    number_format($mes['resultado_operativo'], 0, ',', '.'),
                    number_format($mes['iibb_estimado'], 0, ',', '.'),
                ];
            }, $control_meses)
        );

        $this->table(
            ['Caja', 'Saldo esperado'],
            array_map(function ($caja, $monto) {
                return [$caja, number_format($monto, 0, ',', '.')];
            }, array_keys($saldo_por_caja_total), $saldo_por_caja_total)
        );

        $this->line('Cheques: '.json_encode($ciclo_cheques));
        $this->line('Presupuestos: '.json_encode($presupuestos));
        $this->line('Aperturas de caja: '.json_encode($this->resumen_aperturas));
        $this->line('Barrido de efectivo: '.json_encode($this->resumen_barrido));
    }

    /**
     * Mapa `caja_id => clave de la planilla`, resuelto preguntándole a `DefaultPaymentMethodCaja`
     * (vía `caja_para()`) a qué caja va cada método de pago en cada sucursal.
     *
     * Se resuelve así y no por el nombre de la caja porque el nombre cambia (la caja del método 4
     * pasó de llamarse "Banco" a "Transferencias" sin que la clave de la planilla cambie) y porque
     * el efectivo son cuatro cajas distintas -- una por sucursal -- que comparten la misma clave.
     *
     * @return array<int,string>
     */
    protected function claves_por_caja_id()
    {
        $mapa = [];

        foreach (self::CLAVE_POR_METODO as $metodo_id => $clave) {
            foreach ($this->addresses as $address) {
                try {
                    $mapa[$this->semilla->caja_para($metodo_id, $address->id)] = $clave;
                } catch (\Exception $e) {
                    // Un método sin caja por defecto en esa sucursal no es un error acá: este mapa
                    // es para etiquetar plata que ya se movió, no para decidir dónde moverla.
                    continue;
                }
            }
        }

        return $mapa;
    }

    /**
     * Los tres perfiles de stock con los que `FerreteriaArticlesSeeder` sembró el catálogo, y
     * cuántos artículos cayó en cada uno.
     *
     * Va a la planilla porque el stock inicial es la otra mitad de la cuenta que hace verificables
     * a los motores de sugerencias: el stock final esperado es "inicial − vendido", y sin el inicial
     * escrito al lado de lo vendido esa resta hay que ir a buscarla al seeder.
     *
     * @return array<string,mixed>
     */
    protected function resumen_de_perfiles_de_stock()
    {
        $conteo = [];

        foreach ((new FerreteriaArticlesSeeder())->get_catalog() as $item) {
            $perfil = isset($item['perfil_stock']) ? $item['perfil_stock'] : 'sin_perfil';
            $conteo[$perfil] = (isset($conteo[$perfil]) ? $conteo[$perfil] : 0) + 1;
        }

        return [
            'planes'                 => FerreteriaArticlesSeeder::PLANES_DE_STOCK,
            'articulos_por_perfil'   => $conteo,
        ];
    }

    /**
     * Grupo 321 · Prompt 06 — wrapper público de `resetear()` para el `tearDown()` de un test de
     * aceptación: borra por el mismo camino real de controllers todo lo que `sembrar_mes()` haya
     * sembrado para `config('semilla.user_id')` (ventas, cuentas corrientes, comprobantes,
     * cheques), sin loguear nada por consola. Requiere haber llamado antes `preparar_para_test()`
     * (usa `$this->user_id`).
     *
     * Desde la semilla de datos de demo SÍ borra `movimiento_cajas` y `apertura_cajas` y deja
     * `cajas.saldo` en 0 (antes no: los controllers que usa `resetear()` reciben un
     * `Request::create('/')` sin `compensar_caja`, así que nunca generan el movimiento de
     * compensación, y los saldos quedaban inflados corrida tras corrida). Hacía falta igual por otro
     * motivo: con apertura diaria, dos corridas seguidas de `semilla:datos --reset` dejarían el doble
     * de aperturas colgadas de las mismas cajas.
     *
     * @return void
     */
    public function limpiar_para_test()
    {
        $this->resetear();
    }

    /**
     * Borra lo que dejó una corrida anterior de ESTE comando, usando el flujo real de cada
     * controller (mismo criterio que `ReportesMesSeeder::truncate_data()`) -- nunca `delete()` a
     * lo bruto, que dejaría cuentas corrientes descuadradas.
     *
     * @return void
     */
    protected function resetear()
    {
        $user_id = $this->user_id;

        $request = Request::create('/');
        config(['app.suppress_delete_notifications' => true]);

        try {
            $sale_controller = new SaleController();
            $sale_ids = Sale::where('user_id', $user_id)->pluck('id')->toArray();
            foreach ($sale_ids as $sale_id) {
                $sale_controller->destroy($request, $sale_id);
            }

            $provider_order_ids = ProviderOrder::where('user_id', $user_id)->pluck('id')->toArray();
            if (count($provider_order_ids)) {
                DeleteModelsHelper::process_delete('provider_order', $provider_order_ids, true);
            }

            $expense_controller = new ExpenseController();
            $expense_ids = Expense::where('user_id', $user_id)->pluck('id')->toArray();
            foreach ($expense_ids as $expense_id) {
                $expense_controller->destroy($request, $expense_id);
            }

            $current_acount_controller = new CurrentAcountController();
            $current_acounts = CurrentAcount::where('user_id', $user_id)
                ->orderBy('created_at', 'DESC')
                ->get();
            foreach ($current_acounts as $current_acount) {
                $model_name = !is_null($current_acount->client_id) ? 'client' : 'provider';
                $current_acount_controller->delete($request, $model_name, $current_acount->id);
            }

            // Cheques, presupuestos y comprobantes fiscales sembrados por este comando: no tienen
            // el mismo riesgo de descuadrar una cuenta corriente que Sale/CurrentAcount/Expense
            // (ya se borraron arriba, con su flujo real), así que un delete directo por lote es
            // seguro acá.
            Cheque::where('user_id', $user_id)->delete();
            Budget::whereIn('num', range(900000, 900010))->where('user_id', $user_id)->delete();
            AfipTicket::whereHas('sale', function ($q) use ($user_id) {
                $q->where('user_id', $user_id);
            })->delete();
            ProviderOrderAfipTicket::where('user_id', $user_id)->delete();

            CompanyPerformance::where('user_id', $user_id)->delete();

            // Caja: va AL FINAL, después de los controllers, que leen y escriben movimientos
            // mientras deshacen ventas, gastos y cuentas corrientes.
            //
            // `current_apertura_caja_id` se pone en null junto con el borrado, no como adorno:
            // dejarlo apuntando a una `apertura_cajas` que ya no existe hace que
            // `CajaCierreHelper::cerrar_apertura()` (que hace `find()` y escribe sin chequear) tire
            // un fatal en el primer cierre de la corrida siguiente.
            $caja_ids = Caja::where('user_id', $user_id)->pluck('id')->toArray();

            if (count($caja_ids)) {
                MovimientoCaja::whereIn('caja_id', $caja_ids)->delete();
                AperturaCaja::whereIn('caja_id', $caja_ids)->delete();

                // Los movimientos entre cajas del barrido de efectivo: sus dos MovimientoCaja ya se
                // borraron en la línea de arriba, pero la cabecera vive en su propia tabla y sin
                // esto dos corridas con --reset dejarían el doble de transferencias en la pantalla.
                MovimientoEntreCaja::where('user_id', $user_id)->delete();

                Caja::whereIn('id', $caja_ids)->update([
                    'saldo'                    => 0,
                    'abierta'                  => 0,
                    'current_apertura_caja_id' => null,
                ]);
            }
        } finally {
            config(['app.suppress_delete_notifications' => false]);
        }
    }

    /**
     * Fecha aleatoria dentro del mes que se está sembrando, determinística porque corre después
     * de `mt_srand()`.
     *
     * @param \Carbon\Carbon $inicio_mes
     * @param int $dias_disponibles
     * @return \Carbon\Carbon
     */
    protected function fecha_en_rango($inicio_mes, $dias_disponibles)
    {
        return $inicio_mes->copy()->addDays(mt_rand(0, max(0, $dias_disponibles - 1)))->setTime(mt_rand(9, 19), mt_rand(0, 59));
    }

    /**
     * Cuánto puede pagar cada cliente este mes: su deuda pendiente multiplicada por su perfil de
     * pago (ver `PERFIL_DE_PAGO`). Los que no deben nada, y los que tienen perfil 0, no aparecen.
     *
     * Se devuelve ordenado de mayor a menor monto cobrable, con el id de cliente como desempate
     * ascendente. El orden no es cosmético: es el que consume `tomar_de_la_capacidad()`, así que es
     * lo que decide a quién se le cobra primero cuando el balde de un método no alcanza para todos.
     * `arsort()` no sirve para esto: en PHP 7.4 los sorts NO son estables y dos clientes con el
     * mismo monto quedarían ordenados según el orden de inserción, que a su vez depende del
     * catálogo -- el mismo tipo de dependencia escondida que hace que dos corridas dejen de ser
     * comparables.
     *
     * @param float|null $perfil_fijo Si viene, se usa este perfil para TODOS los clientes en vez de
     *                                `PERFIL_DE_PAGO`. Lo usan las devoluciones, que se apoyan en la
     *                                deuda entera (1,0) porque una nota de crédito no depende de la
     *                                voluntad de pago del cliente.
     * @return array<int,float> client_id => monto cobrable
     */
    protected function capacidad_de_cobro_del_mes($perfil_fijo = null)
    {
        $capacidad = [];

        foreach ($this->clientes->values() as $posicion => $cliente) {
            $client_id = (int) $cliente->id;
            $deuda = isset($this->deuda_por_cliente[$client_id]) ? (float) $this->deuda_por_cliente[$client_id] : 0.0;

            if ($deuda <= 0) {
                continue;
            }

            $perfil = is_null($perfil_fijo)
                ? self::PERFIL_DE_PAGO[$posicion % count(self::PERFIL_DE_PAGO)]
                : (float) $perfil_fijo;

            $cobrable = round($deuda * $perfil, 2);

            if ($cobrable > 0) {
                $capacidad[$client_id] = $cobrable;
            }
        }

        uksort($capacidad, function ($a, $b) use ($capacidad) {
            if ($capacidad[$a] == $capacidad[$b]) {
                return $a - $b;
            }

            return $capacidad[$a] < $capacidad[$b] ? 1 : -1;
        });

        return $capacidad;
    }

    /**
     * Toma `$monto` de la capacidad de cobro del mes, cliente por cliente y de mayor a menor, sin
     * pasarse NUNCA de lo que cada uno debe. Descuenta lo tomado de `$capacidad` y del ledger de
     * deuda, así que dos llamadas seguidas (una por método de pago) no cobran dos veces lo mismo.
     *
     * La suma de las partes que devuelve es exactamente `$monto`, salvo que se acabe la capacidad
     * del mes -- caso que el llamador ya evitó topeando `$cobranza_cc_total` con la capacidad total.
     *
     * @param float $monto
     * @param array<int,float> $capacidad Se modifica: se le descuenta lo tomado.
     * @return array<int,array<string,mixed>> Lista de ['client_id' => int, 'monto' => float]
     */
    protected function tomar_de_la_capacidad($monto, &$capacidad)
    {
        $partes = [];
        $restante = round((float) $monto, 2);

        // Se recorren las CLAVES y no el array: `$capacidad` llega por referencia y se modifica
        // adentro del loop, y recorrer las claves deja explícito que la lista sobre la que se itera
        // no cambia mientras se la consume.
        foreach (array_keys($capacidad) as $client_id) {
            if ($restante <= 0) {
                break;
            }

            $cobrable = (float) $capacidad[$client_id];

            if ($cobrable <= 0) {
                continue;
            }

            $parte = round(min($cobrable, $restante), 2);

            $partes[] = ['client_id' => (int) $client_id, 'monto' => $parte];

            $capacidad[$client_id] = round($cobrable - $parte, 2);
            $this->deuda_por_cliente[$client_id] = round((isset($this->deuda_por_cliente[$client_id]) ? $this->deuda_por_cliente[$client_id] : 0) - $parte, 2);
            $restante = round($restante - $parte, 2);
        }

        return $partes;
    }

    /**
     * A quién se le hace la nota de crédito de `$monto`. Misma máquina que las cobranzas, con una
     * red al final: el monto de las devoluciones del mes es parte de la aritmética (entra en
     * `ventas_netas`, en el costo y en el resultado bruto, que son justo lo que compara
     * `4_Semilla_Test.php`), así que NO se puede recortar. Si no hay deuda suficiente para
     * absorberlo, el sobrante va igual al primer cliente del catálogo y ese cliente queda con saldo
     * a favor -- que es lo correcto contablemente: le devolvimos más de lo que debía.
     *
     * En la corrida completa ese caso no se da: cuando se planifican las devoluciones ya se
     * descontaron las cobranzas del mes y queda deuda de sobra.
     *
     * @param float $monto
     * @param array<int,float> $deuda_disponible Se modifica: se le descuenta lo tomado.
     * @return array<int,array<string,mixed>> Lista de ['client_id' => int, 'monto' => float]
     */
    protected function repartir_devolucion($monto, &$deuda_disponible)
    {
        $partes = $this->tomar_de_la_capacidad($monto, $deuda_disponible);

        $repartido = 0.0;
        foreach ($partes as $parte) {
            $repartido = round($repartido + $parte['monto'], 2);
        }

        $faltante = round((float) $monto - $repartido, 2);

        if ($faltante > 0) {
            $client_id = (int) $this->clientes->first()->id;

            $partes[] = ['client_id' => $client_id, 'monto' => $faltante];
            $this->deuda_por_cliente[$client_id] = round((isset($this->deuda_por_cliente[$client_id]) ? $this->deuda_por_cliente[$client_id] : 0) - $faltante, 2);
        }

        return $partes;
    }

    /**
     * Cliente de los 10 sembrados, rotando por índice.
     *
     * @param int $indice
     * @return \App\Models\Client
     */
    protected function cliente_rotativo($indice)
    {
        return $this->clientes->get($indice % $this->clientes->count());
    }

    /**
     * Proveedor de los 10 sembrados, rotando por índice.
     *
     * @param int $indice
     * @return \App\Models\Provider
     */
    protected function proveedor_rotativo($indice)
    {
        return $this->proveedores->get($indice % $this->proveedores->count());
    }

    /**
     * Registra el cheque "recibido" que acaba de crear `attach_payment_methods()` (vía el método
     * de pago 1) para la venta/cobro recién sembrado, para poder aplicarle el ciclo de estados
     * más adelante.
     *
     * `ChequeHelper::crear_cheque($model, $payment_method)` graba
     * `'current_acount_id' => $model->id` sin distinguir si `$model` es una `Sale` o un
     * `CurrentAcount` -- el nombre de la columna es heredado de cuando solo existía el camino de
     * cuenta corriente, pero el código de hoy la usa igual para las dos. Por eso alcanza con
     * buscar por `$modelo->id` sin importar de qué modelo venga.
     *
     * @param \App\Models\Sale|\App\Models\CurrentAcount $modelo
     * @return void
     */
    protected function registrar_cheque_recibido_de($modelo)
    {
        $cheque = Cheque::where('current_acount_id', $modelo->id)
            ->orderBy('id', 'DESC')
            ->first();

        if (!is_null($cheque)) {
            $this->cheques_recibidos_ids[] = $cheque->id;
        }
    }
}
