<?php

namespace Tests\Feature\Agenda;

use App\Models\Pending;

/**
 * Misión agenda-tareas-calendario (14/9/2026) — validación mínima de `POST pending` /
 * `PUT pending/{id}` (contrato §2): 422 `{ message }` con lo que falta, y la forma en que se
 * guarda lo que sí vino (fecha a las 00:00:00, recurrencia limpia cuando no se repite, gasto
 * con monto 0 permitido).
 *
 * @group agenda
 */
class Validaciones_Test extends AgendaTestCase
{
    /**
     * @test
     */
    public function sin_detalle_o_sin_fecha_da_422()
    {
        $this->postJson('api/pending', ['detalle' => '', 'fecha_realizacion' => '2026-09-20'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->postJson('api/pending', ['detalle' => '   ', 'fecha_realizacion' => '2026-09-20'])
            ->assertStatus(422);

        $this->postJson('api/pending', ['detalle' => 'Sin fecha'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->postJson('api/pending', ['detalle' => 'Fecha inválida', 'fecha_realizacion' => '2026-02-30'])
            ->assertStatus(422);

        $this->postJson('api/pending', ['detalle' => 'Fecha en otro formato', 'fecha_realizacion' => '20/09/2026'])
            ->assertStatus(422);

        $this->assertEquals(0, Pending::where('detalle', 'Sin fecha')->count());
    }

    /**
     * @test
     */
    public function una_recurrente_exige_unidad_existente_y_cantidad_entera_mayor_a_cero()
    {
        $base = ['detalle' => 'Recurrente incompleta', 'fecha_realizacion' => '2026-09-20', 'es_recurrente' => true];

        $this->postJson('api/pending', $base + ['unidad_frecuencia_id' => null, 'cantidad_frecuencia' => 1])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->postJson('api/pending', $base + ['unidad_frecuencia_id' => 999999, 'cantidad_frecuencia' => 1])
            ->assertStatus(422);

        $unidad = $this->unidad('week')->id;

        $this->postJson('api/pending', $base + ['unidad_frecuencia_id' => $unidad, 'cantidad_frecuencia' => 0])
            ->assertStatus(422);

        $this->postJson('api/pending', $base + ['unidad_frecuencia_id' => $unidad, 'cantidad_frecuencia' => 1.5])
            ->assertStatus(422);

        $this->postJson('api/pending', $base + ['unidad_frecuencia_id' => $unidad, 'cantidad_frecuencia' => null])
            ->assertStatus(422);

        // Fin anterior a la primera fecha, tampoco.
        $this->postJson('api/pending', $base + ['unidad_frecuencia_id' => $unidad, 'cantidad_frecuencia' => 1, 'fecha_fin_recurrencia' => '2026-09-01'])
            ->assertStatus(422);

        $this->assertEquals(0, Pending::where('detalle', 'Recurrente incompleta')->count());

        // Con todo, 201 y la respuesta trae la unidad cargada.
        $ok = $this->postJson('api/pending', $base + ['unidad_frecuencia_id' => $unidad, 'cantidad_frecuencia' => '3']);

        $ok->assertStatus(201);

        $this->tareas_creadas[] = $ok->json('model.id');

        $this->assertTrue($ok->json('model.es_recurrente'));
        $this->assertSame(3, $ok->json('model.cantidad_frecuencia'));
        $this->assertEquals('week', $ok->json('model.unidad_frecuencia.slug'));
    }

    /**
     * @test
     */
    public function un_gasto_asociado_exige_monto_numerico_no_negativo_y_admite_cero()
    {
        $concepto = $this->resolver_concepto_gasto_por_nombre(\Database\Seeders\testing\TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        $base = ['detalle' => 'Con gasto', 'fecha_realizacion' => '2026-09-20', 'expense_concept_id' => $concepto->id];

        $this->postJson('api/pending', $base + ['expense_amount' => -1])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->postJson('api/pending', $base + ['expense_amount' => 'mucho'])
            ->assertStatus(422);

        // 0 es "monto a definir al pagar".
        $cero = $this->postJson('api/pending', $base + ['expense_amount' => 0]);

        $cero->assertStatus(201);
        $this->tareas_creadas[] = $cero->json('model.id');
        $this->assertEquals(0, (float) $cero->json('model.expense_amount'));
        $this->assertEquals('Alquiler', $cero->json('model.expense_concept.name'));

        // Sin monto (la SPA vieja no siempre lo manda) cuenta como 0.
        $sin_monto = $this->postJson('api/pending', $base);

        $sin_monto->assertStatus(201);
        $this->tareas_creadas[] = $sin_monto->json('model.id');
        $this->assertEquals(0, (float) $sin_monto->json('model.expense_amount'));
    }

    /**
     * Lo que se guarda: la fecha a las 00:00:00 aunque venga con hora, y una tarea que no se
     * repite queda sin unidad, cantidad ni fin aunque el body los traiga (un toggle apagado en
     * el form no tiene que dejar restos).
     *
     * @test
     */
    public function guarda_la_fecha_sin_hora_y_limpia_la_recurrencia_cuando_no_se_repite()
    {
        $tarea = $this->crear_tarea([
            'detalle'               => 'Con hora',
            'fecha_realizacion'     => '2026-09-20T15:30:00.000Z',
            'es_recurrente'         => false,
            'unidad_frecuencia_id'  => $this->unidad('day')->id,
            'cantidad_frecuencia'   => 4,
            'fecha_fin_recurrencia' => '2026-12-31',
            'expense_concept_id'    => 0,
            'expense_amount'        => 99,
        ]);

        $this->assertEquals('2026-09-20 00:00:00', $tarea->fecha_realizacion);
        $this->assertFalse($tarea->es_recurrente);
        $this->assertNull($tarea->unidad_frecuencia_id);
        $this->assertNull($tarea->cantidad_frecuencia);
        $this->assertNull($tarea->fecha_fin_recurrencia);
        // Concepto 0 es "sin gasto": el monto no se guarda suelto.
        $this->assertNull($tarea->expense_concept_id);
        $this->assertNull($tarea->expense_amount);
        $this->assertFalse($tarea->completado);
        $this->assertNull($tarea->notas);
    }
}
