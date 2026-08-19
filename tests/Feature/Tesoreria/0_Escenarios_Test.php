<?php

namespace Tests\Feature\Tesoreria;

use App\Models\Expense;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use Carbon\Carbon;
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

    /**
     * Grupo 260 · Prompt 01 — regresión del bug de reloj congelado: fijar_reloj_en() tiene que
     * "sobrevivir" a la creación de una venta (avanzar_reloj_de_ventas() no puede pisarlo).
     *
     * Fija el escenario en un mes (fecha A), crea una venta y verifica que cae ahí; después fija
     * el escenario en OTRO mes (fecha B) y crea una segunda venta idéntica en monto/cliente —
     * antes de este prompt, avanzar_reloj_de_ventas() pisaba el setTestNow() de fecha B con el
     * reloj_base_escenarios congelado en la venta 1, y la segunda venta terminaba cayendo en el
     * mismo mes que la primera, a segundos de distancia.
     *
     * @group tesoreria
     * @test
     */
    public function fijar_reloj_en_sobrevive_a_la_creacion_de_una_venta()
    {
        // Fechas relativas al presente (no hardcodeadas) para que el test no envejezca: dos meses
        // distintos hacia el pasado, bien separados entre sí.
        $fecha_a = Carbon::now()->subMonths(3)->startOfMonth()->addDays(2)->setTime(10, 0, 0);
        $fecha_b = Carbon::now()->subMonths(1)->startOfMonth()->addDays(5)->setTime(11, 0, 0);

        $this->fijar_reloj_en($fecha_a);

        $venta_1 = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            750
        );

        // La venta 1 tiene que haber quedado con created_at en el mismo día que fecha_a (el guard
        // anti-duplicado corre segundos después, pero el día/mes no puede moverse).
        $this->assertEquals($fecha_a->toDateString(), Carbon::parse($venta_1->created_at)->toDateString());

        $this->fijar_reloj_en($fecha_b);

        $venta_2 = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            750
        );

        // Bug 260: sin el fix, avanzar_reloj_de_ventas() pisaba fecha_b y la venta 2 caía en el
        // mismo mes que la venta 1.
        $this->assertEquals($fecha_b->toDateString(), Carbon::parse($venta_2->created_at)->toDateString());

        // La segunda venta existe de verdad (no fue descartada por el guard de los 5 segundos) y
        // es un registro distinto de la primera.
        $this->assertNotEquals($venta_1->id, $venta_2->id);
    }
}
