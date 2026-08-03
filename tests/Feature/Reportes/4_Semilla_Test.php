<?php

namespace Tests\Feature\Reportes;

use App\Console\Commands\SembrarDatosDePrueba;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Address;
use App\Models\AfipTicket;
use App\Models\Caja;
use App\Models\Client;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\Provider;
use App\Models\ProviderOrderAfipTicket;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Grupo 321 · Prompt 06 — test de aceptación: los reportes reales (Estado de Resultados, Posición
 * Fiscal, Flujo de Caja) tienen que dar exactamente lo que dice la planilla de control que arma
 * `SembrarDatosDePrueba::sembrar_mes()` (grupo 321, prompt 05).
 *
 * Este archivo NO corre el comando `semilla:datos` completo (sembraría varios meses y pisaría los
 * rangos de fecha de los otros archivos de esta carpeta). En su lugar invoca `sembrar_mes()`
 * directamente sobre UN solo mes, vía los dos puntos de entrada públicos que el prompt 06 agregó a
 * `SembrarDatosDePrueba` (`preparar_para_test()` + `sembrar_mes()` público).
 *
 * Rango de fechas propio: ABRIL DE 2018. Verificado archivo por archivo, no solo por el PHPDoc
 * (instrucción explícita del checker):
 * - `1_Estado_Resultados_Test.php` usa enero, marzo, mayo y junio de 2019, y todo 2016.
 * - `2_Posicion_Fiscal_Test.php` usa julio a diciembre de 2019, y febrero de 2020.
 * - `3_Flujo_De_Caja_Test.php` usa enero a agosto de 2021.
 * 2018 no aparece en ninguno de los tres.
 *
 * Fixture propio (`preparar_fixture_de_semilla()`): `SembrarDatosDePrueba::cargar_catalogo()`
 * necesita 4 sucursales (tantas como `SembrarDatosDePrueba::REPARTO_SUCURSAL`) y una caja por
 * defecto por método de pago para cada una; `TestingFerreteriaSeeder` solo trae UNA dirección
 * ("Principal") y ninguna `DefaultPaymentMethodCaja`. Se completa lo que falta directo por
 * Eloquent (no por endpoint): es setup del escenario, no el comportamiento bajo prueba -- misma
 * excepción que ya usa `EscenariosDePlata::configurar_override_caja()`. A propósito se arma UNA
 * sola caja física por método de pago, COMPARTIDA entre las 4 sucursales (a diferencia de
 * `CajaSeeder` en producción, que abre una caja de efectivo por sucursal): así cada clave de
 * `saldo_por_caja` que devuelve `sembrar_mes()` (una por MÉTODO, no por sucursal) corresponde a
 * exactamente UNA caja real, que es lo que permite comparar caja por caja en el test 3 (checker:
 * "que compare cada caja, no solo el total, para no tapar dos cajas cruzadas").
 *
 * 🔴 Punto ciego conocido de esa elección (hallazgo del checker, 3/8/2026): con una única caja de
 * efectivo compartida, este archivo NO puede detectar plata que `SemillaHelper::caja_para()`
 * hubiera mandado a la sucursal equivocada -- solo que el TOTAL de efectivo entre las 4
 * sucursales cierre. Probar eso de verdad requeriría que `saldo_por_caja` de `sembrar_mes()` se
 * indexara por caja real (o por sucursal), no por método de pago; queda fuera de este prompt.
 *
 * Ubicación del reloj: todo vía `fijar_reloj_en()` del trait (grupo 260), nunca `Carbon::setTestNow()`
 * directo.
 *
 * @group reportes
 * @group semilla
 */
class Semilla_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la carpeta). */
    const DELTA = 0.01;

    /**
     * Métodos de pago de cuenta corriente (ver CurrentAcountPaymentMethodSeeder) que arma
     * `preparar_fixture_de_semilla()`, indexados por la clave que usa `saldo_por_caja` en la
     * planilla de `sembrar_mes()`.
     */
    const METODOS_DE_PAGO = [
        3 => 'efectivo',
        6 => 'mercado_pago',
        4 => 'banco',
        1 => 'cheques',
    ];

    /** @var array<string,string> Clave de la planilla => nombre real de la caja de prueba. */
    protected $nombre_caja_por_clave = [];

    /** Renglón de control que devolvió `sembrar_mes()` para el mes sembrado por este test. */
    protected $control;

    /**
     * Arma el fixture que le falta a `TestingFerreteriaSeeder` para poder invocar la semilla,
     * fija el reloj en el mes exclusivo de este archivo y siembra ESE único mes, guardando su
     * planilla de control en `$this->control` para que los 5 tests de abajo comparen contra ella.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_fixture_de_semilla();

        $this->fijar_reloj_en('2018-04-15 10:00:00');

        $comando = new SembrarDatosDePrueba();
        $comando->preparar_para_test();

        $this->control = $comando->sembrar_mes(0, (float) config('semilla.ventas_mes_base'), false);

        // 🔴 Ojo si algún test nuevo necesita que "hoy" siga siendo abril de 2018 después de este
        // punto: cada primitiva de SemillaHelper restaura Carbon::setTestNow(null) en su propio
        // finally, así que el reloj vuelve al real apenas termina sembrar_mes(). No afecta a los 5
        // tests de este archivo (piden los reportes con desde/hasta explícitos), pero no asumir
        // que el reloj sigue fijado acá.
    }

    /**
     * Limpia lo que sembró `sembrar_mes()` (ventas, cuentas corrientes, comprobantes, cheques) por
     * el mismo camino real que usa `SembrarDatosDePrueba::resetear()`, antes del rollback de
     * `DatabaseTransactions` -- red real redundante, no la única (ver PHPDoc de
     * `EscenariosDePlata::limpiar_escenarios()` sobre por qué esta suite no confía únicamente en el
     * rollback). El fixture propio (sucursales/cajas/defaults que agregó
     * `preparar_fixture_de_semilla()`) no necesita limpieza aparte: se creó dentro de la misma
     * transacción del test y el rollback lo revierte solo. Envuelto en try/finally para que un
     * `preparar_para_test()`/`limpiar_para_test()` que tire igual deje correr `parent::tearDown()`
     * (y su rollback), en vez de dejar la transacción abierta.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        try {
            $comando = new SembrarDatosDePrueba();
            $comando->preparar_para_test();
            $comando->limpiar_para_test();

            $this->limpiar_escenarios();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Completa el catálogo que `SembrarDatosDePrueba::cargar_catalogo()` necesita y que
     * `TestingFerreteriaSeeder` no trae: 4 sucursales, una caja por método de pago (compartida
     * entre las 4) y `credit_account` (moneda 1) para cada cliente/proveedor del fixture -- sin
     * esto último, `SemillaHelper::credit_account_para()` corta la siembra con una excepción en la
     * primera cobranza/compra/pago/devolución.
     *
     * @return void
     */
    protected function preparar_fixture_de_semilla()
    {
        $user_id = config('semilla.user_id');

        $direcciones = Address::where('user_id', $user_id)->orderBy('id')->get();

        while ($direcciones->count() < 4) {
            $nueva = Address::create([
                'street'  => 'Sucursal semilla '.($direcciones->count() + 1),
                'user_id' => $user_id,
            ]);
            $direcciones->push($nueva);
        }

        // Defensivo: si algún día `TestingFerreteriaSeeder` empieza a sembrar sus propios defaults
        // (hoy no lo hace), `SemillaHelper::caja_para()` podría resolver esos en vez de los de este
        // test (usa `->first()`, sin desempate) y el saldo esperado se iría a la caja equivocada.
        // Se borran los defaults de estos 4 métodos para este usuario antes de crear los propios.
        DefaultPaymentMethodCaja::where('user_id', $user_id)
            ->whereIn('current_acount_payment_method_id', array_keys(self::METODOS_DE_PAGO))
            ->delete();

        $siguiente_num = (int) (Caja::where('user_id', $user_id)->max('num') ?? 0) + 1;

        foreach (self::METODOS_DE_PAGO as $metodo_id => $clave) {
            $nombre = 'Caja semilla '.$clave;

            $caja = Caja::create([
                'num'     => $siguiente_num,
                'name'    => $nombre,
                'user_id' => $user_id,
                'saldo'   => 0,
            ]);
            $siguiente_num++;

            // Sin abrirla, MovimientoCajaHelper::get_current_aperutra_caja() no encuentra ningún
            // AperturaCaja y revienta ("Trying to get property 'id' of non-object") en la primera
            // venta que intenta mover esta caja -- mismo paso que hace CajaSeeder::abrir_caja() en
            // producción.
            (new CajaAperturaHelper($caja->id))->abrir_caja();

            $this->nombre_caja_por_clave[$clave] = $nombre;

            foreach ($direcciones as $direccion) {
                DefaultPaymentMethodCaja::create([
                    'current_acount_payment_method_id' => $metodo_id,
                    'address_id'                        => $direccion->id,
                    'caja_id'                            => $caja->id,
                    'user_id'                            => $user_id,
                ]);
            }
        }

        foreach (Client::where('user_id', $user_id)->get() as $cliente) {
            CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $user_id);
        }

        foreach (Provider::where('user_id', $user_id)->get() as $proveedor) {
            CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $user_id);
        }
    }

    /**
     * Pega contra `GET api/reportes/estado-resultados` y devuelve el array `estado_resultados` ya
     * decodificado, o corta el test con `fail()` con el detalle completo si la respuesta no fue 200.
     *
     * @param string $desde
     * @param string $hasta
     * @return array
     */
    protected function pedir_estado_resultados($desde, $hasta)
    {
        $response = $this->getJson('api/reportes/estado-resultados?desde='.$desde.'&hasta='.$hasta.'&moneda=pesos');

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/estado-resultados devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $body = json_decode($response->getContent(), true);

        if (!isset($body['estado_resultados'])) {
            $this->fail('La respuesta de GET api/reportes/estado-resultados no trae la clave "estado_resultados". Cuerpo completo: '.$response->getContent());
        }

        return $body['estado_resultados'];
    }

    /**
     * Pega contra `GET api/reportes/posicion-fiscal` y devuelve el array `posicion_fiscal` ya
     * decodificado, o corta el test con `fail()` con el detalle completo si la respuesta no fue 200.
     *
     * @param string $desde
     * @param string $hasta
     * @return array
     */
    protected function pedir_posicion_fiscal($desde, $hasta)
    {
        $response = $this->getJson('api/reportes/posicion-fiscal?desde='.$desde.'&hasta='.$hasta);

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/posicion-fiscal devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $body = json_decode($response->getContent(), true);

        if (!isset($body['posicion_fiscal'])) {
            $this->fail('La respuesta de GET api/reportes/posicion-fiscal no trae la clave "posicion_fiscal". Cuerpo completo: '.$response->getContent());
        }

        return $body['posicion_fiscal'];
    }

    /**
     * Pega contra `GET api/reportes/flujo-caja` y devuelve el array `flujo_caja` ya decodificado, o
     * corta el test con `fail()` con el detalle completo si la respuesta no fue 200.
     *
     * @param string $desde
     * @param string $hasta
     * @return array
     */
    protected function pedir_flujo_caja($desde, $hasta)
    {
        $response = $this->getJson('api/reportes/flujo-caja?desde='.$desde.'&hasta='.$hasta.'&moneda=pesos');

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/flujo-caja devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $body = json_decode($response->getContent(), true);

        if (!isset($body['flujo_caja'])) {
            $this->fail('La respuesta de GET api/reportes/flujo-caja no trae la clave "flujo_caja". Cuerpo completo: '.$response->getContent());
        }

        return $body['flujo_caja'];
    }

    /**
     * Test 1 — El ancla de toda la aritmética de la semilla: con el costeo al 50% del precio que
     * usa `SemillaHelper` en cada línea vendida, el resultado bruto del Estado de Resultados tiene
     * que ser la mitad de las ventas netas del mismo mes sembrado. Si esto se pone rojo, o cambió
     * el costeo del sistema o cambió la semilla, y en los dos casos hay que mirar (no ajustar esta
     * aserción para que pase).
     *
     * @group reportes
     * @group semilla
     * @test
     */
    public function resultado_bruto_es_la_mitad_de_las_ventas_netas()
    {
        $estado = $this->pedir_estado_resultados('2018-04-01', '2018-04-30');

        $this->assertGreaterThan(0, (float) $estado['ventas_netas'], 'Sin ventas netas sembradas, este test no prueba nada.');

        // Ancla contra la propia planilla de control (no solo la relación entre renglones):
        // resultado_bruto real tiene que coincidir con lo que sembrar_mes() dice que va a dar.
        $this->assertEqualsWithDelta(
            (float) $this->control['resultado_bruto'],
            (float) $estado['resultado_bruto'],
            self::DELTA,
            'resultado_bruto del reporte no coincide con resultado_bruto de la planilla de control.'
        );

        $this->assertEqualsWithDelta(
            0.5 * (float) $estado['ventas_netas'],
            (float) $estado['resultado_bruto'],
            self::DELTA,
            'resultado_bruto tiene que ser exactamente la mitad de ventas_netas, dado el costeo al 50% que usa SemillaHelper en cada línea vendida.'
        );
    }

    /**
     * Test 2 — Verifica que las devoluciones se estén sembrando Y que el Estado de Resultados las
     * esté restando: `sembrar_mes()` siembra devoluciones por el 10% de las ventas brutas del mes
     * (`SembrarDatosDePrueba::DEVOLUCIONES_FRACCION`), así que ventas_netas tiene que dar
     * exactamente el 90% de ventas_brutas.
     *
     * @group reportes
     * @group semilla
     * @test
     */
    public function ventas_netas_son_el_noventa_por_ciento_de_las_brutas()
    {
        $estado = $this->pedir_estado_resultados('2018-04-01', '2018-04-30');

        $this->assertGreaterThan(0, (float) $estado['devoluciones'], 'Sin devoluciones sembradas, este test no prueba nada: tienen que estar la semilla las sembró.');

        // Ancla contra la propia planilla de control.
        $this->assertEqualsWithDelta(
            (float) $this->control['ventas_netas'],
            (float) $estado['ventas_netas'],
            self::DELTA,
            'ventas_netas del reporte no coincide con ventas_netas de la planilla de control.'
        );

        $fraccion_neta = 1 - SembrarDatosDePrueba::DEVOLUCIONES_FRACCION;

        $this->assertEqualsWithDelta(
            $fraccion_neta * (float) $estado['ventas_brutas'],
            (float) $estado['ventas_netas'],
            self::DELTA,
            'ventas_netas tiene que ser (1 - DEVOLUCIONES_FRACCION) de ventas_brutas.'
        );
    }

    /**
     * Test 3 — El saldo de CADA caja (no solo el total) coincide con lo que dice la planilla de
     * control. Es el que atrapa el modo de falla que originó este grupo entero: operaciones que
     * suceden pero no llegan a la caja, o que llegan a la caja equivocada (un total que cierra
     * puede tapar dos cajas cruzadas -- por eso se compara caja por caja, nunca la suma).
     *
     * @group reportes
     * @group semilla
     * @test
     */
    public function el_saldo_de_cada_caja_coincide_con_la_planilla()
    {
        $this->assertNotEmpty($this->control['saldo_por_caja'], 'La planilla de este mes no dejó ningún saldo_por_caja esperado -- no hay nada que comparar.');

        foreach ($this->control['saldo_por_caja'] as $clave => $monto_esperado) {
            $this->assertArrayHasKey($clave, $this->nombre_caja_por_clave, 'saldo_por_caja trae una clave ("'.$clave.'") sin caja de prueba asociada.');

            $saldo_real = $this->saldos_de_caja($this->nombre_caja_por_clave[$clave])['saldo_contable'];

            $this->assertEqualsWithDelta(
                (float) $monto_esperado,
                $saldo_real,
                self::DELTA,
                'El saldo contable de la caja "'.$this->nombre_caja_por_clave[$clave].'" ('.$clave.') no coincide con la planilla.'
            );
        }
    }

    /**
     * Test 4 — El desglose por caja y método del Flujo de Caja tiene que sumar exactamente el
     * total de ingresos del período: si una operación entra al total pero cae en "sin caja" (o se
     * pierde) en el desglose, esta suma deja de cerrar.
     *
     * @group reportes
     * @group semilla
     * @test
     */
    public function los_ingresos_por_caja_y_metodo_suman_el_total_de_ingresos()
    {
        $flujo = $this->pedir_flujo_caja('2018-04-01', '2018-04-30');

        $this->assertGreaterThan(0, (float) $flujo['total_ingresos'], 'Sin ingresos sembrados, este test no prueba nada.');

        $suma_desglose = 0.0;
        foreach ($flujo['ingresos_por_caja_metodo'] as $renglon) {
            $suma_desglose += (float) $renglon['total'];
        }

        $this->assertEqualsWithDelta(
            (float) $flujo['total_ingresos'],
            $suma_desglose,
            self::DELTA,
            'La suma de ingresos_por_caja_metodo tiene que coincidir exactamente con total_ingresos.'
        );
    }

    /**
     * Test 5 — La Posición Fiscal no da cero: con comprobantes AFIP y `sale_taxes` sembrados por
     * `sembrar_mes()`, IVA débito, IVA crédito e IIBB determinado tienen que ser todos mayores a
     * cero (sin comprobantes/impuestos configurados el reporte da cero entero, que es como está
     * hoy sin la semilla). IVA débito e IVA crédito, además, coinciden exactamente con lo que
     * queda en `afip_tickets`/`provider_order_afip_tickets` para este mes -- las mismas tablas y
     * los mismos filtros que usa `ContabilidadRepository::iva_debito()`/`iva_credito()` (no un
     * número memorizado de antemano).
     *
     * @group reportes
     * @group semilla
     * @test
     */
    public function la_posicion_fiscal_no_da_cero()
    {
        $user_id = config('semilla.user_id');

        $iva_debito_esperado = (float) AfipTicket::query()
            ->join('sales', 'sales.id', '=', 'afip_tickets.sale_id')
            ->where('sales.user_id', $user_id)
            ->where('afip_tickets.resultado', 'A')
            ->whereDate('afip_tickets.afip_fecha_emision', '>=', '2018-04-01')
            ->whereDate('afip_tickets.afip_fecha_emision', '<=', '2018-04-30')
            ->sum('afip_tickets.importe_iva');

        $iva_credito_esperado = (float) ProviderOrderAfipTicket::where('user_id', $user_id)
            ->whereNotNull('total_iva')
            ->whereDate('issued_at', '>=', '2018-04-01')
            ->whereDate('issued_at', '<=', '2018-04-30')
            ->sum('total_iva');

        $this->assertGreaterThan(0, $iva_debito_esperado, 'La semilla tiene que haber registrado al menos un comprobante de venta con IVA -- si no, este test no prueba nada.');
        $this->assertGreaterThan(0, $iva_credito_esperado, 'La semilla tiene que haber registrado al menos un comprobante de compra con IVA -- si no, este test no prueba nada.');

        $posicion = $this->pedir_posicion_fiscal('2018-04-01', '2018-04-30');
        $iva = $posicion['posicion_iva'];
        $iibb = $posicion['posicion_iibb'];

        $this->assertEqualsWithDelta($iva_debito_esperado, (float) $iva['iva_debito'], self::DELTA);
        $this->assertEqualsWithDelta($iva_credito_esperado, (float) $iva['iva_credito'], self::DELTA);

        $this->assertGreaterThan(0, (float) $iva['iva_debito']);
        $this->assertGreaterThan(0, (float) $iva['iva_credito']);
        $this->assertGreaterThan(0, (float) $iibb['iibb_determinado'], 'Con sale_taxes activos y ventas con items sembradas, iibb_determinado tiene que ser mayor a cero.');
    }
}
