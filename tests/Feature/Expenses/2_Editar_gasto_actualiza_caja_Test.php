<?php

namespace Tests\Feature\Expenses;

use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Tanda correctivos 24/8 — ítem 5: editar un gasto no tocaba ni el desglose de métodos
 * de pago (pivot) ni el movimiento de caja. El estado de resultados mostraba el monto
 * nuevo; el flujo de caja y el saldo de la caja, el viejo. El bloque que debía hacerlo
 * en ExpenseController::update() estaba comentado y
 * ExpenseCajaHelper::editar_movimiento_caja() no tenía llamadores (y estaba rota por
 * dentro: fallback a guardar_movimiento_caja() sin el segundo argumento).
 *
 * Escenario por los endpoints reales, con el trait EscenariosDePlata (mismo molde que
 * tests/Feature/Tesoreria).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Editar_gasto_actualiza_caja_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * Editar el monto de un gasto pagado por caja actualiza el desglose, el egreso del
     * movimiento de caja y el saldo de la caja.
     *
     * @group expenses
     * @test
     */
    public function editar_el_monto_del_gasto_actualiza_desglose_y_saldo_de_caja()
    {
        /** Caja sin config de comisión (comportamiento puro de caja). */
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        /** Método de pago del desglose. */
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $this->asegurar_caja_abierta($caja);

        /** Saldo de la caja antes del gasto, punto de referencia de todo el test. */
        $saldo_inicial = (float) $caja->fresh()->saldo;

        $gasto = $this->crear_gasto(
            TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO,
            3000,
            [
                'payment_methods' => [
                    [
                        'current_acount_payment_method_id' => $metodo->id,
                        'amount'                           => 3000,
                        'caja_id'                          => $caja->id,
                    ],
                ],
            ]
        );

        // El alta dejó el egreso en la caja (comportamiento preexistente, ancla del escenario).
        $this->assertEquals($saldo_inicial - 3000, (float) $caja->fresh()->saldo);

        /*
         * La edición, con el payload que manda la SPA hoy: los campos del gasto SIN
         * payment_methods (la tabla de métodos de pago del form es de solo lectura).
         */
        $response = $this->putJson('api/expense/'.$gasto->id, [
            'expense_concept_id' => $gasto->expense_concept_id,
            'amount'             => 5000,
            'importe_iva'        => 0,
            'observations'       => $gasto->observations,
            'created_at'         => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(200);

        $gasto->refresh();
        $gasto->load('current_acount_payment_methods');

        // El desglose refleja el monto nuevo.
        $this->assertCount(1, $gasto->current_acount_payment_methods);
        $this->assertEquals(
            5000,
            (float) $gasto->current_acount_payment_methods[0]->pivot->amount,
            'El pivot del método de pago tiene que reflejar el monto editado.'
        );

        // El movimiento de caja refleja el egreso nuevo (y sigue siendo UNO solo).
        $movimientos = MovimientoCaja::where('expense_id', $gasto->id)->get();
        $this->assertCount(1, $movimientos, 'La edición no puede duplicar el movimiento de caja del gasto.');
        $this->assertEquals(
            5000,
            (float) $movimientos[0]->egreso,
            'El egreso del movimiento de caja tiene que reflejar el monto editado.'
        );

        // Y el saldo de la caja quedó recalculado con el monto nuevo.
        $this->assertEquals(
            $saldo_inicial - 5000,
            (float) $caja->fresh()->saldo,
            'El saldo de la caja tiene que reflejar el monto editado del gasto.'
        );
    }

    /**
     * Achicar el monto también funciona (el saldo sube), y editar un gasto SIN métodos de
     * pago ni caja sigue sin tocar nada de caja.
     *
     * @group expenses
     * @test
     */
    public function achicar_el_monto_sube_el_saldo_y_un_gasto_sin_caja_no_se_ve_afectado()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $this->asegurar_caja_abierta($caja);

        $saldo_inicial = (float) $caja->fresh()->saldo;

        $gasto_con_caja = $this->crear_gasto(
            TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO,
            4000,
            [
                'payment_methods' => [
                    [
                        'current_acount_payment_method_id' => $metodo->id,
                        'amount'                           => 4000,
                        'caja_id'                          => $caja->id,
                    ],
                ],
            ]
        );

        /** Gasto sin desglose ni caja: la edición no tiene nada que sincronizar. */
        $gasto_sin_caja = $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 1000);

        $this->assertEquals($saldo_inicial - 4000, (float) $caja->fresh()->saldo);

        // Achicar el gasto con caja: 4000 -> 1500.
        $this->putJson('api/expense/'.$gasto_con_caja->id, [
            'expense_concept_id' => $gasto_con_caja->expense_concept_id,
            'amount'             => 1500,
            'importe_iva'        => 0,
            'observations'       => $gasto_con_caja->observations,
            'created_at'         => Carbon::now()->format('Y-m-d H:i:s'),
        ])->assertStatus(200);

        $this->assertEquals(
            $saldo_inicial - 1500,
            (float) $caja->fresh()->saldo,
            'Achicar el monto del gasto tiene que devolver la diferencia al saldo de la caja.'
        );

        // Editar el gasto sin caja no crea movimientos ni toca el saldo.
        $this->putJson('api/expense/'.$gasto_sin_caja->id, [
            'expense_concept_id' => $gasto_sin_caja->expense_concept_id,
            'amount'             => 2500,
            'importe_iva'        => 0,
            'observations'       => $gasto_sin_caja->observations,
            'created_at'         => Carbon::now()->format('Y-m-d H:i:s'),
        ])->assertStatus(200);

        $this->assertEquals(0, MovimientoCaja::where('expense_id', $gasto_sin_caja->id)->count());
        $this->assertEquals($saldo_inicial - 1500, (float) $caja->fresh()->saldo);
        $this->assertEquals(2500, (float) $gasto_sin_caja->fresh()->amount);
    }
}
