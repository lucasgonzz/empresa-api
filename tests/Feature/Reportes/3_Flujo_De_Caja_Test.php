<?php

namespace Tests\Feature\Reportes;

use App\Models\Cheque;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Grupo 246 · Prompt 01 — tests del Flujo de Caja PERCIBIDO (`GET api/reportes/flujo-caja`, grupo
 * 226 prompt 01) y de su drill-down (`GET api/reportes/detalle`, grupo 226 prompt 02), con foco en
 * los dos riesgos cruzados entre el grupo 223 (comisión/liquidación de Mercado Pago) y el 226 que la
 * prueba manual marca explícitamente: la plata en tránsito NO tiene que sumar al flujo neto, y la
 * comisión de Mercado Pago (materializada como `Expense` por el grupo 223) tiene que aparecer una
 * sola vez, del lado de egresos.
 *
 * Reemplaza las 5 pruebas manuales pendientes del grupo `reportes-flujo-caja-drilldown`, y las
 * números 4 y 5 de `reportes-ui` (drill-down y paginación).
 *
 * Estrategia (igual que `1_Estado_Resultados_Test.php` y `2_Posicion_Fiscal_Test.php`): cada test
 * siembra su propio escenario acotado con `Tests\Concerns\EscenariosDePlata` en un rango de fechas
 * EXCLUSIVO (un mes distinto por test, todos en 2021, lejos de los rangos que ya usan los otros dos
 * archivos de este directorio). Las aserciones comparan contra el payload REAL de otro endpoint
 * (`GET api/caja` vía `saldos_de_caja()`) o contra valores leídos de la propia base (el monto real
 * que `MovimientoCajaHelper`/`CajaLiquidacionHelper` calcularon), nunca contra un número escrito a
 * mano — salvo los montos que el propio test siembra (esos sí son anclas válidas).
 *
 * Ubicación del reloj: todo vía `fijar_reloj_en()` del trait (grupo 260), nunca `Carbon::setTestNow()`
 * directo.
 *
 * @group reportes
 */
class Flujo_De_Caja_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /**
     * Libera todo lo que EscenariosDePlata haya creado en este test, antes del rollback de
     * DatabaseTransactions. Corre siempre, incluso si una aserción cortó el test a mitad de camino.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * Pega contra `GET api/reportes/flujo-caja` y devuelve el array `flujo_caja` ya decodificado, o
     * corta el test con `fail()` con el detalle completo si la respuesta no fue 200.
     *
     * @param string $desde
     * @param string $hasta
     * @param string $moneda 'pesos' | 'dolares' | 'consolidado'.
     * @return array
     */
    protected function pedir_flujo_caja($desde, $hasta, $moneda = 'pesos')
    {
        $response = $this->getJson('api/reportes/flujo-caja?desde='.$desde.'&hasta='.$hasta.'&moneda='.$moneda);

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
     * Pega contra `GET api/reportes/detalle` y devuelve el body ya decodificado, o corta el test con
     * `fail()` con el detalle completo si la respuesta no fue 200.
     *
     * @param string $concepto
     * @param string $desde
     * @param string $hasta
     * @param array $extra Query params adicionales (moneda, page, per_page, etc.).
     * @return array
     */
    protected function pedir_detalle($concepto, $desde, $hasta, $extra = [])
    {
        $query = array_merge([
            'concepto' => $concepto,
            'desde'    => $desde,
            'hasta'    => $hasta,
        ], $extra);

        $response = $this->getJson('api/reportes/detalle?'.http_build_query($query));

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/detalle devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        return json_decode($response->getContent(), true);
    }

    /**
     * Busca el `MovimientoCaja` de ingreso que generó una venta, para leer de la base los campos que
     * `CajaLiquidacionHelper::calcular()` ya resolvió (fecha de liquidación, neto y comisión) — nunca
     * se recalculan a mano en el test, para no arrastrar una fórmula de redondeo propia.
     *
     * @param int $sale_id
     * @return \App\Models\MovimientoCaja
     */
    protected function movimiento_de_venta($sale_id)
    {
        $movimiento = MovimientoCaja::where('sale_id', $sale_id)->whereNotNull('fecha_liquidacion_estimada')->first();

        if (is_null($movimiento)) {
            $this->fail(
                'No se encontró el MovimientoCaja con fecha_liquidacion_estimada de la venta '.$sale_id.'. '.
                'Revisar que SaleCajaHelper haya mandado aplica_liquidacion => true.'
            );
        }

        return $movimiento;
    }

    /**
     * Test 1 — Ingresos − egresos = flujo neto, y ese flujo neto coincide con el movimiento real de
     * la caja para el mismo período: se captura `saldo_disponible` de `CAJA_EFECTIVO` (payload real
     * de `GET api/caja`, vía `saldos_de_caja()`) antes y después de sembrar una venta y un gasto
     * conocidos, y la diferencia tiene que coincidir con `flujo_neto` — sin comisión ni liquidación
     * diferida de por medio (CAJA_EFECTIVO liquida ya), el movimiento de la caja y el flujo percibido
     * del período tienen que ser exactamente la misma plata.
     *
     * @group reportes
     * @test
     */
    public function flujo_neto_coincide_con_el_movimiento_real_de_la_caja()
    {
        $caja_efectivo = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $metodo_efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $saldo_antes = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_EFECTIVO)['saldo_disponible'];

        $this->fijar_reloj_en('2021-01-10 10:00:00');

        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 50000);
        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 15000, [
            'payment_methods' => [
                [
                    'current_acount_payment_method_id' => $metodo_efectivo->id,
                    'amount'                             => 15000,
                    'caja_id'                             => $caja_efectivo->id,
                ],
            ],
        ]);

        $saldo_despues = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_EFECTIVO)['saldo_disponible'];
        $movimiento_real_de_caja = $saldo_despues - $saldo_antes;

        $flujo = $this->pedir_flujo_caja('2021-01-01', '2021-01-31');

        // Ancla: la venta y el gasto que este mismo test acaba de sembrar (50000 - 15000).
        $this->assertEqualsWithDelta(35000, $movimiento_real_de_caja, self::DELTA, 'El movimiento real de saldo_disponible de la caja tendría que ser venta menos gasto.');

        $this->assertEqualsWithDelta(
            $movimiento_real_de_caja,
            (float) $flujo['flujo_neto'],
            self::DELTA,
            'flujo_neto tiene que coincidir con el movimiento real de saldo_disponible de la caja (payload real de GET api/caja) para el mismo período.'
        );
    }

    /**
     * Test 2 — 🔴 La plata en tránsito viene en un bloque aparte y NO está sumada dentro del flujo
     * neto: una venta por `CAJA_EFECTIVO` (liquida ya) y otra por `CAJA_MP` (14 días) en el mismo
     * período. El flujo neto tiene que reflejar el ingreso BRUTO de las dos ventas menos únicamente
     * la comisión de MP (que sí es un egreso real ya pagado) — nunca además la resta del monto que
     * está en tránsito, que sería contar la misma plata dos veces. El bloque de tránsito, por su
     * lado, sí tiene que traer ese monto neto estimado.
     *
     * Los montos de comparación (comisión y monto neto estimado) se leen del `MovimientoCaja`/
     * `Expense` reales que generó la propia venta, nunca se recalculan a mano.
     *
     * @group reportes
     * @test
     */
    public function plata_en_transito_no_esta_sumada_dentro_del_flujo_neto()
    {
        $this->fijar_reloj_en('2021-02-10 10:00:00');

        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 20000);
        $venta_mp = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_MP, TestingFerreteriaSeeder::PAGO_EFECTIVO, 30000);

        $movimiento_mp = $this->movimiento_de_venta($venta_mp->id);
        $monto_neto_estimado_mp = (float) $movimiento_mp->monto_neto_estimado;

        $comision_amount = 0.0;
        if (!is_null($movimiento_mp->comision_expense_id)) {
            $comision_expense = Expense::find($movimiento_mp->comision_expense_id);
            $comision_amount = is_null($comision_expense) ? 0.0 : (float) $comision_expense->amount;
        }

        $flujo = $this->pedir_flujo_caja('2021-02-01', '2021-02-28');

        // El neto esperado: las dos ventas por su monto BRUTO (20000 + 30000, sin descontar la
        // liquidación pendiente) menos únicamente la comisión ya pagada. Si el bloque de tránsito
        // se sumara (con signo negativo o restando de otra forma) dentro del flujo neto, este
        // número no coincidiría con lo que devuelve el endpoint.
        $neto_esperado = (20000 + 30000) - $comision_amount;

        $this->assertEqualsWithDelta(
            $neto_esperado,
            (float) $flujo['flujo_neto'],
            self::DELTA,
            'flujo_neto no puede incluir la plata en tránsito (liquidación pendiente de la venta por CAJA_MP): '.
            'tiene que reflejar el ingreso bruto de las dos ventas menos únicamente la comisión ya pagada. '.
            'Neto obtenido: '.$flujo['flujo_neto'].'. Neto esperado: '.$neto_esperado.
            '. Monto neto estimado en tránsito (NO debería estar restado del neto): '.$monto_neto_estimado_mp.
            '. Comisión ya pagada (sí debería estar restada del neto): '.$comision_amount
        );

        $this->assertEqualsWithDelta(
            $monto_neto_estimado_mp,
            (float) $flujo['plata_en_transito']['total_estimado'],
            self::DELTA,
            'plata_en_transito.total_estimado tiene que traer el monto neto estimado de la liquidación pendiente de la venta por CAJA_MP.'
        );
    }

    /**
     * Test 3 — 🔴 La comisión de Mercado Pago aparece exactamente una vez, como gasto: una venta de
     * $100.000 por `CAJA_MP` genera (grupo 223) un `Expense` automático con el concepto
     * `CONCEPTO_GASTO_COMISION`. Se verifica que ese concepto aparece UNA sola vez dentro del
     * desglose de gastos del flujo de caja (`gastos_pagados_por_categoria`, que a diferencia del
     * Estado de Resultados NO excluye la comisión — tarea 06 del prompt 226/01) — no duplicado ahí
     * adentro — y que el ingreso entra por su monto BRUTO, sin la resta adicional que lo contaría dos
     * veces (una vez como gasto, otra vez restado del ingreso).
     *
     * El monto de comisión se lee del `Expense` real (nunca se recalcula el 6.29% a mano).
     *
     * @group reportes
     * @test
     */
    public function comision_de_mercado_pago_aparece_exactamente_una_vez_como_gasto()
    {
        $this->fijar_reloj_en('2021-03-10 10:00:00');

        $venta_mp = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_MP, TestingFerreteriaSeeder::PAGO_EFECTIVO, 100000);

        $movimiento_mp = $this->movimiento_de_venta($venta_mp->id);

        if (is_null($movimiento_mp->comision_expense_id)) {
            $this->fail('La venta por CAJA_MP no generó ningún Expense de comisión (comision_expense_id nulo); no se puede probar este escenario.');
        }

        $comision_expense = Expense::find($movimiento_mp->comision_expense_id);
        $monto_comision = (float) $comision_expense->amount;

        $this->assertGreaterThan(0, $monto_comision, 'La comisión generada por la venta de $100.000 por CAJA_MP tendría que ser mayor a 0.');

        $flujo = $this->pedir_flujo_caja('2021-03-01', '2021-03-31');

        // Buscar el concepto de comisión dentro del desglose de gastos del flujo de caja y contar
        // cuántas veces aparece: tiene que ser exactamente una.
        $filas_de_comision = [];
        foreach ($flujo['gastos_pagados_por_categoria'] as $fila) {
            if ((int) $fila['expense_concept_id'] === (int) $comision_expense->expense_concept_id) {
                $filas_de_comision[] = $fila;
            }
        }

        $this->assertCount(
            1,
            $filas_de_comision,
            'El concepto "'.TestingFerreteriaSeeder::CONCEPTO_GASTO_COMISION.'" tiene que aparecer exactamente una vez en gastos_pagados_por_categoria. Filas encontradas: '.json_encode($filas_de_comision)
        );

        $this->assertEqualsWithDelta(
            $monto_comision,
            (float) $filas_de_comision[0]['total'],
            self::DELTA,
            'El total del concepto de comisión en el flujo de caja tiene que coincidir con el Expense real generado.'
        );

        // El ingreso tiene que entrar BRUTO: si el flujo restara la comisión también del lado de
        // ingresos, la contaría dos veces (una como gasto, otra restada del ingreso).
        $this->assertEqualsWithDelta(
            100000,
            (float) $flujo['total_ingresos'],
            self::DELTA,
            'total_ingresos tiene que ser el monto BRUTO de la venta (100000), sin descontar la comisión: restarla acá la contaría dos veces.'
        );

        $this->assertEqualsWithDelta(
            100000 - $monto_comision,
            (float) $flujo['flujo_neto'],
            self::DELTA,
            'flujo_neto tiene que ser el ingreso bruto (100000) menos la comisión UNA sola vez.'
        );
    }

    /**
     * Test 4 — Cheques diferidos en cartera aparecen listados en plata en tránsito junto con las
     * liquidaciones pendientes: se siembra a mano un `Cheque` recibido, no cobrado/rechazado, con
     * vencimiento futuro (el fixture de testing no trae ninguno — `Cheque::create()` directo, misma
     * excepción documentada en `2_Posicion_Fiscal_Test.php` para `AfipTicket`), junto con una venta
     * por `CAJA_MP` (liquidación pendiente). Las dos fuentes tienen que convivir en el mismo bloque de
     * tránsito. El cheque se borra en el `finally`, sembrado y limpiado por este test (no pasa por
     * `EscenariosDePlata`).
     *
     * @group reportes
     * @test
     */
    public function cheques_diferidos_aparecen_en_plata_en_transito_junto_con_liquidaciones_pendientes()
    {
        $this->fijar_reloj_en('2021-04-10 10:00:00');

        $venta_mp = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_MP, TestingFerreteriaSeeder::PAGO_EFECTIVO, 40000);
        $movimiento_mp = $this->movimiento_de_venta($venta_mp->id);
        $monto_neto_estimado_mp = (float) $movimiento_mp->monto_neto_estimado;

        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $usuario = $this->usuario_de_testing();

        $monto_cheque = 12345.67;

        $cheque = Cheque::create([
            'numero'                 => 'CH-TEST-3-FLUJO-CAJA',
            'banco'                  => 'Banco de Pruebas',
            'amount'                 => $monto_cheque,
            'fecha_emision'          => '2021-04-10',
            // Vencimiento futuro respecto del "hoy" de test (fijar_reloj_en deja Carbon::today() en
            // 2021-04-10): con esto, el cheque cae dentro de la ventana de "en cartera, por vencer".
            'fecha_pago'             => '2021-04-30',
            'tipo'                   => 'recibido',
            'client_id'              => $cliente->id,
            'user_id'                => $usuario->id,
            'estado_manual'          => null,
            'endosado_a_provider_id' => null,
        ]);

        try {
            $flujo = $this->pedir_flujo_caja('2021-04-01', '2021-04-30');
            $transito = $flujo['plata_en_transito'];

            $this->assertNotEmpty($transito['liquidaciones_pendientes'], 'La liquidación pendiente de la venta por CAJA_MP tendría que aparecer en liquidaciones_pendientes.');
            $this->assertNotEmpty($transito['cheques_diferidos'], 'El cheque sembrado en este test tendría que aparecer en cheques_diferidos.');

            $cheque_encontrado = null;
            foreach ($transito['cheques_diferidos'] as $fila) {
                if (abs((float) $fila['total'] - $monto_cheque) < self::DELTA) {
                    $cheque_encontrado = $fila;
                    break;
                }
            }

            $this->assertNotNull($cheque_encontrado, 'No se encontró el cheque sembrado (monto '.$monto_cheque.') dentro de cheques_diferidos. Cuerpo completo de cheques_diferidos: '.json_encode($transito['cheques_diferidos']));

            $this->assertEqualsWithDelta(
                $monto_neto_estimado_mp + $monto_cheque,
                (float) $transito['total_estimado'],
                self::DELTA,
                'total_estimado tiene que ser la suma de la liquidación pendiente y el cheque diferido, las dos fuentes conviviendo en el mismo bloque.'
            );
        } finally {
            Cheque::where('id', $cheque->id)->delete();
        }
    }

    /**
     * Test 5 — Negocio sin cajas con liquidación configurada (solo `CAJA_EFECTIVO`, que en el fixture
     * no tiene `dias_liquidacion`/`comision_porcentaje`): la plata en tránsito da cero y el reporte
     * funciona igual, sin romperse. Es el test de compatibilidad de la prueba manual: los clientes
     * que no usan tesorería avanzada no ven nada raro.
     *
     * @group reportes
     * @test
     */
    public function negocio_sin_cajas_con_liquidacion_configurada_da_transito_en_cero()
    {
        $this->fijar_reloj_en('2021-05-10 10:00:00');

        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 8000);

        $flujo = $this->pedir_flujo_caja('2021-05-01', '2021-05-31');

        $this->assertEqualsWithDelta(8000, (float) $flujo['total_ingresos'], self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $flujo['plata_en_transito']['total_estimado'], self::DELTA, 'Sin ninguna caja con liquidación diferida configurada, la plata en tránsito tiene que dar 0.');
        $this->assertEquals([], $flujo['plata_en_transito']['liquidaciones_pendientes']);
    }

    /**
     * Test 6 — Drill-down consistente: la suma de las líneas de `GET api/reportes/detalle` para el
     * concepto `gastos` (junio de 2021, sin ninguna venta por CAJA_MP en este escenario, así que no
     * hay comisión de por medio) tiene que ser exactamente igual a `gastos_pagados` del Flujo de Caja
     * para el mismo rango. Mismo criterio que el test 6 del grupo 245.
     *
     * @group reportes
     * @test
     */
    public function tarjeta_de_gastos_pagados_coincide_con_la_suma_del_detalle()
    {
        $this->fijar_reloj_en('2021-06-15 10:00:00');

        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 7000);
        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 2500);

        $flujo = $this->pedir_flujo_caja('2021-06-01', '2021-06-30');
        $monto_tarjeta = (float) $flujo['gastos_pagados'];

        $detalle = $this->pedir_detalle('gastos', '2021-06-01', '2021-06-30');

        $suma_detalle = 0.0;
        foreach ($detalle['registros'] as $registro) {
            $suma_detalle += (float) $registro['monto'];
        }

        $this->assertEqualsWithDelta($monto_tarjeta, $suma_detalle, self::DELTA, 'La suma de las líneas del detalle tiene que coincidir con la tarjeta gastos_pagados.');
        $this->assertEqualsWithDelta($monto_tarjeta, (float) $detalle['total'], self::DELTA, 'El "total" que devuelve el propio detalle tiene que coincidir con la tarjeta.');
    }

    /**
     * Test 7 — Drill-down de un rango de un año con muchos movimientos: responde 200 y paginado, sin
     * colgarse. Mismo criterio que el test 7 del grupo 245.
     *
     * @group reportes
     * @test
     */
    public function drill_down_de_ventas_brutas_en_rango_de_un_anio_devuelve_estructura_paginada()
    {
        $this->fijar_reloj_en('2021-07-05 10:00:00');

        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 1000);
        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 2000);
        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 3000);
        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 4000);

        $detalle = $this->pedir_detalle('ventas_brutas', '2021-01-01', '2021-12-31');

        $this->assertArrayHasKey('registros', $detalle);
        $this->assertArrayHasKey('paginacion', $detalle);
        $this->assertArrayHasKey('page', $detalle['paginacion']);
        $this->assertArrayHasKey('per_page', $detalle['paginacion']);
        $this->assertArrayHasKey('total_registros', $detalle['paginacion']);

        $this->assertEquals(4, $detalle['paginacion']['total_registros']);
        $this->assertLessThanOrEqual($detalle['paginacion']['per_page'], count($detalle['registros']));

        $suma_registros = 0.0;
        foreach ($detalle['registros'] as $registro) {
            $suma_registros += (float) $registro['monto'];
        }

        $this->assertEqualsWithDelta(1000 + 2000 + 3000 + 4000, $suma_registros, self::DELTA);
    }

    /**
     * Test 8 — Rango inclusivo en ambos extremos: una venta el primer día de agosto de 2021 y otra el
     * último tienen que entrar las dos en total_ingresos. Antes de assertear, se verifica que las dos
     * ventas cayeron efectivamente en días distintos (guard explícito del prompt, mismo criterio que
     * los tests 5/8 de los otros dos archivos de este directorio): si `fijar_reloj_en()` no separó el
     * reloj como se espera, este test no estaría probando el límite del rango y hay que pararlo y
     * reportarlo, no ajustar la aserción.
     *
     * @group reportes
     * @test
     */
    public function rango_de_fechas_incluye_ingreso_del_primer_dia_y_del_ultimo_dia()
    {
        $this->fijar_reloj_en('2021-08-01 08:00:00');
        $venta_primer_dia = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 6000);

        $this->fijar_reloj_en('2021-08-31 22:00:00');
        $venta_ultimo_dia = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 9000);

        // Guard duro del prompt: si las dos ventas cayeron el mismo día, no se está probando el
        // límite inclusivo del rango. Se para y se reporta, no se toca la aserción de abajo.
        if ($venta_primer_dia->created_at->format('Y-m-d') === $venta_ultimo_dia->created_at->format('Y-m-d')) {
            $this->fail(
                'Las dos ventas de este test cayeron el mismo día ('.$venta_primer_dia->created_at->format('Y-m-d').'), '.
                'en vez de en el primer y el último día del rango: fijar_reloj_en() no separó el reloj como se '.
                'esperaba. No se puede probar el límite inclusivo del rango en estas condiciones — esto es un '.
                'problema a reportar, no algo para ajustar en esta aserción.'
            );
        }

        $flujo = $this->pedir_flujo_caja('2021-08-01', '2021-08-31');

        $this->assertEqualsWithDelta(6000 + 9000, (float) $flujo['total_ingresos'], self::DELTA);
    }

    /**
     * Usuario dueño del fixture de testing (el mismo que autentica EmpresaTestCase::setUp()).
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return \App\Models\User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }
}
