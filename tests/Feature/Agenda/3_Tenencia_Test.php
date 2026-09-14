<?php

namespace Tests\Feature\Agenda;

use App\Models\Pending;
use App\Models\PendingCompleted;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Misión agenda-tareas-calendario (14/9/2026) — tenencia: `PUT`/`DELETE pending/{id}`,
 * `GET pending/{id}` y `DELETE pending-completed/{id}` de OTRA cuenta responden 404 y la fila
 * sigue igual.
 *
 * Cubre el defecto 3 del plan: hasta hoy update()/destroy() hacían `find($id)` pelado y cualquier
 * usuario autenticado podía editar o borrar pendientes de otra cuenta con solo adivinar el id.
 *
 * El otro comercio se crea con User::create() mínimo (el rollback lo limpia) y sus filas se
 * insertan directo: es setup, no el comportamiento bajo prueba.
 *
 * @group agenda
 */
class Tenencia_Test extends AgendaTestCase
{
    /**
     * @var \App\Models\User
     */
    protected $otro;

    /**
     * @var \App\Models\Pending
     */
    protected $ajena;

    /**
     * @var \App\Models\PendingCompleted
     */
    protected $ajena_realizada;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->otro = User::create([
            'name'     => 'Otro comercio agenda',
            'email'    => 'agenda-tenencia-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->ajena = Pending::create([
            'detalle'           => 'Tarea ajena',
            'fecha_realizacion' => '2026-09-20 00:00:00',
            'es_recurrente'     => 0,
            'expense_amount'    => 1234,
            'completado'        => 0,
            'notas'             => 'Notas ajenas',
            'user_id'           => $this->otro->id,
        ]);

        $this->ajena_realizada = PendingCompleted::create([
            'pending_id'        => $this->ajena->id,
            'detalle'           => 'Tarea ajena',
            'fecha_realizacion' => '2026-09-20 00:00:00',
            'fecha_realizada'   => '2026-09-20 10:00:00',
            'user_id'           => $this->otro->id,
        ]);

        $this->tareas_creadas[] = $this->ajena->id;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        PendingCompleted::where('id', $this->ajena_realizada->id)->delete();

        parent::tearDown();
    }

    /**
     * @test
     */
    public function editar_una_tarea_de_otra_cuenta_da_404_y_no_la_toca()
    {
        $response = $this->putJson('api/pending/'.$this->ajena->id, [
            'detalle'           => 'Pisada',
            'fecha_realizacion' => '2026-01-01',
            'es_recurrente'     => false,
        ]);

        $response->assertStatus(404)->assertJsonStructure(['message']);

        $fresca = $this->ajena->fresh();

        $this->assertEquals('Tarea ajena', $fresca->detalle);
        $this->assertEquals('2026-09-20', substr($fresca->fecha_realizacion, 0, 10));
        $this->assertEquals('Notas ajenas', $fresca->notas);
        $this->assertEquals($this->otro->id, $fresca->user_id);
    }

    /**
     * @test
     */
    public function borrar_una_tarea_de_otra_cuenta_da_404_y_la_deja()
    {
        $this->deleteJson('api/pending/'.$this->ajena->id)
            ->assertStatus(404)
            ->assertJsonStructure(['message']);

        $this->assertNotNull(Pending::find($this->ajena->id));
    }

    /**
     * @test
     */
    public function ver_una_tarea_de_otra_cuenta_da_404()
    {
        $this->getJson('api/pending/'.$this->ajena->id)
            ->assertStatus(404)
            ->assertJsonStructure(['message']);
    }

    /**
     * @test
     */
    public function marcar_una_tarea_de_otra_cuenta_da_404_y_no_crea_nada()
    {
        $antes = PendingCompleted::where('pending_id', $this->ajena->id)->count();

        $this->postJson('api/pending-completed', [
            'pending_id'        => $this->ajena->id,
            'fecha_realizacion' => '2026-09-20',
        ])->assertStatus(404)->assertJsonStructure(['message']);

        $this->assertEquals($antes, PendingCompleted::where('pending_id', $this->ajena->id)->count());
    }

    /**
     * @test
     */
    public function deshacer_una_realizada_de_otra_cuenta_da_404_y_la_deja()
    {
        $this->deleteJson('api/pending-completed/'.$this->ajena_realizada->id)
            ->assertStatus(404)
            ->assertJsonStructure(['message']);

        $this->assertNotNull(PendingCompleted::find($this->ajena_realizada->id));

        // Tampoco se puede ver ni editar.
        $this->getJson('api/pending-completed/'.$this->ajena_realizada->id)->assertStatus(404);
        $this->putJson('api/pending-completed/'.$this->ajena_realizada->id, ['notas' => 'x'])->assertStatus(404);
    }

    /**
     * Un id que no existe también es 404 (no se distingue "no existe" de "no es tuya").
     *
     * @test
     */
    public function un_id_inexistente_da_404()
    {
        $this->putJson('api/pending/999999999', ['detalle' => 'x', 'fecha_realizacion' => '2026-01-01'])->assertStatus(404);
        $this->deleteJson('api/pending/999999999')->assertStatus(404);
        $this->deleteJson('api/pending-completed/999999999')->assertStatus(404);
    }

    /**
     * El contraste: la dueña sí puede editar y borrar la suya, y `update()` ahora guarda
     * `expense_amount` y `fecha_fin_recurrencia` (defecto 2 del plan).
     *
     * @test
     */
    public function la_duena_si_puede_editar_y_borrar_la_suya()
    {
        $propia = $this->crear_tarea_con_gasto(1000, ['detalle' => 'Propia', 'fecha_realizacion' => '2026-09-20']);

        $response = $this->putJson('api/pending/'.$propia->id, [
            'detalle'               => 'Propia editada',
            'fecha_realizacion'     => '2026-09-21',
            'es_recurrente'         => true,
            'unidad_frecuencia_id'  => $this->unidad('month')->id,
            'cantidad_frecuencia'   => 2,
            'fecha_fin_recurrencia' => '2027-03-31',
            'expense_concept_id'    => $propia->expense_concept_id,
            'expense_amount'        => 2500,
            'notas'                 => 'Editada',
        ]);

        $response->assertStatus(200);

        $this->assertEquals('Propia editada', $response->json('model.detalle'));
        $this->assertEquals('Mes', $response->json('model.unidad_frecuencia.name'));
        $this->assertEquals('Alquiler', $response->json('model.expense_concept.name'));

        $fresca = $propia->fresh();

        $this->assertEquals('Propia editada', $fresca->detalle);
        $this->assertEquals('2026-09-21', substr($fresca->fecha_realizacion, 0, 10));
        $this->assertTrue($fresca->es_recurrente);
        $this->assertEquals(2, $fresca->cantidad_frecuencia);
        $this->assertEquals('2027-03-31', $fresca->fecha_fin_recurrencia);
        $this->assertEquals(2500, (float) $fresca->expense_amount);
        $this->assertEquals('Editada', $fresca->notas);
        $this->assertFalse($fresca->completado);

        $this->deleteJson('api/pending/'.$propia->id)->assertStatus(200);

        $this->assertNull(Pending::find($propia->id));
    }
}
