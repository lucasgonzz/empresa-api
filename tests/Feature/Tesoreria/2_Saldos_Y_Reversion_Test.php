<?php

namespace Tests\Feature\Tesoreria;

use App\Models\Caja;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Grupo 243 · Prompt 03 — tests de los tres saldos (contable, disponible, a liquidar), la
 * reversión de una venta/cobro comisionado, y la línea de tiempo de liquidaciones pendientes.
 * Reemplaza las pruebas manuales pendientes 1, 4, 7 y 8 del grupo tesoreria-liquidaciones-comisiones.
 *
 * Un descuadre de saldo no rompe nada visible (el sistema sigue mostrando un número), así que es
 * la parte del grupo 223 que menos se detecta a ojo y más necesita este tipo de test.
 *
 * Todos los saldos se leen SIEMPRE con `saldos_de_caja()` del trait `EscenariosDePlata` (payload
 * real de `CajaController@index`), nunca recalculados a mano en el test.
 *
 * Nota de aislamiento (leer antes de tocar este archivo): estos son los primeros tests de la
 * suite que dependen de valores relativos al estado previo de la caja (saldos acumulados), no de
 * valores absolutos. Por eso TODAS las aserciones de saldo comparan una foto de "antes" contra una
 * de "después" (patrón delta), nunca un número fijo — así siguen siendo correctas aunque el guard
 * de InnoDB del grupo 242 no esté en verde y la base venga contaminada de un test anterior.
 *
 * @group tesoreria
 */
class Saldos_Y_Reversion_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /**
     * Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite de tesorería).
     */
    const DELTA = 0.01;

    /**
     * Libera todo lo que el trait EscenariosDePlata haya creado en este test (ventas, gastos,
     * cobros, overrides, aperturas, movimientos manuales) antes de que corra el rollback de la
     * transacción de DatabaseTransactions. Corre siempre, incluso si una aserción cortó el test a
     * mitad de camino, porque PHPUnit ejecuta tearDown() de todas formas.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * Inserta a mano un MovimientoCaja con las tres columnas de liquidación (fecha_liquidacion_estimada,
     * monto_neto_estimado, comision_calculada) en null, simulando una fila anterior a la migración
     * del grupo 223 — algo que ningún endpoint actual produce, porque hoy siempre pasan por
     * `MovimientoCajaHelper::crear_movimiento()`, que calcula esos campos (aunque sea a null, por
     * "sin config"). Es la ÚNICA excepción de esta suite a la regla de "todo por endpoint" de
     * `EscenariosDePlata`, documentada explícitamente acá y en el test que la usa (test 2).
     *
     * Como el insert es directo, replica a mano lo que `MovimientoCajaHelper::set_saldos()` hace
     * siempre después de crear un movimiento: suma el ingreso al saldo contable de la caja.
     *
     * @param \App\Models\Caja $caja Caja donde se simula el movimiento histórico (debe estar abierta).
     * @param float $monto Monto del ingreso simulado.
     * @return \App\Models\MovimientoCaja
     */
    protected function crear_movimiento_historico($caja, $monto)
    {
        $caja->refresh();

        $movimiento = MovimientoCaja::create([
            'concepto_movimiento_caja_id' => 1,
            'ingreso'                     => $monto,
            'egreso'                      => null,
            'notas'                       => 'Fila histórica simulada (pre-migración grupo 223) — test 2 del prompt 243-03',
            'employee_id'                 => null,
            'apertura_caja_id'            => $caja->current_apertura_caja_id,
            'sale_id'                     => null,
            'expense_id'                  => null,
            'caja_id'                     => $caja->id,
            // Las tres columnas del grupo 223 quedan en null a propósito: es lo que se está simulando.
            'fecha_liquidacion_estimada'  => null,
            'monto_neto_estimado'         => null,
            'comision_calculada'          => null,
        ]);

        // Réplica a mano de set_saldos(): el insert directo no pasa por MovimientoCajaHelper.
        $caja->saldo = (float) $caja->saldo + $monto;
        $caja->save();

        // Se registra en el trait para que limpiar_escenarios() lo borre en el tearDown, igual que
        // cualquier otro movimiento creado por los helpers de EscenariosDePlata.
        $this->movimientos_caja_creados_por_escenarios[] = $movimiento->id;

        return $movimiento;
    }

    /**
     * Test 1 (compatibilidad) — después de una venta de $1.000 por CAJA_EFECTIVO (sin ninguna
     * config de liquidación/comisión), los tres saldos se mueven todos por igual: es el
     * comportamiento de siempre, sin ningún tratamiento especial.
     *
     * @group tesoreria
     * @test
     */
    public function caja_efectivo_despues_de_una_venta_los_tres_saldos_se_mueven_igual()
    {
        $saldos_antes = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            1000
        );

        $saldos_despues = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        // Un movimiento con fecha_liquidacion_estimada = null cuenta como ya disponible: contable
        // y disponible suben exactamente igual, y a_liquidar no se mueve.
        $this->assertEqualsWithDelta($saldos_antes['saldo_contable'] + 1000, $saldos_despues['saldo_contable'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_disponible'] + 1000, $saldos_despues['saldo_disponible'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_a_liquidar'], $saldos_despues['saldo_a_liquidar'], self::DELTA);

        // "Los tres saldos son iguales entre sí": se compara la DIFERENCIA entre contable y
        // disponible antes y después (no un valor absoluto), para no asumir una base limpia.
        $diferencia_antes = $saldos_antes['saldo_contable'] - $saldos_antes['saldo_disponible'];
        $diferencia_despues = $saldos_despues['saldo_contable'] - $saldos_despues['saldo_disponible'];
        $this->assertEqualsWithDelta($diferencia_antes, $diferencia_despues, self::DELTA);
    }

    /**
     * Test 2 (compatibilidad) — un movimiento histórico simulado (las tres columnas nuevas en
     * null) sigue contando como disponible y no rompe el cálculo: sube contable y disponible por
     * igual, sin tocar a_liquidar. Ver crear_movimiento_historico() para el detalle del insert directo.
     *
     * @group tesoreria
     * @test
     */
    public function movimiento_historico_con_columnas_de_liquidacion_en_null_cuenta_como_disponible()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        $saldos_antes = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $this->crear_movimiento_historico($caja, 2000);

        $saldos_despues = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $this->assertEqualsWithDelta($saldos_antes['saldo_contable'] + 2000, $saldos_despues['saldo_contable'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_disponible'] + 2000, $saldos_despues['saldo_disponible'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_a_liquidar'], $saldos_despues['saldo_a_liquidar'], self::DELTA);
    }

    /**
     * Test 3 (los tres saldos) — una venta de $100.000 por CAJA_MP (14 días, comisión 6,29%) sube
     * saldo_a_liquidar por el NETO (no el bruto), no mueve saldo_disponible, y sube saldo_contable
     * por el bruto. Valor textual (93710) igual al de `1_Gasto_Comision_Test`, mismo fixture.
     *
     * @group tesoreria
     * @test
     */
    public function venta_de_cien_mil_por_caja_mp_sube_a_liquidar_por_el_neto_y_no_mueve_disponible()
    {
        $saldos_antes = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            100000
        );

        $saldos_despues = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        // Comisión 6,29% sobre 100000: neto = 93710.
        $this->assertEqualsWithDelta($saldos_antes['saldo_a_liquidar'] + 93710, $saldos_despues['saldo_a_liquidar'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_disponible'], $saldos_despues['saldo_disponible'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_contable'] + 100000, $saldos_despues['saldo_contable'], self::DELTA);
    }

    /**
     * Test 4 (los tres saldos) — un override con dias_liquidacion = 0 para PAGO_TARJETA_CREDITO en
     * CAJA_MP hace que una venta por ese método caiga en saldo_disponible el mismo día, aunque la
     * caja tenga 14 días por defecto (cascada por campo: la comisión se sigue heredando de la caja).
     * Cierra el par con el test 3 del prompt 01 (la cascada), probándolo end-to-end sobre los saldos.
     *
     * @group tesoreria
     * @test
     */
    public function override_dias_liquidacion_cero_hace_que_la_venta_caiga_en_disponible_el_mismo_dia()
    {
        $this->configurar_override_caja(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            ['dias_liquidacion' => 0]
        );

        $saldos_antes = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            100000
        );

        $saldos_despues = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        $this->assertEqualsWithDelta($saldos_antes['saldo_disponible'] + 93710, $saldos_despues['saldo_disponible'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_a_liquidar'], $saldos_despues['saldo_a_liquidar'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_contable'] + 100000, $saldos_despues['saldo_contable'], self::DELTA);
    }

    /**
     * Test 5 (los tres saldos) — un egreso desde CAJA_MP (gasto pagado con esa caja) baja
     * saldo_disponible y saldo_contable por igual, y no toca saldo_a_liquidar: un egreso siempre
     * impacta liquidez ya, sin importar si la caja tiene días de liquidación configurados.
     *
     * @group tesoreria
     * @test
     */
    public function egreso_desde_caja_mp_baja_disponible_y_contable_sin_tocar_a_liquidar()
    {
        $caja_mp = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP);
        $metodo_pago = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO);

        // crear_gasto() no abre la caja por sí sola (a diferencia de crear_venta_cobrada()).
        $this->asegurar_caja_abierta($caja_mp);

        $saldos_antes = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        $this->crear_gasto(
            TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO,
            3000,
            [
                'payment_methods' => [
                    [
                        'current_acount_payment_method_id' => $metodo_pago->id,
                        'amount'                             => 3000,
                        'caja_id'                             => $caja_mp->id,
                    ],
                ],
            ]
        );

        $saldos_despues = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        $this->assertEqualsWithDelta($saldos_antes['saldo_disponible'] - 3000, $saldos_despues['saldo_disponible'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_contable'] - 3000, $saldos_despues['saldo_contable'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_a_liquidar'], $saldos_despues['saldo_a_liquidar'], self::DELTA);
    }

    /**
     * 🔴 Test 6 (reversión — el test más valioso de los tres prompts de este grupo) — eliminar una
     * venta comisionada (compensar_caja = 1) tiene que dejar los tres saldos exactamente donde
     * estaban antes de crearla. Esto es lo que falló en el intento anterior del grupo 223: el
     * movimiento compensatorio restaba el BRUTO mientras el ingreso original había sumado el NETO,
     * y cada venta comisionada eliminada dejaba el saldo corrido por el valor de la comisión, para
     * siempre. El fix (`DeleteCajaCompensacionHelper::revertir_comision_del_original()`) copia el
     * `monto_neto_estimado` original al movimiento compensatorio para que el COALESCE de la
     * fórmula cierre en cero. Se espera rojo declarado: el fix nunca se verificó a mano.
     *
     * @group tesoreria
     * @test
     */
    public function eliminar_venta_comisionada_deja_los_tres_saldos_donde_estaban_antes()
    {
        $saldos_antes = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            100000
        );

        // Marca de agua: el movimiento compensatorio lo crea el endpoint de destroy, no
        // crear_venta_cobrada(), así que hay que registrarlo a mano para el cleanup.
        $antes_movimiento = $this->max_id_movimiento_caja();

        $response = $this->deleteJson('api/sale/'.$venta->id, ['compensar_caja' => 1]);
        $this->assertTrue($response->getStatusCode() < 300, 'DELETE api/sale devolvió '.$response->getStatusCode().'. Cuerpo: '.$response->getContent());

        $this->registrar_movimientos_caja_nuevos($antes_movimiento);

        $saldos_despues = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        $this->assertEqualsWithDelta($saldos_antes['saldo_contable'], $saldos_despues['saldo_contable'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_disponible'], $saldos_despues['saldo_disponible'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_a_liquidar'], $saldos_despues['saldo_a_liquidar'], self::DELTA);
    }

    /**
     * Test 7 (reversión) — eliminar una venta comisionada borra el Expense automático de comisión:
     * no puede quedar colgado en Gastos un gasto de una venta que ya no existe.
     *
     * @group tesoreria
     * @test
     */
    public function eliminar_venta_comisionada_borra_el_expense_de_comision()
    {
        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            100000
        );

        $movimiento = MovimientoCaja::where('sale_id', $venta->id)->first();
        $this->assertNotNull($movimiento, 'No se encontró el MovimientoCaja de la venta.');

        $expense_id = $movimiento->comision_expense_id;
        $this->assertNotNull($expense_id, 'La venta comisionada debería haber generado un Expense de comisión.');

        $antes_movimiento = $this->max_id_movimiento_caja();

        $response = $this->deleteJson('api/sale/'.$venta->id, ['compensar_caja' => 1]);
        $this->assertTrue($response->getStatusCode() < 300, 'DELETE api/sale devolvió '.$response->getStatusCode().'. Cuerpo: '.$response->getContent());

        $this->registrar_movimientos_caja_nuevos($antes_movimiento);

        $this->assertNull(Expense::find($expense_id), 'El Expense de comisión de la venta eliminada no debería seguir existiendo.');
    }

    /**
     * Test 8 (reversión, camino sin comisión) — eliminar una venta de CAJA_EFECTIVO (sin config de
     * liquidación/comisión) deja los tres saldos exactamente donde estaban, sin ningún tratamiento
     * especial: confirma que el fix de la reversión comisionada no rompió el camino sin comisión.
     *
     * @group tesoreria
     * @test
     */
    public function eliminar_venta_de_caja_efectivo_deja_los_tres_saldos_donde_estaban()
    {
        $saldos_antes = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            1000
        );

        $antes_movimiento = $this->max_id_movimiento_caja();

        $response = $this->deleteJson('api/sale/'.$venta->id, ['compensar_caja' => 1]);
        $this->assertTrue($response->getStatusCode() < 300, 'DELETE api/sale devolvió '.$response->getStatusCode().'. Cuerpo: '.$response->getContent());

        $this->registrar_movimientos_caja_nuevos($antes_movimiento);

        $saldos_despues = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $this->assertEqualsWithDelta($saldos_antes['saldo_contable'], $saldos_despues['saldo_contable'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_disponible'], $saldos_despues['saldo_disponible'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_a_liquidar'], $saldos_despues['saldo_a_liquidar'], self::DELTA);
    }

    /**
     * 🔴 Test 9 (reversión) — mismo comportamiento que el test 6, pero para el segundo camino que
     * manda `aplica_liquidacion => true`: un cobro de cuenta corriente comisionado
     * (`CurrentAcountCajaHelper::guardar_pago()`). Se espera rojo declarado por el mismo motivo.
     *
     * @group tesoreria
     * @test
     */
    public function reversion_de_cobro_de_cuenta_corriente_comisionado_deja_los_tres_saldos_donde_estaban()
    {
        // cobrar_cuenta_corriente() no abre la caja por sí sola.
        $this->asegurar_caja_abierta($this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP));

        $saldos_antes = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        $pago = $this->cobrar_cuenta_corriente(
            TestingFerreteriaSeeder::CLIENTE_CC,
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            50000
        );

        $antes_movimiento = $this->max_id_movimiento_caja();

        $response = $this->deleteJson('api/current-acount/client/'.$pago->id, ['compensar_caja' => 1]);
        $this->assertTrue($response->getStatusCode() < 300, 'DELETE api/current-acount/client devolvió '.$response->getStatusCode().'. Cuerpo: '.$response->getContent());

        $this->registrar_movimientos_caja_nuevos($antes_movimiento);

        $saldos_despues = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_MP);

        $this->assertEqualsWithDelta($saldos_antes['saldo_contable'], $saldos_despues['saldo_contable'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_disponible'], $saldos_despues['saldo_disponible'], self::DELTA);
        $this->assertEqualsWithDelta($saldos_antes['saldo_a_liquidar'], $saldos_despues['saldo_a_liquidar'], self::DELTA);
    }

    /**
     * Test 10 (línea de tiempo) — GET api/caja/{id}/liquidaciones-pendientes sobre CAJA_MP con tres
     * ventas en fechas de liquidación distintas: devuelve 200, los ingresos agrupados por fecha con
     * el neto de cada día, y excluye una venta ya liquidada (override dias_liquidacion = 0).
     *
     * Para lograr tres fechas de liquidación distintas se mueve la base del reloj determinista del
     * trait (`$reloj_base_escenarios`, no solo los segundos de `avanzar_reloj_de_ventas()`) entre
     * cada venta: cada una queda con dias_liquidacion=14 aplicado sobre un "hoy" distinto.
     *
     * @group tesoreria
     * @test
     */
    public function linea_de_tiempo_de_liquidaciones_pendientes_agrupa_por_fecha_y_excluye_lo_ya_liquidado()
    {
        $caja_mp = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP);

        // Venta 1: "hoy" (base del reloj de escenarios).
        $this->reloj_base_escenarios = Carbon::now();
        $this->segundos_desplazados_escenarios = 0;
        $venta_1 = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_MP, TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO, 10000);

        // Venta 2: "hoy" + 1 día.
        $this->reloj_base_escenarios = Carbon::now()->addDay();
        $this->segundos_desplazados_escenarios = 0;
        $venta_2 = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_MP, TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO, 20000);

        // Venta 3: "hoy" + 2 días.
        $this->reloj_base_escenarios = Carbon::now()->addDays(2);
        $this->segundos_desplazados_escenarios = 0;
        $venta_3 = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_MP, TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO, 30000);

        // Venta 4: ya liquidada (override dias_liquidacion = 0 para este método de pago). No tiene
        // que aparecer en ninguna fecha de la línea de tiempo.
        $this->configurar_override_caja(TestingFerreteriaSeeder::CAJA_MP, TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO, ['dias_liquidacion' => 0]);
        $venta_liquidada = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_MP, TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO, 5000);

        $response = $this->getJson('api/caja/'.$caja_mp->id.'/liquidaciones-pendientes');
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $items = $body['models'];

        $this->assertCount(3, $items, 'Se esperaban 3 fechas pendientes (una por venta, sin la ya liquidada). Cuerpo: '.$response->getContent());

        $movimiento_1 = MovimientoCaja::where('sale_id', $venta_1->id)->first();
        $movimiento_2 = MovimientoCaja::where('sale_id', $venta_2->id)->first();
        $movimiento_3 = MovimientoCaja::where('sale_id', $venta_3->id)->first();

        // Mapa fecha => neto esperado, para no depender del orden en que vuelve el listado.
        $fechas_esperadas = [
            $movimiento_1->fecha_liquidacion_estimada => (float) $movimiento_1->monto_neto_estimado,
            $movimiento_2->fecha_liquidacion_estimada => (float) $movimiento_2->monto_neto_estimado,
            $movimiento_3->fecha_liquidacion_estimada => (float) $movimiento_3->monto_neto_estimado,
        ];

        foreach ($items as $item) {
            $this->assertArrayHasKey($item['fecha'], $fechas_esperadas, 'Fecha inesperada en la línea de tiempo: '.$item['fecha']);
            $this->assertEqualsWithDelta($fechas_esperadas[$item['fecha']], (float) $item['neto'], self::DELTA);
        }

        // La venta ya liquidada no debe aparecer en ninguna de las fechas devueltas.
        $movimiento_liquidado = MovimientoCaja::where('sale_id', $venta_liquidada->id)->first();
        foreach ($items as $item) {
            $this->assertNotEquals($movimiento_liquidado->fecha_liquidacion_estimada, $item['fecha']);
        }
    }

    /**
     * Test 11 (línea de tiempo) — el mismo endpoint sobre CAJA_EFECTIVO (sin ningún ingreso con
     * fecha_liquidacion_estimada futura) devuelve 200 con lista vacía, no un error.
     *
     * @group tesoreria
     * @test
     */
    public function linea_de_tiempo_de_caja_efectivo_devuelve_lista_vacia_sin_error()
    {
        $caja_efectivo = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $response = $this->getJson('api/caja/'.$caja_efectivo->id.'/liquidaciones-pendientes');

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body['models']);
        $this->assertCount(0, $body['models']);
    }
}
