<?php

namespace Tests\Feature\Tesoreria;

use App\Models\Expense;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Grupo 242 · Prompt 03 — humo del trait EscenariosDePlata.
 *
 * Es el andamio del que dependen los grupos 243, 244, 245 y 246: si estos tres tests no pasan,
 * ningún test de esos grupos va a significar nada (ver "LEER PRIMERO" del prompt de este archivo).
 *
 * @group tesoreria
 */
class Escenarios_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /**
     * Libera todo lo que el trait haya creado en este test (incluso si una aserción cortó a
     * mitad de camino), antes de que corra el rollback de la transacción de DatabaseTransactions.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * crear_venta_cobrada() contra la caja de efectivo con el método de pago efectivo crea una
     * Sale real (vía POST api/sale), un MovimientoCaja de ingreso por el monto cobrado, y ningún
     * Expense automático — la caja de efectivo no tiene comisión configurada en el fixture.
     *
     * @group tesoreria
     * @test
     */
    public function crear_venta_cobrada_genera_venta_y_movimiento_sin_gasto()
    {
        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            1000
        );

        $this->assertInstanceOf(Sale::class, $venta);

        $movimiento = MovimientoCaja::where('sale_id', $venta->id)->first();

        $this->assertNotNull($movimiento, 'No se encontró ningún MovimientoCaja para la venta recién creada.');
        $this->assertEquals(1000, (float) $movimiento->ingreso);

        // Ningún Expense automático: sin comisión configurada, comision_expense_id queda null.
        $this->assertNull($movimiento->comision_expense_id);
    }

    /**
     * saldos_de_caja() después de esa venta devuelve saldo_contable == saldo_disponible == 1000,
     * y saldo_a_liquidar == 0. Los tres valores se assertan por separado, con el número exacto —
     * NO se comparan entre sí: 0 es el valor correcto para una caja de efectivo sin configurar
     * (no hay nada en tránsito esperando liquidarse), no un descuadre. Ver Bug 4 del prompt.
     *
     * @group tesoreria
     * @test
     */
    public function saldos_de_caja_efectivo_despues_de_una_venta()
    {
        $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            1000
        );

        $saldos = $this->saldos_de_caja(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $this->assertEquals(1000, $saldos['saldo_contable']);
        $this->assertEquals(1000, $saldos['saldo_disponible']);
        $this->assertEquals(0, $saldos['saldo_a_liquidar']);
    }

    /**
     * Dos crear_venta_cobrada() seguidas, con los mismos parámetros (misma caja, mismo método,
     * mismo monto, mismo cliente -null-), crean DOS Sale distintas, con id distinto.
     *
     * Test de regresión del Bug 3: sin el avance de reloj de avanzar_reloj_de_ventas(), la segunda
     * venta caía en el guard anti-duplicado de SaleController::venta_ya_cread() y desaparecía.
     *
     * @group tesoreria
     * @test
     */
    public function dos_ventas_identicas_seguidas_crean_dos_ventas_distintas()
    {
        $venta_1 = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            500
        );

        $venta_2 = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            500
        );

        $this->assertNotEquals($venta_1->id, $venta_2->id);
    }
}
