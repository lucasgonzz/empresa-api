<?php

namespace App\Console\Commands;

use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\DeleteModelsHelper;
use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use App\Http\Controllers\Helpers\Seeders\SemillaHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Http\Controllers\SaleController;
use App\Models\Address;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\CompanyPerformance;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\Sale;
use Carbon\Carbon;
use Database\Seeders\ReportesMesSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $ventas_brutas_mes = (float) config('semilla.ventas_mes_base');
        $control_meses = [];

        // m = meses_atras - 1 es el mes más VIEJO (usa la base); m = 0 es el mes ACTUAL (parcial,
        // hasta hoy). Cada mes más cercano a hoy crece sobre el anterior.
        for ($m = $meses_atras - 1; $m >= 0; $m--) {
            if ($m < $meses_atras - 1) {
                $ventas_brutas_mes = $this->crecer($ventas_brutas_mes);
            }

            $es_mes_actual = ($m === 0);
            $this->info('Sembrando mes '.($meses_atras - $m).'/'.$meses_atras.' (meses_atras='.$m.', ventas brutas '.$ventas_brutas_mes.')'.($es_mes_actual ? ' -- mes actual, hasta hoy' : ''));

            $control_meses[] = $this->sembrar_mes($m, $ventas_brutas_mes, $es_mes_actual);
        }

        $this->info('Sembrando el bloque especial de hoy...');
        $control_hoy = $this->sembrar_hoy();

        $this->info('Aplicando ciclo de estados a los cheques recibidos...');
        $ciclo_cheques = $this->sembrar_cheques_con_ciclo();

        $this->info('Sembrando presupuestos en los tres estados...');
        $presupuestos = $this->sembrar_presupuestos();

        $this->escribir_planilla_de_control($control_meses, $control_hoy, $meses_atras, $ciclo_cheques, $presupuestos);

        $this->info('Listo.');

        return 0;
    }

    /**
     * Multiplica por (1 + crecimiento_mensual/100) y redondea al millón, para que ningún mes
     * tenga decimales.
     *
     * @param float $monto
     * @return float
     */
    protected function crecer($monto)
    {
        $crecido = $monto * (1 + ((float) config('semilla.crecimiento_mensual') / 100));

        return round($crecido / 1000000) * 1000000;
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

        $articulo = Article::where('user_id', $this->user_id)->first();
        if (is_null($articulo)) {
            throw new \Exception('semilla:datos: no hay ningún Article para el usuario '.$this->user_id.'.');
        }
        $this->articulo_id = $articulo->id;
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
     * @param int $meses_atras 0 = mes actual.
     * @param float $ventas_brutas_mes
     * @param bool $es_mes_actual Si es true, el mes se siembra solo hasta HOY (no hasta fin de mes).
     * @return array<string,mixed>
     */
    public function sembrar_mes($meses_atras, $ventas_brutas_mes, $es_mes_actual)
    {
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
        $devoluciones_total = round($ventas_brutas_mes * self::DEVOLUCIONES_FRACCION, 2);
        $ventas_netas = round($ventas_brutas_mes - $devoluciones_total, 2);
        $costo_total = round($ventas_netas * self::COSTO_FRACCION, 2);
        $resultado_bruto = round($ventas_netas - $costo_total, 2);
        $gastos_total = round($ventas_netas * self::GASTOS_FRACCION, 2);
        $resultado_operativo = round($resultado_bruto - $gastos_total, 2);
        $cobranza_cc_total = round($ventas_cc_total * self::COBRANZA_CC_FRACCION, 2);
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

        // --- Mostrador: efectivo por sucursal (reparto exacto, no aleatorio -- es lo que hace
        // que "la sucursal 1 facture el cuádruple que la 4" sea una cuenta verificable) ---------
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
        $mostrador_efectivo_total = round($mostrador_total * (self::MEZCLA_COBRO[3] + self::MEZCLA_COBRO[1]), 2);

        foreach ($this->addresses->values() as $indice => $address) {
            if ($indice >= count(self::REPARTO_SUCURSAL)) {
                break; // Si hubiera más de 4 sucursales, el resto no factura mostrador en efectivo.
            }
            $monto_sucursal = round($mostrador_efectivo_total * self::REPARTO_SUCURSAL[$indice], 2);
            if ($monto_sucursal <= 0) {
                continue;
            }
            $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);
            $caja_id = $this->semilla->caja_para(3, $address->id);

            $sale = $this->semilla->venta_mostrador($fecha, $monto_sucursal, $address->id, [
                ['current_acount_payment_method_id' => 3, 'amount' => $monto_sucursal, 'caja_id' => $caja_id],
            ]);
            // Comprobante de venta (factura A): es lo único que lee iva_debito() (filtra por
            // afip_fecha_emision, no por created_at -- ver PHPDoc de comprobante_de_venta()). IVA
            // al 21% sobre el monto, misma alícuota general usada en comprobante_de_compra().
            $this->semilla->comprobante_de_venta($fecha, $sale->id, round($monto_sucursal * 0.21, 2));

            $saldo_por_caja['efectivo'] = ($saldo_por_caja['efectivo'] ?? 0) + $monto_sucursal;
        }

        // --- Mostrador: Mercado Pago y Banco (compartidas, no atadas a una sucursal) -----------
        $primer_address_id = $this->addresses->first()->id;

        foreach ([6, 4] as $metodo_id) {
            $monto_metodo = round($mostrador_total * self::MEZCLA_COBRO[$metodo_id], 2);
            if ($monto_metodo <= 0) {
                continue;
            }

            $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);
            $caja_id = $this->semilla->caja_para($metodo_id, $primer_address_id);
            $address_venta = $this->addresses->get(array_rand($this->addresses->all()))->id;

            $sale = $this->semilla->venta_mostrador($fecha, $monto_metodo, $address_venta, [
                ['current_acount_payment_method_id' => $metodo_id, 'amount' => $monto_metodo, 'caja_id' => $caja_id],
            ]);
            $this->semilla->comprobante_de_venta($fecha, $sale->id, round($monto_metodo * 0.21, 2));

            $nombre = [6 => 'mercado_pago', 4 => 'banco'][$metodo_id];
            $saldo_por_caja[$nombre] = ($saldo_por_caja[$nombre] ?? 0) + $monto_metodo;
        }

        // --- Ventas a cuenta corriente, repartidas entre los 10 clientes -----------------------
        $montos_cc = ReportesMesSeeder::distribuir((int) $ventas_cc_total, self::CANT_REGISTROS);

        foreach ($montos_cc as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            $cliente = $this->cliente_rotativo($indice + $offset_rotacion);
            $address = $this->addresses->get($indice % $this->addresses->count());
            $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);

            $this->semilla->venta_cuenta_corriente($fecha, $monto, $cliente->id, $address->id);

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

                    $cobro = $this->semilla->cobro_cuenta_corriente($fecha, $monto_cheque, $cliente->id, [
                        [
                            'current_acount_payment_method_id' => 1,
                            'amount'                             => $monto_cheque,
                            'caja_id'                            => $caja_id,
                            'numero'                             => 'COB-'.$meses_atras.'-'.$indice_cheque.'-'.mt_rand(10000, 99999),
                            'banco'                               => 'Banco Nación',
                            'fecha_emision'                       => $fecha->format('Y-m-d'),
                            'fecha_pago'                           => $fecha->copy()->addDays(30)->format('Y-m-d'),
                        ],
                    ]);

                    $this->registrar_cheque_recibido_de($cobro);

                    $saldo_por_caja['cheques'] = ($saldo_por_caja['cheques'] ?? 0) + $monto_cheque;
                    $saldo_por_cliente_delta[$cliente->id] = ($saldo_por_cliente_delta[$cliente->id] ?? 0) - $monto_cheque;
                }

                continue;
            }

            $cliente = $this->cliente_rotativo($metodo_id + $offset_rotacion);
            $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);
            $caja_id = $this->semilla->caja_para($metodo_id, $primer_address_id);

            $cobro = $this->semilla->cobro_cuenta_corriente($fecha, $monto_metodo, $cliente->id, [
                ['current_acount_payment_method_id' => $metodo_id, 'amount' => $monto_metodo, 'caja_id' => $caja_id],
            ]);

            $nombre = ['3' => 'efectivo', '6' => 'mercado_pago', '4' => 'banco'][(string) $metodo_id];
            $saldo_por_caja[$nombre] = ($saldo_por_caja[$nombre] ?? 0) + $monto_metodo;
            $saldo_por_cliente_delta[$cliente->id] = ($saldo_por_cliente_delta[$cliente->id] ?? 0) - $monto_metodo;
        }

        // --- Devoluciones, repartidas entre clientes con ventas CC este mes ---------------------
        $montos_devoluciones = ReportesMesSeeder::distribuir((int) $devoluciones_total, self::CANT_REGISTROS);

        foreach ($montos_devoluciones as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            $cliente = $this->cliente_rotativo($indice + 2 + $offset_rotacion);
            $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);

            $this->semilla->devolucion($fecha, $monto, $cliente->id);

            $saldo_por_cliente_delta[$cliente->id] = ($saldo_por_cliente_delta[$cliente->id] ?? 0) - $monto;
        }

        // --- Compras a proveedores, repartidas entre los 10 proveedores -------------------------
        $montos_compras = ReportesMesSeeder::distribuir((int) $compras_total, self::CANT_REGISTROS);

        foreach ($montos_compras as $indice => $monto) {
            if ($monto <= 0) {
                continue;
            }
            $proveedor = $this->proveedor_rotativo($indice + $offset_rotacion);
            $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);

            $order = $this->semilla->compra_a_proveedor($fecha, $monto, $proveedor->id);

            // Comprobante de compra: base para iva_credito()/percepciones/retenciones. IVA al 21%
            // sobre el monto (alícuota general, no configurable acá -- esta semilla no modela
            // percepciones/retenciones reales, quedan en 0 a propósito).
            $this->semilla->comprobante_de_compra($fecha, $order->id, [
                'total_iva' => round($monto * 0.21, 2),
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
            $fecha = $this->fecha_en_rango($inicio_mes, $dias_disponibles);
            // Los pagos a proveedor rotan entre efectivo, banco y Mercado Pago (no cheque acá: el
            // cheque emitido a proveedor es otro camino, `endosar()`, ya cubierto en el paso 4).
            $metodo_id = [3, 4, 6][$indice % 3];
            $caja_id = $this->semilla->caja_para($metodo_id, $primer_address_id);

            $this->semilla->pago_a_proveedor($fecha, $monto, $proveedor->id, [
                ['current_acount_payment_method_id' => $metodo_id, 'amount' => $monto, 'caja_id' => $caja_id],
            ]);

            $saldo_por_proveedor_delta[$proveedor->id] = ($saldo_por_proveedor_delta[$proveedor->id] ?? 0) - $monto;

            // 🔴 Sale plata de caja de verdad: CurrentAcountPagoHelper::attachPaymentMethods()
            // llama CurrentAcountCajaHelper::guardar_pago(..., 'provider', ...), que registra un
            // EGRESO. Sin restar acá, la planilla ignoraba los pagos a proveedores por completo
            // (bug encontrado por el checker, 3/8/2026): "Sale de caja" del ejemplo del prompt
            // incluye explícitamente "4.000.000 a proveedores".
            $nombre_metodo_pago = [3 => 'efectivo', 4 => 'banco', 6 => 'mercado_pago'][$metodo_id];
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
            if ($indice % 2 === 0) {
                $caja_id = $this->semilla->caja_para(3, $primer_address_id);
                $this->semilla->gasto($fecha, $monto, $this->expense_concept_id, [
                    ['current_acount_payment_method_id' => 3, 'amount' => $monto, 'caja_id' => $caja_id],
                ]);
                $saldo_por_caja['efectivo'] = ($saldo_por_caja['efectivo'] ?? 0) - $monto;
            } else {
                $this->semilla->gasto($fecha, $monto, $this->expense_concept_id, []);
            }
        }

        return [
            'meses_atras'          => $meses_atras,
            'mes'                  => $inicio_mes->format('Y-m'),
            'es_mes_actual'        => $es_mes_actual,
            'ventas_brutas'        => $ventas_brutas_mes,
            'ventas_mostrador'     => $mostrador_total,
            'ventas_cuenta_corriente' => $ventas_cc_total,
            'devoluciones'         => $devoluciones_total,
            'ventas_netas'         => $ventas_netas,
            'costo'                => $costo_total,
            'resultado_bruto'      => $resultado_bruto,
            'gastos'               => $gastos_total,
            'resultado_operativo'  => $resultado_operativo,
            'cobranza_cuenta_corriente' => $cobranza_cc_total,
            'compras_proveedores'  => $compras_total,
            'pagos_proveedores'    => $pagos_proveedores_total,
            'entra_a_caja'         => $entra_a_caja_total,
            'iibb_estimado'        => $iibb_estimado,
            'saldo_por_caja'       => $saldo_por_caja,
            'delta_deuda_por_cliente'   => $saldo_por_cliente_delta,
            'delta_deuda_por_proveedor' => $saldo_por_proveedor_delta,
        ];
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
     * @return void
     */
    protected function escribir_planilla_de_control($control_meses, $control_hoy, $meses_atras, $ciclo_cheques, $presupuestos)
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

        $control = [
            'parametros' => [
                'meses_atras'         => $meses_atras,
                'ventas_mes_base'     => (float) config('semilla.ventas_mes_base'),
                'crecimiento_mensual' => (float) config('semilla.crecimiento_mensual'),
                'semilla_aleatoria'   => (int) config('semilla.semilla_aleatoria'),
                'user_id'             => $this->user_id,
                'fecha_de_corrida'    => Carbon::now()->format('Y-m-d H:i:s'),
            ],
            'meses'                    => $control_meses,
            'hoy'                      => $control_hoy,
            'saldo_esperado_por_caja'  => $saldo_por_caja_total,
            'deuda_esperada_por_cliente'   => $deuda_por_cliente,
            'deuda_esperada_por_proveedor' => $deuda_por_proveedor,
            'cheques_por_estado'       => $ciclo_cheques,
            'presupuestos_por_estado'  => $presupuestos,
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
    }

    /**
     * Grupo 321 · Prompt 06 — wrapper público de `resetear()` para el `tearDown()` de un test de
     * aceptación: borra por el mismo camino real de controllers todo lo que `sembrar_mes()` haya
     * sembrado para `config('semilla.user_id')` (ventas, cuentas corrientes, comprobantes,
     * cheques), sin loguear nada por consola. Requiere haber llamado antes `preparar_para_test()`
     * (usa `$this->user_id`).
     *
     * 🔴 NO borra `movimiento_cajas` (hallazgo del checker, 3/8/2026): `resetear()` llama a
     * `SaleController::destroy()`/`CurrentAcountController::delete()`/`ExpenseController::destroy()`
     * con un `Request::create('/')` sin `compensar_caja`, así que esos controllers nunca generan el
     * movimiento de compensación, y no hay ningún `MovimientoCaja::...->delete()` en `resetear()`.
     * En un test bajo `DatabaseTransactions` no importa (el rollback se encarga); fuera de un test
     * (`semilla:datos --reset` en la instancia demo) los saldos de caja quedan inflados corrida
     * tras corrida -- deuda técnica preexistente de `resetear()` (prompt 05), no introducida acá.
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
