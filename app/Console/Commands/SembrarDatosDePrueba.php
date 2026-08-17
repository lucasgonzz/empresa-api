<?php

namespace App\Console\Commands;

use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\DeleteModelsHelper;
use App\Http\Controllers\Helpers\Seeders\ActividadTiendaHelper;
use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use App\Http\Controllers\Helpers\Seeders\SemillaHelper;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\caja\CajaCierreHelper;
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

    /** Compras a proveedores, como fracción de las ventas brutas. */
    const COMPRAS_FRACCION = 0.50;

    /** Pagos a proveedores del mes, como fracción de las compras del mismo mes. */
    const PAGOS_PROVEEDORES_FRACCION = 0.80;

    /** IIBB: alícuota del SaleTax sembrado en el prompt 01, replicada acá para el cálculo de la planilla. */
    const IIBB_FRACCION = 0.03;

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
     * Reparto de la porción en EFECTIVO del mostrador entre las 4 sucursales (índice 0 a 3). Solo
     * el efectivo se reparte por sucursal: es la única caja que es exclusiva de cada local (las
     * demás son compartidas, ver prompt 02), y es lo que hace que la sucursal 1 facture el
     * cuádruple que la 4 en el desglose por caja.
     */
    const REPARTO_SUCURSAL = [0.40, 0.30, 0.20, 0.10];

    /** Cantidad de registros por categoría al distribuir un total (mismo criterio que ReportesMesSeeder). */
    const CANT_REGISTROS = 4;

    /** @var \App\Http\Controllers\Helpers\Seeders\SemillaHelper */
    protected $semilla;

    protected $user_id;

    /** @var \Illuminate\Support\Collection */
    protected $clientes;

    /** @var \Illuminate\Support\Collection */
    protected $proveedores;

    /** @var \Illuminate\Support\Collection */
    protected $addresses;

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
     * @var array<string,int>
     */
    protected $resumen_aperturas = ['dias' => 0, 'cajas' => 0, 'total' => 0];

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
     * @return array{operaciones: array<int,array<string,mixed>>, control: array<string,mixed>}
     */
    protected function planificar_mes($meses_atras, $ventas_brutas_mes, $es_mes_actual, $cobranza_arrastrada = 0)
    {
        $operaciones = [];

        $inicio_mes = Carbon::now()->startOfMonth()->subMonths($meses_atras);
        $fin_mes = $es_mes_actual ? Carbon::now()->copy() : $inicio_mes->copy()->endOfMonth();
        // Cantidad de días disponibles para repartir las operaciones de este mes (el mes actual
        // parcial tiene menos días que un mes cerrado).
        $dias_disponibles = max(1, $inicio_mes->diffInDays($fin_mes));

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
        $cobranza_cc_total = round($ventas_cc_total * self::COBRANZA_CC_FRACCION + $cobranza_arrastrada, 2);
        $compras_total = round($ventas_brutas_mes * self::COMPRAS_FRACCION, 2);
        $pagos_proveedores_total = round($compras_total * self::PAGOS_PROVEEDORES_FRACCION, 2);
        $entra_a_caja_total = round($mostrador_total + $cobranza_cc_total, 2);
        // Aproximación documentada: iibb_determinado() calcula la base gravada sobre
        // article_sale.price_sin_iva (neto de IVA), pero esta semilla no distingue precio con/sin
        // IVA -- los montos de venta ya son "el total", no hay descomposición de IVA por línea.
        // Usar ventas_netas como base gravada es la aproximación más razonable disponible acá; el
        // prompt 06 compara contra esta misma cifra, así que el test sigue siendo consistente
        // consigo mismo aunque no sea matemáticamente idéntico a iibb_determinado() al centavo.
        $iibb_estimado = round($ventas_netas * self::IIBB_FRACCION, 2);

        $saldo_por_caja = []; // nombre_metodo => monto
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
                'importe_iva' => round($monto * 0.21, 2),
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
                'importe_iva' => round($monto * 0.21, 2),
                'cbte_letra'  => $comprobante['cbte_letra'],
                'cbte_tipo'   => $comprobante['cbte_tipo'],
            ]);

            $saldo_por_cliente_delta[$cliente->id] = ($saldo_por_cliente_delta[$cliente->id] ?? 0) + $monto;
        }

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
                $montos_cheque = ReportesMesSeeder::distribuir((int) $monto_metodo, self::CANT_REGISTROS);

                foreach ($montos_cheque as $indice_cheque => $monto_cheque) {
                    if ($monto_cheque <= 0) {
                        continue;
                    }
                    $cliente = $this->cliente_rotativo($indice_cheque + $offset_rotacion);
                    $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);
                    $caja_id = $this->semilla->caja_para(1, $primer_address_id);

                    $operaciones[] = $this->operacion($fecha, 'cobro_cuenta_corriente', [
                        'monto'             => $monto_cheque,
                        'client_id'         => $cliente->id,
                        'registrar_cheque'  => true,
                        'metodos'           => [
                            [
                                'current_acount_payment_method_id' => 1,
                                'amount'                             => $monto_cheque,
                                'caja_id'                            => $caja_id,
                                'numero'                             => 'COB-'.$meses_atras.'-'.$indice_cheque.'-'.mt_rand(10000, 99999),
                                'banco'                               => 'Banco Nación',
                                'fecha_emision'                       => $fecha->format('Y-m-d'),
                                'fecha_pago'                           => $fecha->copy()->addDays(30)->format('Y-m-d'),
                            ],
                        ],
                    ]);

                    $saldo_por_caja['cheques'] = ($saldo_por_caja['cheques'] ?? 0) + $monto_cheque;
                    $saldo_por_cliente_delta[$cliente->id] = ($saldo_por_cliente_delta[$cliente->id] ?? 0) - $monto_cheque;
                }

                continue;
            }

            $cliente = $this->cliente_rotativo($metodo_id + $offset_rotacion);
            $caja_id = $this->semilla->caja_para($metodo_id, $primer_address_id);

            $operaciones[] = $this->operacion($this->fecha_en_rango($inicio_mes, $dias_disponibles), 'cobro_cuenta_corriente', [
                'monto'     => $monto_metodo,
                'client_id' => $cliente->id,
                'metodos'   => [
                    ['current_acount_payment_method_id' => $metodo_id, 'amount' => $monto_metodo, 'caja_id' => $caja_id],
                ],
            ]);

            $nombre = self::CLAVE_POR_METODO[$metodo_id];
            $saldo_por_caja[$nombre] = ($saldo_por_caja[$nombre] ?? 0) + $monto_metodo;
            $saldo_por_cliente_delta[$cliente->id] = ($saldo_por_cliente_delta[$cliente->id] ?? 0) - $monto_metodo;
        }

        // --- Devoluciones, repartidas entre clientes con ventas CC este mes ---------------------
        $montos_devoluciones = $devoluciones_total > 0
            ? ReportesMesSeeder::distribuir((int) $devoluciones_total, self::CANT_REGISTROS)
            : [];

        foreach ($montos_devoluciones as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            $cliente = $this->cliente_rotativo($indice + 2 + $offset_rotacion);

            $operaciones[] = $this->operacion($this->fecha_en_rango($inicio_mes, $dias_disponibles), 'devolucion', [
                'monto'     => $monto,
                'client_id' => $cliente->id,
            ]);

            $saldo_por_cliente_delta[$cliente->id] = ($saldo_por_cliente_delta[$cliente->id] ?? 0) - $monto;
        }

        // --- Compras a proveedores, repartidas entre los proveedores ----------------------------
        $montos_compras = ReportesMesSeeder::distribuir((int) $compras_total, self::CANT_REGISTROS);
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
                'importe_iva' => round($monto * 0.21, 2),
            ]);

            $saldo_por_proveedor_delta[$proveedor->id] = ($saldo_por_proveedor_delta[$proveedor->id] ?? 0) + $monto;
        }

        // --- Pagos a proveedores, repartidos entre los 10 proveedores y por método de pago -----
        $montos_pagos_prov = ReportesMesSeeder::distribuir((int) $pagos_proveedores_total, self::CANT_REGISTROS);

        foreach ($montos_pagos_prov as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            $proveedor = $this->proveedor_rotativo($indice + 1 + $offset_rotacion);
            // Los pagos a proveedor rotan entre efectivo, banco y Mercado Pago (no cheque acá: el
            // cheque emitido a proveedor es otro camino, `endosar()`, ya cubierto en el paso 4).
            $metodo_id = [3, 4, 6][$indice % 3];
            $caja_id = $this->semilla->caja_para($metodo_id, $primer_address_id);

            $operaciones[] = $this->operacion($this->fecha_en_rango($inicio_mes, $dias_disponibles), 'pago_a_proveedor', [
                'monto'       => $monto,
                'provider_id' => $proveedor->id,
                'metodos'     => [
                    ['current_acount_payment_method_id' => $metodo_id, 'amount' => $monto, 'caja_id' => $caja_id],
                ],
            ]);

            $saldo_por_proveedor_delta[$proveedor->id] = ($saldo_por_proveedor_delta[$proveedor->id] ?? 0) - $monto;

            // 🔴 Sale plata de caja de verdad: CurrentAcountPagoHelper::attachPaymentMethods()
            // llama CurrentAcountCajaHelper::guardar_pago(..., 'provider', ...), que registra un
            // EGRESO. Sin restar acá, la planilla ignoraba los pagos a proveedores por completo
            // (bug encontrado por el checker, 3/8/2026): "Sale de caja" del ejemplo del prompt
            // incluye explícitamente "4.000.000 a proveedores".
            $nombre_metodo_pago = self::CLAVE_POR_METODO[$metodo_id];
            $saldo_por_caja[$nombre_metodo_pago] = ($saldo_por_caja[$nombre_metodo_pago] ?? 0) - $monto;
        }

        // --- Gastos operativos, repartidos entre los 4 registros --------------------------------
        $montos_gastos = ReportesMesSeeder::distribuir((int) $gastos_total, self::CANT_REGISTROS);

        foreach ($montos_gastos as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);
            // La mitad de los gastos se paga (mueve caja); la otra mitad queda cargada sin pagar
            // (pasa a formar parte de "deuda" del negocio, no de un cliente/proveedor puntual) --
            // variación deliberada para que no todos los gastos toquen caja.
            $metodos_gasto = [];

            if ($indice % 2 === 0) {
                $metodos_gasto = [
                    [
                        'current_acount_payment_method_id' => 3,
                        'amount'                           => $monto,
                        'caja_id'                          => $this->semilla->caja_para(3, $primer_address_id),
                    ],
                ];
                $saldo_por_caja['efectivo'] = ($saldo_por_caja['efectivo'] ?? 0) - $monto;
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
            'comision_mercado_pago' => $this->comision_estimada(6, $saldo_por_caja),
            'entra_a_caja'         => $entra_a_caja_total,
            'iibb_estimado'        => $iibb_estimado,
            'saldo_por_caja'       => $saldo_por_caja,
            'delta_deuda_por_cliente'   => $saldo_por_cliente_delta,
            'delta_deuda_por_proveedor' => $saldo_por_proveedor_delta,
        ];

        return ['operaciones' => $operaciones, 'control' => $control];
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
     * Comisión que le va a cobrar la caja de un método de pago sobre lo que entró por él este mes.
     *
     * El porcentaje se lee de la caja real (`cajas.comision_porcentaje`), nunca del 5% hardcodeado:
     * el número lo configura `CajaSeeder` y una demo con otra configuración tiene que ver su propio
     * número en la planilla. Sin configuración, la comisión es 0 (que es lo que hace
     * `CajaLiquidacionHelper::tiene_configuracion()`).
     *
     * @param int $metodo_id
     * @param array<string,float> $saldo_por_caja
     * @return float
     */
    protected function comision_estimada($metodo_id, $saldo_por_caja)
    {
        $clave = self::CLAVE_POR_METODO[$metodo_id];
        $ingresos = isset($saldo_por_caja[$clave]) ? (float) $saldo_por_caja[$clave] : 0.0;

        if ($ingresos <= 0) {
            return 0.0;
        }

        $caja = Caja::find($this->semilla->caja_para($metodo_id, $this->addresses->first()->id));

        if (is_null($caja) || is_null($caja->comision_porcentaje)) {
            return 0.0;
        }

        return round($ingresos * ((float) $caja->comision_porcentaje / 100), 2);
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

        $this->resumen_aperturas = [
            'dias'  => $dias,
            'cajas' => $cajas->count(),
            'total' => $dias * $cajas->count(),
        ];

        $this->avisar_stock_negativo();
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

            default:
                throw new \Exception('semilla:datos: tipo de operación desconocido "'.$operacion['tipo'].'".');
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
            // ⚠️ `deuda_esperada_por_cliente` NO descuenta las cobranzas de cierre de tienda: el
            // resumen de `ActividadTiendaHelper` trae el total y la cantidad de clientes, pero no el
            // detalle por cliente. El monto está en `actividad_tienda.cobranzas_de_cierre.monto`.
            'deuda_esperada_por_cliente'   => $deuda_por_cliente,
            'deuda_esperada_por_proveedor' => $deuda_por_proveedor,
            'cheques_por_estado'       => $ciclo_cheques,
            'presupuestos_por_estado'  => $presupuestos,
            'aperturas_de_caja'        => $this->resumen_aperturas,
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
