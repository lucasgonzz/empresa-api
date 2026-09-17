<?php

namespace Tests\Feature\Agenda;

use App\Models\Caja;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use App\Models\PendingCompleted;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Misión agenda-tareas-calendario (14/9/2026) — marcar una ocurrencia como hecha
 * (`POST pending-completed`, AgendaCompletarHelper) y deshacerla (`DELETE pending-completed/{id}`).
 *
 * Cubre los defectos 1 y 5 del plan: el gasto que se creaba con un Request falso y reventaba
 * después de marcar la tarea (ahora todo va en una transacción: o queda todo o no queda nada), y
 * el doble clic que creaba dos PendingCompleted y dos gastos (ahora 409 por el candado).
 *
 * Todo pasa por los endpoints reales; el gasto se paga por la caja de efectivo del fixture, que
 * gasto_en_efectivo() deja abierta y limpiar_escenarios() devuelve a su estado.
 *
 * @group agenda
 */
class Marcar_Hecha_Test extends AgendaTestCase
{
    /**
     * Delta para comparar montos (mismo criterio que la suite de Tesorería).
     */
    const DELTA = 0.01;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fijar_reloj_en('2026-09-14 10:00:00');
    }

    /**
     * (a) Tarea con gasto + `expense.payment_methods` en efectivo → 201, existe el Expense con
     * observations "Agenda: …", el PendingCompleted apunta a él, y hay UN movimiento de egreso en
     * la caja por el monto.
     *
     * @test
     */
    public function marcar_una_tarea_con_gasto_registra_el_gasto_y_mueve_la_caja()
    {
        $tarea = $this->crear_tarea_con_gasto(5000, ['fecha_realizacion' => '2026-09-14']);

        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $movimientos_antes = MovimientoCaja::where('caja_id', $caja->id)->count();

        $response = $this->marcar_hecha($tarea, '2026-09-14', [
            'expense' => $this->gasto_en_efectivo(5000, ['observations' => 'Septiembre']),
        ]);

        $response->assertStatus(201);

        $expense_id = $response->json('expense.id');
        $this->assertNotNull($expense_id, 'La respuesta tiene que traer el gasto bajo `expense`. Cuerpo: '.$response->getContent());

        $gasto = Expense::find($expense_id);
        $this->assertNotNull($gasto);
        $this->assertEquals('Agenda: Pagar el alquiler — Septiembre', $gasto->observations);
        $this->assertEqualsWithDelta(5000, (float) $gasto->amount, self::DELTA);
        $this->assertEquals($tarea->expense_concept_id, $gasto->expense_concept_id);
        $this->assertEquals(1, $gasto->moneda_id);
        $this->assertNotNull($gasto->num);

        $realizada = PendingCompleted::find($response->json('model.id'));
        $this->assertNotNull($realizada);
        $this->assertEquals($expense_id, $realizada->expense_id);
        $this->assertEqualsWithDelta(5000, (float) $realizada->expense_amount, self::DELTA);
        $this->assertEquals($tarea->id, $realizada->pending_id);
        $this->assertEquals('2026-09-14', substr($realizada->fecha_realizacion, 0, 10));
        $this->assertEquals('Pagar el alquiler', $realizada->detalle);
        $this->assertEquals($tarea->expense_concept_id, $realizada->expense_concept_id);

        // La respuesta trae el modelo con `pending` (con su concepto) y `expense`.
        $this->assertEquals($tarea->id, $response->json('model.pending.id'));
        $this->assertEquals('Alquiler', $response->json('model.pending.expense_concept.name'));
        $this->assertEquals($expense_id, $response->json('model.expense.id'));

        // Exactamente un movimiento de egreso en la caja de efectivo, por el monto, atado al gasto.
        $movimientos = MovimientoCaja::where('caja_id', $caja->id)
                                        ->where('expense_id', $expense_id)
                                        ->get();

        $this->assertCount(1, $movimientos);
        $this->assertEqualsWithDelta(5000, (float) $movimientos[0]->egreso, self::DELTA);
        $this->assertEquals($movimientos_antes + 1, MovimientoCaja::where('caja_id', $caja->id)->count());

        // El desglose del gasto quedó adjunto (es lo que lee el listado de Gastos).
        $this->assertCount(1, $gasto->current_acount_payment_methods);
        $this->assertEquals($caja->id, $gasto->current_acount_payment_methods[0]->pivot->caja_id);
    }

    /**
     * (b) Segundo POST con la misma fecha → 409, y NO hay segundo gasto ni segundo
     * PendingCompleted.
     *
     * @test
     */
    public function marcar_dos_veces_la_misma_ocurrencia_da_409_sin_duplicar_nada()
    {
        $tarea = $this->crear_tarea_con_gasto(5000, [
            'fecha_realizacion'     => '2026-09-01',
            'es_recurrente'         => true,
            'unidad_frecuencia_id'  => $this->unidad('month')->id,
            'cantidad_frecuencia'   => 1,
        ]);

        $this->marcar_hecha($tarea, '2026-09-01', ['expense' => $this->gasto_en_efectivo(5000)])
            ->assertStatus(201);

        $gastos_antes = Expense::where('expense_concept_id', $tarea->expense_concept_id)->count();

        $segundo = $this->marcar_hecha($tarea, '2026-09-01', ['expense' => $this->gasto_en_efectivo(5000)]);

        $segundo->assertStatus(409)->assertJsonStructure(['message']);

        $this->assertEquals(1, PendingCompleted::where('pending_id', $tarea->id)->count());
        $this->assertEquals($gastos_antes, Expense::where('expense_concept_id', $tarea->expense_concept_id)->count());

        // Con hora en la fecha también es la misma ocurrencia.
        $this->marcar_hecha($tarea, '2026-09-01 15:30:00', ['expense' => $this->gasto_en_efectivo(5000)])
            ->assertStatus(409);

        $this->assertEquals(1, PendingCompleted::where('pending_id', $tarea->id)->count());
    }

    /**
     * (c) Tarea con gasto sin `expense` → 422 y NO queda PendingCompleted ni gasto: el 422 se
     * decide adentro de la transacción y la revierte. La tarea sigue pendiente.
     *
     * @test
     */
    public function marcar_una_tarea_con_gasto_sin_decir_como_se_pago_da_422_y_no_escribe_nada()
    {
        $tarea = $this->crear_tarea_con_gasto(5000, ['fecha_realizacion' => '2026-09-14']);

        $gastos_antes = Expense::where('expense_concept_id', $tarea->expense_concept_id)->count();

        $sin_expense = $this->marcar_hecha($tarea, '2026-09-14');

        $sin_expense->assertStatus(422);
        $this->assertEquals(
            'Esta tarea tiene un gasto asociado: indicá cómo se pagó, o marcala como hecha sin registrar el gasto.',
            $sin_expense->json('message')
        );

        // Con `expense` pero sin métodos de pago, lo mismo.
        $this->marcar_hecha($tarea, '2026-09-14', [
            'expense' => ['amount' => 5000, 'moneda_id' => 1, 'importe_iva' => 0, 'payment_methods' => []],
        ])->assertStatus(422);

        // Con métodos de pago pero monto 0, lo mismo.
        $this->marcar_hecha($tarea, '2026-09-14', [
            'expense' => $this->gasto_en_efectivo(5000, ['amount' => 0]),
        ])->assertStatus(422);

        $this->assertEquals(0, PendingCompleted::where('pending_id', $tarea->id)->count());
        $this->assertEquals($gastos_antes, Expense::where('expense_concept_id', $tarea->expense_concept_id)->count());
        $this->assertFalse($tarea->fresh()->completado);
    }

    /**
     * (d) `sin_gasto: true` → 201 sin Expense; la puntual queda completada.
     *
     * @test
     */
    public function marcar_sin_registrar_el_gasto_completa_la_tarea_sin_crear_gasto()
    {
        $tarea = $this->crear_tarea_con_gasto(5000, ['fecha_realizacion' => '2026-09-14']);

        $gastos_antes = Expense::where('expense_concept_id', $tarea->expense_concept_id)->count();

        $response = $this->marcar_hecha($tarea, '2026-09-14', ['sin_gasto' => true]);

        $response->assertStatus(201);

        $this->assertNull($response->json('expense'));
        $this->assertNull($response->json('model.expense_id'));

        $realizada = PendingCompleted::find($response->json('model.id'));
        $this->assertNull($realizada->expense_id);
        $this->assertNull($realizada->expense_amount);

        $this->assertEquals($gastos_antes, Expense::where('expense_concept_id', $tarea->expense_concept_id)->count());
        $this->assertTrue($tarea->fresh()->completado);
    }

    /**
     * (e) Caja sin apertura → 422 con el mismo texto que Gastos, y nada escrito.
     *
     * @test
     */
    public function una_caja_que_nunca_se_abrio_da_422_sin_escribir_nada()
    {
        $tarea = $this->crear_tarea_con_gasto(5000, ['fecha_realizacion' => '2026-09-14']);

        // Una caja nueva no tiene aperturas por definición (el rollback la limpia).
        $caja_nueva = Caja::create([
            'name'      => 'Caja agenda sin apertura',
            'num'       => 9901,
            'user_id'   => $tarea->user_id,
        ]);

        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $gastos_antes = Expense::where('expense_concept_id', $tarea->expense_concept_id)->count();

        $response = $this->marcar_hecha($tarea, '2026-09-14', [
            'expense' => [
                'amount'            => 5000,
                'moneda_id'         => 1,
                'importe_iva'       => 0,
                'payment_methods'   => [$this->fila_metodo_de_pago($metodo->id, 5000, $caja_nueva->id)],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Caja agenda sin apertura', $response->json('message'));
        $this->assertStringContainsString('nunca se abrieron', $response->json('message'));

        $this->assertEquals(0, PendingCompleted::where('pending_id', $tarea->id)->count());
        $this->assertEquals($gastos_antes, Expense::where('expense_concept_id', $tarea->expense_concept_id)->count());
        $this->assertEquals(0, MovimientoCaja::where('caja_id', $caja_nueva->id)->count());
        $this->assertFalse($tarea->fresh()->completado);

        Caja::where('id', $caja_nueva->id)->delete();
    }

    /**
     * (f) Una puntual queda `completado = 1` y `DELETE pending-completed/{id}` la vuelve a 0,
     * responde `{ expense_id }` y deja el Expense intacto.
     *
     * @test
     */
    public function deshacer_vuelve_la_puntual_a_pendiente_y_no_toca_el_gasto()
    {
        $tarea = $this->crear_tarea_con_gasto(5000, ['fecha_realizacion' => '2026-09-14']);

        $marcada = $this->marcar_hecha($tarea, '2026-09-14', ['expense' => $this->gasto_en_efectivo(5000)]);

        $marcada->assertStatus(201);

        $this->assertTrue($tarea->fresh()->completado);

        $realizada_id = $marcada->json('model.id');
        $expense_id = $marcada->json('expense.id');

        $deshecha = $this->deleteJson('api/pending-completed/'.$realizada_id);

        $deshecha->assertStatus(200);
        $this->assertEquals($expense_id, $deshecha->json('expense_id'));

        $this->assertNull(PendingCompleted::find($realizada_id));
        $this->assertFalse($tarea->fresh()->completado);

        $gasto = Expense::find($expense_id);
        $this->assertNotNull($gasto, 'Deshacer no puede borrar el gasto: eso se hace desde Gastos, compensando caja.');
        $this->assertEquals(1, MovimientoCaja::where('expense_id', $expense_id)->count());

        // Y se puede volver a marcar (sin gasto esta vez): el flag bajó de verdad.
        $this->marcar_hecha($tarea, '2026-09-14', ['sin_gasto' => true])->assertStatus(201);

        // Deshacer una sin gasto responde expense_id null.
        $sin_gasto = PendingCompleted::where('pending_id', $tarea->id)->first();
        $this->deleteJson('api/pending-completed/'.$sin_gasto->id)
            ->assertStatus(200)
            ->assertJson(['expense_id' => null]);
    }

    /**
     * (g) Compatibilidad con la SPA vieja: POST con `id` en vez de `pending_id`, sin `expense`,
     * en una tarea sin concepto → 201.
     *
     * @test
     */
    public function la_spa_vieja_manda_id_y_sin_expense_y_sigue_funcionando()
    {
        $tarea = $this->crear_tarea(['detalle' => 'Sin gasto', 'fecha_realizacion' => '2026-09-14']);

        $response = $this->postJson('api/pending-completed', [
            'id'                => $tarea->id,
            'detalle'           => $tarea->detalle,
            'notas'             => null,
            'fecha_realizacion' => '2026-09-14 00:00:00',
        ]);

        $response->assertStatus(201);

        $this->assertEquals($tarea->id, $response->json('model.pending_id'));
        $this->assertNull($response->json('expense'));
        $this->assertTrue($tarea->fresh()->completado);

        // Una puntual sin fecha en el body también anda: tiene una sola fecha posible.
        $otra = $this->crear_tarea(['detalle' => 'Sin fecha en el body', 'fecha_realizacion' => '2026-09-10']);

        $sin_fecha = $this->postJson('api/pending-completed', ['pending_id' => $otra->id]);

        $sin_fecha->assertStatus(201);
        $this->assertEquals('2026-09-10', substr($sin_fecha->json('model.fecha_realizacion'), 0, 10));
    }

    /**
     * (h) Una recurrente se puede marcar en dos fechas distintas (dos realizadas, dos gastos) y
     * nunca queda `completado`.
     *
     * @test
     */
    public function una_recurrente_se_marca_por_ocurrencia_y_no_se_completa()
    {
        $tarea = $this->crear_tarea_con_gasto(1000, [
            'fecha_realizacion'     => '2026-09-01',
            'es_recurrente'         => true,
            'unidad_frecuencia_id'  => $this->unidad('week')->id,
            'cantidad_frecuencia'   => 1,
        ]);

        $primera = $this->marcar_hecha($tarea, '2026-09-01', ['expense' => $this->gasto_en_efectivo(1000)]);
        $segunda = $this->marcar_hecha($tarea, '2026-09-08', ['expense' => $this->gasto_en_efectivo(1200)]);

        $primera->assertStatus(201);
        $segunda->assertStatus(201);

        $this->assertNotEquals($primera->json('expense.id'), $segunda->json('expense.id'));
        $this->assertEqualsWithDelta(1200, (float) $segunda->json('model.expense_amount'), self::DELTA);

        $this->assertEquals(2, PendingCompleted::where('pending_id', $tarea->id)->count());
        $this->assertFalse($tarea->fresh()->completado);

        // Deshacer una de una recurrente tampoco la marca de ninguna forma.
        $this->deleteJson('api/pending-completed/'.$primera->json('model.id'))->assertStatus(200);

        $this->assertEquals(1, PendingCompleted::where('pending_id', $tarea->id)->count());
        $this->assertFalse($tarea->fresh()->completado);
    }

    /**
     * `GET pending-completed/from-date/{desde}/{hasta}` trae las hechas del rango con `pending`
     * (con su concepto) y `expense`, de la más reciente a la más vieja.
     *
     * @test
     */
    public function el_listado_de_realizadas_trae_la_tarea_y_el_gasto()
    {
        $con_gasto = $this->crear_tarea_con_gasto(700, ['fecha_realizacion' => '2026-09-13']);
        $sin_gasto = $this->crear_tarea(['detalle' => 'Sin gasto', 'fecha_realizacion' => '2026-09-14']);

        $this->marcar_hecha($con_gasto, '2026-09-13', ['expense' => $this->gasto_en_efectivo(700)])->assertStatus(201);

        $this->fijar_reloj_en('2026-09-14 11:00:00');

        $this->marcar_hecha($sin_gasto, '2026-09-14')->assertStatus(201);

        $response = $this->getJson('api/pending-completed/from-date/2026-09-14/2026-09-14');

        $response->assertStatus(200);

        $models = array_values(array_filter($response->json('models'), function ($model) use ($con_gasto, $sin_gasto) {
            return in_array($model['pending_id'], [$con_gasto->id, $sin_gasto->id]);
        }));

        $this->assertCount(2, $models);

        // La más reciente primero (fecha_realizada desc).
        $this->assertEquals($sin_gasto->id, $models[0]['pending_id']);
        $this->assertNull($models[0]['expense']);

        $this->assertEquals($con_gasto->id, $models[1]['pending_id']);
        $this->assertEquals('Alquiler', $models[1]['pending']['expense_concept']['name']);
        $this->assertNotNull($models[1]['expense']);
        $this->assertEqualsWithDelta(700, (float) $models[1]['expense']['amount'], self::DELTA);
        $this->assertArrayHasKey('num', $models[1]['expense']);

        // Fuera del rango, no aparecen.
        $vacio = $this->getJson('api/pending-completed/from-date/2026-09-01/2026-09-13');

        $ids = array_map(function ($model) {
            return $model['pending_id'];
        }, $vacio->json('models'));

        $this->assertNotContains($sin_gasto->id, $ids);
        $this->assertNotContains($con_gasto->id, $ids);
    }
}
