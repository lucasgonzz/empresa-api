<?php

namespace Tests\Feature\Tesoreria;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\CreditAccount;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use App\Models\Provider;
use App\Models\Sale;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Grupo 243 · Prompt 02 — tests del gasto automático de comisión (Expense generado a partir de un
 * MovimientoCaja liquidable) y, sobre todo, de los caminos que NO tienen que generarlo ("comisiones
 * fantasma"). Reemplaza las pruebas manuales pendientes 5, 6, 7 y 10 del grupo tesoreria-liquidaciones-comisiones.
 *
 * El código de producción (`MovimientoCajaHelper::crear_movimiento()`) solo calcula comisión cuando
 * el llamador manda el flag explícito `aplica_liquidacion => true`. Ese flag es responsabilidad de
 * cada uno de los 6 flujos que comparten `crear_movimiento()`: `SaleCajaHelper` (venta cobrada) y
 * `CurrentAcountCajaHelper::guardar_pago()` con `model_name == 'client'` (cobro a cliente) lo mandan;
 * `MovimientoEntreCajaHelper` (transferencia entre cajas), `DeleteCajaCompensacionHelper` (reversión
 * de venta/gasto/pago), `ExpenseCajaHelper` (pago de un gasto) y `MovimientoCajaController` (carga
 * manual) NO lo mandan nunca. Estos tests son la red que impide que alguien "simplifique" esa regla
 * a una inferencia sobre `ingreso > 0` (el bug real del grupo 223).
 *
 * Para los casos negativos, la aserción correcta es contar los `Expense` con
 * `expense_concept_id = CONCEPTO_GASTO_COMISION` antes y después de la operación (no el total de
 * `Expense` de la base, que es frágil frente a gastos legítimos generados por otro motivo).
 *
 * @group tesoreria
 */
class Gasto_Comision_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /**
     * Delta de tolerancia para comparar floats (mismo criterio que `CajaLiquidacionHelperTest`).
     */
    const DELTA = 0.01;

    /**
     * Libera todo lo que el trait EscenariosDePlata haya creado en este test (ventas, gastos,
     * cobros, transferencias, overrides, aperturas) antes de que corra el rollback de la
     * transacción de DatabaseTransactions. Es la misma red de seguridad explícita que usa
     * `0_Escenarios_Test.php`: corre siempre, incluso si una aserción cortó el test a mitad de
     * camino, porque PHPUnit ejecuta tearDown() de todas formas.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * Cuenta los Expense automáticos de comisión (concepto CONCEPTO_GASTO_COMISION del fixture).
     * Contar por concepto y no el total de Expense de la base es la regla dura de este prompt: la
     * operación bajo prueba puede generar gastos legítimos por otro motivo (ej. el gasto operativo
     * que arma el propio test 8) y no queremos que esos disparen un falso positivo.
     *
     * @return int
     */
    protected function contar_gastos_comision()
    {
        $concepto_comision = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_COMISION);

        return Expense::where('expense_concept_id', $concepto_comision->id)->count();
    }

    /**
     * Test positivo 1 — Venta de $100.000 por CAJA_MP (comisión 6,29% + IVA incluido, tal cual el
     * fixture) cubre en un único test todo el escenario canónico del grupo 223 (valores textuales de
     * la prueba manual 6, no se recalculan acá).
     *
     * @group tesoreria
     * @test
     */
    public function venta_por_caja_mp_genera_movimiento_liquidable_y_gasto_de_comision()
    {
        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            100000
        );

        $this->assertInstanceOf(Sale::class, $venta);

        $movimiento = MovimientoCaja::where('sale_id', $venta->id)->first();
        $this->assertNotNull($movimiento, 'No se encontró el MovimientoCaja de la venta.');

        // Comisión y neto: 100000 * 6.29% = 6290; neto = 100000 - 6290 = 93710.
        $this->assertEqualsWithDelta(6290, (float) $movimiento->comision_calculada, self::DELTA);
        $this->assertEqualsWithDelta(93710, (float) $movimiento->monto_neto_estimado, self::DELTA);

        // dias_liquidacion = 14 en el fixture: la fecha estimada es "ahora" (reloj de la venta) + 14 días.
        $this->assertEquals(
            Carbon::now()->addDays(14)->format('Y-m-d'),
            $movimiento->fecha_liquidacion_estimada
        );

        $this->assertNotNull($movimiento->comision_expense_id, 'El movimiento no quedó vinculado a ningún Expense de comisión.');

        $gasto = Expense::find($movimiento->comision_expense_id);
        $this->assertNotNull($gasto);

        // IVA incluido: 6290 * 21 / 121 = 1091.65 (valor textual de la prueba manual 6).
        $this->assertEqualsWithDelta(6290, (float) $gasto->amount, self::DELTA);
        $this->assertEqualsWithDelta(1091.65, (float) $gasto->importe_iva, self::DELTA);

        $concepto_comision = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_COMISION);
        $this->assertEquals($concepto_comision->id, $gasto->expense_concept_id);

        // Regla dura: el Expense de comisión NUNCA lleva caja_id, o ExpenseCajaHelper generaría un
        // egreso propio y la comisión se contaría dos veces (neto + saldo contable).
        $this->assertNull($gasto->caja_id, 'El Expense automático de comisión no debe tener caja_id.');
    }

    /**
     * Test positivo 2 — mismo escenario que el anterior pero con `comision_iva_incluido = 0` seteado
     * vía override de la caja para ese método de pago (cascada por campo: el resto de los valores
     * -porcentaje, alícuota- siguen viniendo de la caja). El override queda registrado por
     * `configurar_override_caja()` y se limpia solo en `limpiar_escenarios()` (tearDown).
     *
     * @group tesoreria
     * @test
     */
    public function venta_por_caja_mp_con_comision_neta_de_iva_calcula_amount_con_iva_sumado()
    {
        $this->configurar_override_caja(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            ['comision_iva_incluido' => 0]
        );

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            100000
        );

        $movimiento = MovimientoCaja::where('sale_id', $venta->id)->first();
        $this->assertNotNull($movimiento->comision_expense_id);

        $gasto = Expense::find($movimiento->comision_expense_id);

        // Neta de IVA: comisión (6290) + IVA (6290 * 21 / 100 = 1320.90) = 7610.90 (valor textual del prompt).
        $this->assertEqualsWithDelta(7610.90, (float) $gasto->amount, self::DELTA);
        $this->assertEqualsWithDelta(1320.90, (float) $gasto->importe_iva, self::DELTA);
    }

    /**
     * Test positivo 3 — un cobro de cuenta corriente (no una venta) por CAJA_MP también dispara
     * comisión: `CurrentAcountCajaHelper::guardar_pago()` es el segundo camino que manda
     * `aplica_liquidacion => true` cuando `$model_name == 'client'`.
     *
     * @group tesoreria
     * @test
     */
    public function cobro_de_cuenta_corriente_por_caja_mp_tambien_genera_gasto_de_comision()
    {
        // A diferencia de crear_venta_cobrada()/transferir_entre_cajas(), cobrar_cuenta_corriente()
        // no abre la caja por sí sola: sin apertura, MovimientoCajaHelper::get_current_aperutra_caja()
        // rompe con "Trying to get property 'id' of non-object" al no encontrar ninguna AperturaCaja.
        $this->asegurar_caja_abierta($this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP));

        $gastos_comision_antes = $this->contar_gastos_comision();

        $this->cobrar_cuenta_corriente(
            TestingFerreteriaSeeder::CLIENTE_CC,
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            50000
        );

        // Segundo camino que manda aplica_liquidacion => true: tiene que sumar exactamente un
        // Expense de comisión más, ni cero (comisión fantasma inversa) ni más de uno (duplicado).
        $this->assertEquals($gastos_comision_antes + 1, $this->contar_gastos_comision());
    }

    /**
     * Test positivo 4 — CAJA_SIN_CONCEPTO tiene la misma config de comisión que CAJA_MP pero sin
     * `expense_concept_id`: el movimiento calcula comisión igual (es información válida), pero
     * `crear_gasto_comision()` no puede crear el Expense y NO tiene que reventar la venta.
     *
     * @group tesoreria
     * @test
     */
    public function venta_por_caja_sin_concepto_calcula_comision_pero_no_crea_gasto()
    {
        $gastos_comision_antes = $this->contar_gastos_comision();

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_SIN_CONCEPTO,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            100000
        );

        // La venta se guardó (crear_venta_cobrada ya hizo fail() si el endpoint no hubiera devuelto 2xx).
        $this->assertInstanceOf(Sale::class, $venta);

        $movimiento = MovimientoCaja::where('sale_id', $venta->id)->first();

        $this->assertNotNull($movimiento->comision_calculada);
        $this->assertNotNull($movimiento->monto_neto_estimado);
        $this->assertNull($movimiento->comision_expense_id, 'Sin expense_concept_id configurado no puede haber Expense de comisión.');

        $this->assertEquals($gastos_comision_antes, $this->contar_gastos_comision());
    }

    /**
     * Test positivo 5 — idempotencia: `MovimientoCajaHelper::crear_gasto_comision()` chequea
     * `comision_expense_id` antes de crear, así que correrlo dos veces sobre el mismo movimiento no
     * duplica el Expense. Se llama al método directo (no hay forma de re-disparar el cálculo por
     * HTTP) para simular un reintento.
     *
     * @group tesoreria
     * @test
     */
    public function recalcular_comision_sobre_el_mismo_movimiento_no_duplica_el_gasto()
    {
        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            100000
        );

        $movimiento = MovimientoCaja::where('sale_id', $venta->id)->first();
        $expense_id_original = $movimiento->comision_expense_id;
        $this->assertNotNull($expense_id_original);

        $gastos_comision_antes = $this->contar_gastos_comision();

        // Reintento manual del mismo cálculo sobre el mismo movimiento ya persistido.
        MovimientoCajaHelper::crear_gasto_comision(
            $movimiento,
            $movimiento->caja_id,
            $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO)->id,
            (float) $movimiento->comision_calculada
        );

        $movimiento->refresh();

        $this->assertEquals($expense_id_original, $movimiento->comision_expense_id, 'El reintento no debería cambiar el Expense vinculado.');
        $this->assertEquals($gastos_comision_antes, $this->contar_gastos_comision(), 'El reintento no debería crear un segundo Expense.');
    }

    /**
     * Test negativo 6 — CAJA_EFECTIVO no tiene ninguna config de liquidación/comisión (todo null en
     * el fixture): ningún Expense de comisión, y los dos campos calculados quedan en null. Es el
     * caso que prueba que los clientes en producción (que nunca configuraron tesorería) no notan
     * ningún cambio de comportamiento.
     *
     * @group tesoreria
     * @test
     */
    public function venta_por_caja_efectivo_no_calcula_comision_ni_crea_gasto()
    {
        $gastos_comision_antes = $this->contar_gastos_comision();

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            1000
        );

        $movimiento = MovimientoCaja::where('sale_id', $venta->id)->first();

        $this->assertNull($movimiento->comision_calculada);
        $this->assertNull($movimiento->monto_neto_estimado);
        $this->assertNull($movimiento->comision_expense_id);

        $this->assertEquals($gastos_comision_antes, $this->contar_gastos_comision());
    }

    /**
     * Test positivo 4 (prompt 380/01, prueba manual 9 del grupo 223) — un override con
     * `dias_liquidacion = 0` explícito sobre una caja que en su config general tiene 14 días SIGUE
     * contando como "tiene configuración" (`CajaLiquidacionHelper::tiene_configuracion()`): el
     * movimiento no queda en null, liquida el mismo día. Caso borde real: `resolve_config()` cae al
     * valor de la caja solo cuando el override es `null`, así que un `0` explícito tiene que
     * distinguirse de "sin override" — un `empty()` o un chequeo de truthy en vez de `is_null()`
     * confundiría los dos casos en silencio.
     *
     * @group tesoreria
     * @test
     */
    public function override_con_dias_liquidacion_cero_sigue_contando_como_configuracion()
    {
        $this->configurar_override_caja(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            ['dias_liquidacion' => 0]
        );

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_MP,
            TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO,
            100000
        );

        $movimiento = MovimientoCaja::where('sale_id', $venta->id)->first();
        $this->assertNotNull($movimiento);

        // El override solo pisa dias_liquidacion; comision_porcentaje sigue viniendo de la caja
        // (6.29%, el default del fixture para CAJA_MP), así que la comisión no tiene que quedar null.
        $this->assertNotNull($movimiento->comision_calculada, 'BUG: el override en 0 explícito no tiene que confundirse con "sin configurar".');
        $this->assertNotNull($movimiento->monto_neto_estimado);
        $this->assertNotNull($movimiento->fecha_liquidacion_estimada);

        // dias_liquidacion=0 -> liquida el mismo dia del movimiento (reloj de la venta), no +14.
        $this->assertEquals(
            Carbon::now()->format('Y-m-d'),
            $movimiento->fecha_liquidacion_estimada
        );
    }

    /**
     * Test negativo 7 — una transferencia entre cajas hacia CAJA_MP genera un ingreso en la caja
     * destino (`MovimientoEntreCajaHelper::ingreso_caja_destino()`), pero NO es un cobro: ese helper
     * nunca manda `aplica_liquidacion`. Cero Expense creados, `monto_neto_estimado` en null en el
     * movimiento de ingreso resultante.
     *
     * @group tesoreria
     * @test
     */
    public function transferencia_entre_cajas_hacia_caja_mp_no_genera_comision()
    {
        $gastos_comision_antes = $this->contar_gastos_comision();

        $this->transferir_entre_cajas(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::CAJA_MP,
            5000
        );

        $caja_mp = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP);

        $movimiento_ingreso = MovimientoCaja::where('caja_id', $caja_mp->id)
                                                ->where('ingreso', 5000)
                                                ->orderBy('id', 'DESC')
                                                ->first();

        $this->assertNotNull($movimiento_ingreso, 'No se encontró el movimiento de ingreso en la caja destino.');
        $this->assertNull($movimiento_ingreso->monto_neto_estimado);
        $this->assertNull($movimiento_ingreso->comision_calculada);
        $this->assertNull($movimiento_ingreso->comision_expense_id);

        $this->assertEquals($gastos_comision_antes, $this->contar_gastos_comision());
    }

    /**
     * Test negativo 8 — eliminar (con compensación de caja) un gasto que se había pagado desde
     * CAJA_MP genera un ingreso compensatorio (`DeleteCajaCompensacionHelper`), pero un gasto nunca
     * tuvo comisión propia y su reversión tampoco manda `aplica_liquidacion`. Cero Expense de
     * comisión en ningún momento del flujo (alta y baja).
     *
     * @group tesoreria
     * @test
     */
    public function eliminar_gasto_pagado_desde_caja_mp_no_genera_ni_revierte_comision()
    {
        $caja_mp = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP);
        $metodo_pago = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO);

        // crear_gasto() no abre la caja por sí sola (a diferencia de crear_venta_cobrada()): el
        // gasto va a impactarla vía payment_methods, y get_apertura_caja_id() rompe si no hay
        // ninguna apertura abierta para esa caja.
        $this->asegurar_caja_abierta($caja_mp);

        $gastos_comision_antes = $this->contar_gastos_comision();

        $gasto = $this->crear_gasto(
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

        $this->assertEquals($gastos_comision_antes, $this->contar_gastos_comision(), 'Pagar un gasto desde una caja comisionada no debe generar comisión.');

        $response = $this->deleteJson('api/expense/'.$gasto->id, ['compensar_caja' => 1]);

        $this->assertTrue($response->getStatusCode() < 300, 'DELETE api/expense devolvió '.$response->getStatusCode().'. Cuerpo: '.$response->getContent());

        $this->assertEquals($gastos_comision_antes, $this->contar_gastos_comision(), 'Revertir el gasto tampoco debe generar comisión.');
    }

    /**
     * Test negativo 9 — un pago a proveedor desde CAJA_MP (egreso) y su reversión (ingreso
     * compensatorio) tampoco generan comisión en ningún momento. No hay helper en
     * `EscenariosDePlata` para pagos a proveedor (solo cobros a cliente), así que este test arma el
     * escenario a mano contra los endpoints reales, con el mismo criterio de "todo por endpoint" del
     * trait: `POST api/current-acount/pago` con `model_name = provider` y `DELETE
     * api/current-acount/provider/{id}` con `compensar_caja = 1`.
     *
     * @group tesoreria
     * @test
     */
    public function pago_a_proveedor_desde_caja_mp_y_su_reversion_no_generan_comision()
    {
        $caja_mp = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP);
        $metodo_pago = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_TARJETA_CREDITO);
        $proveedor = Provider::where('name', TestingFerreteriaSeeder::PROVIDER_BSAS)->first();

        $this->assertNotNull($proveedor, 'No existe el proveedor "'.TestingFerreteriaSeeder::PROVIDER_BSAS.'" en el fixture.');

        $this->asegurar_caja_abierta($caja_mp);

        // Idempotente por dentro (firstOrCreate): garantiza la credit_account (moneda 1) del proveedor.
        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id);

        $credit_account = CreditAccount::where('model_name', 'provider')
                                        ->where('model_id', $proveedor->id)
                                        ->where('moneda_id', 1)
                                        ->first();

        $this->assertNotNull($credit_account, 'No se pudo resolver la credit_account (moneda 1) del proveedor.');

        $gastos_comision_antes = $this->contar_gastos_comision();

        // Marca de agua de movimiento_cajas, para poder registrar en el trait (y así en
        // limpiar_escenarios()) los movimientos que se generen a mano en este test.
        $antes_movimiento = $this->max_id_movimiento_caja();

        $payload_pago = [
            'description'                      => 'Pago a proveedor — escenario de test',
            'credit_account_id'                => $credit_account->id,
            'is_provisorio'                     => 0,
            'model_name'                         => 'provider',
            'model_id'                           => $proveedor->id,
            'haber'                               => 2000,
            'current_date'                        => 1,
            'current_acount_payment_methods'     => [
                [
                    'current_acount_payment_method_id' => $metodo_pago->id,
                    'amount'                             => 2000,
                    'caja_id'                             => $caja_mp->id,
                ],
            ],
        ];

        $response_pago = $this->postJson('api/current-acount/pago', $payload_pago);

        $this->assertEquals(201, $response_pago->getStatusCode(), 'POST api/current-acount/pago devolvió '.$response_pago->getStatusCode().'. Cuerpo: '.$response_pago->getContent());

        $body_pago = json_decode($response_pago->getContent(), true);
        $pago_id = isset($body_pago['current_acount']['id']) ? $body_pago['current_acount']['id'] : null;

        $this->assertNotNull($pago_id, 'POST api/current-acount/pago no devolvió "current_acount.id". Cuerpo: '.$response_pago->getContent());

        $this->assertEquals($gastos_comision_antes, $this->contar_gastos_comision(), 'Un pago a proveedor (egreso) no debe generar comisión.');

        $response_delete = $this->deleteJson('api/current-acount/provider/'.$pago_id, ['compensar_caja' => 1]);

        $this->assertTrue($response_delete->getStatusCode() < 300, 'DELETE api/current-acount/provider devolvió '.$response_delete->getStatusCode().'. Cuerpo: '.$response_delete->getContent());

        $this->assertEquals($gastos_comision_antes, $this->contar_gastos_comision(), 'Revertir el pago a proveedor tampoco debe generar comisión.');

        // Registra en el trait los movimientos de caja generados a mano (pago + reversión) para que
        // limpiar_escenarios() los borre y restaure el saldo contable de CAJA_MP en el tearDown.
        $this->registrar_movimientos_caja_nuevos($antes_movimiento);
    }

    /**
     * Test negativo 10 — un movimiento manual de caja en CAJA_MP (`POST api/movimiento-caja`, el
     * mismo endpoint que usa la pantalla de "carga manual") no lleva método de pago de cuenta
     * corriente: queda fuera del alcance de la liquidación/comisión aunque la caja destino esté
     * comisionada.
     *
     * @group tesoreria
     * @test
     */
    public function movimiento_manual_en_caja_mp_no_lleva_metodo_de_pago_y_no_genera_comision()
    {
        $caja_mp = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_MP);

        $this->asegurar_caja_abierta($caja_mp);

        $gastos_comision_antes = $this->contar_gastos_comision();

        $antes_movimiento = $this->max_id_movimiento_caja();

        $payload = [
            'concepto_movimiento_caja_id' => 1,
            'ingreso'                       => 1500,
            'egreso'                         => null,
            'notas'                          => 'Carga manual — escenario de test',
            'caja_id'                        => $caja_mp->id,
        ];

        $response = $this->postJson('api/movimiento-caja', $payload);

        $this->assertEquals(201, $response->getStatusCode(), 'POST api/movimiento-caja devolvió '.$response->getStatusCode().'. Cuerpo: '.$response->getContent());

        $body = json_decode($response->getContent(), true);
        $movimiento_id = isset($body['model']['id']) ? $body['model']['id'] : null;

        $this->assertNotNull($movimiento_id, 'POST api/movimiento-caja no devolvió "model.id". Cuerpo: '.$response->getContent());

        $movimiento = MovimientoCaja::find($movimiento_id);

        $this->assertNull($movimiento->comision_calculada);
        $this->assertNull($movimiento->monto_neto_estimado);
        $this->assertNull($movimiento->comision_expense_id);

        $this->assertEquals($gastos_comision_antes, $this->contar_gastos_comision());

        $this->registrar_movimientos_caja_nuevos($antes_movimiento);
    }
}
