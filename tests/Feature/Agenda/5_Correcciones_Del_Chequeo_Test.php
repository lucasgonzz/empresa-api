<?php

namespace Tests\Feature\Agenda;

use App\Models\ExpenseConcept;
use App\Models\Pending;
use App\Models\PendingCompleted;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Misión agenda-tareas-calendario (14/9/2026) — lo que dejó el chequeo independiente del diff,
 * cada punto con su test:
 *
 *  1. Editar la REGLA de una recurrente no resucita como vencidas las ocurrencias ya hechas: si
 *     la primera fecha nueva quedó en el pasado, la base se mueve a la primera ocurrencia de la
 *     regla nueva desde hoy (PendingController::reanclar_si_cambio_la_regla()). Una edición que
 *     no toca la regla no mueve nada.
 *  2. `POST pending-completed` con una fecha que no es ocurrencia de la tarea → 422 y nada
 *     escrito (AgendaHelper::es_ocurrencia()).
 *  3. `expense_concept_id` de otra cuenta → 422.
 *  4. `PUT pending/{id}` con `completado: false` reabre una puntual que la SPA vieja dejó en
 *     `completado = 1` sin PendingCompleted; con `completado: true` no la cierra.
 *  5. `k_inicial()` arranca la expansión cerca del rango sin perder ocurrencias: una diaria de
 *     hace dos años devuelve exactamente los días del rango.
 *
 * El reloj se fija en el 14/9/2026 como en las suites 1 y 2.
 *
 * @group agenda
 */
class Correcciones_Del_Chequeo_Test extends AgendaTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fijar_reloj_en('2026-09-14 10:00:00');
    }

    /**
     * (1) Mensual del 5/6 con tres realizadas (5/6, 5/7, 5/8). Se edita "primera vez" al 10/6:
     * no aparece ninguna vencida, la base queda en la primera ocurrencia desde hoy (10/10) y las
     * tres realizadas siguen existiendo con sus fechas.
     *
     * @test
     */
    public function editar_la_regla_de_una_recurrente_no_resucita_lo_ya_hecho_como_vencido()
    {
        $tarea = $this->crear_tarea_recurrente('month', 1, '2026-06-05', ['detalle' => 'Pagar el sueldo']);

        foreach (['2026-06-05', '2026-07-05', '2026-08-05'] as $fecha) {
            $this->marcar_hecha($tarea, $fecha)->assertStatus(201);
        }

        $this->assertEquals(3, PendingCompleted::where('pending_id', $tarea->id)->count());

        // Antes de editar: la única vencida es la del 5/9 (no se hizo).
        $agenda = $this->agenda('2026-09-14', '2026-10-31');
        $this->assertEquals(['2026-09-05'], $this->fechas_de($this->ocurrencias_de($agenda['vencidas'], $tarea)));

        $this->putJson('api/pending/'.$tarea->id, [
            'detalle'               => 'Pagar el sueldo',
            'fecha_realizacion'     => '2026-06-10',
            'es_recurrente'         => true,
            'unidad_frecuencia_id'  => $this->unidad('month')->id,
            'cantidad_frecuencia'   => 1,
        ])->assertStatus(200);

        $tarea->refresh();

        // La base se reancló a la primera ocurrencia de "el 10 de cada mes" desde hoy (14/9).
        $this->assertEquals('2026-10-10 00:00:00', $tarea->fecha_realizacion);

        $agenda = $this->agenda('2026-09-14', '2026-11-30');

        $this->assertEquals([], $this->fechas_de($this->ocurrencias_de($agenda['vencidas'], $tarea)), 'No tiene que haber vencidas resucitadas.');
        $this->assertEquals(['2026-10-10', '2026-11-10'], $this->fechas_de($this->ocurrencias_de($agenda['ocurrencias'], $tarea)));

        // Lo hecho queda en Realizadas con sus fechas originales.
        $realizadas = PendingCompleted::where('pending_id', $tarea->id)->orderBy('fecha_realizacion')->pluck('fecha_realizacion')->all();
        $this->assertEquals(['2026-06-05 00:00:00', '2026-07-05 00:00:00', '2026-08-05 00:00:00'], $realizadas);
    }

    /**
     * (1 bis) Editar solo el detalle y el monto de una recurrente con base en el pasado NO mueve
     * la base: las vencidas legítimas siguen ahí.
     *
     * @test
     */
    public function editar_sin_tocar_la_regla_no_mueve_la_base()
    {
        $tarea = $this->crear_tarea_recurrente('month', 1, '2026-08-20', ['detalle' => 'Pagar el alquiler']);

        $this->putJson('api/pending/'.$tarea->id, [
            'detalle'               => 'Pagar el alquiler (local nuevo)',
            'fecha_realizacion'     => '2026-08-20',
            'es_recurrente'         => true,
            'unidad_frecuencia_id'  => $this->unidad('month')->id,
            'cantidad_frecuencia'   => 1,
            'notas'                 => 'Transferir antes del mediodía',
        ])->assertStatus(200);

        $tarea->refresh();

        $this->assertEquals('2026-08-20 00:00:00', $tarea->fecha_realizacion);
        $this->assertEquals('Pagar el alquiler (local nuevo)', $tarea->detalle);

        $agenda = $this->agenda('2026-09-14', '2026-09-30');
        $this->assertEquals(['2026-08-20'], $this->fechas_de($this->ocurrencias_de($agenda['vencidas'], $tarea)));
    }

    /**
     * (1 ter) Si la regla cambia pero la primera fecha nueva es hoy o futura, se guarda tal cual.
     *
     * @test
     */
    public function una_regla_nueva_con_fecha_futura_se_guarda_sin_reanclar()
    {
        $tarea = $this->crear_tarea_recurrente('week', 1, '2026-09-01');

        $this->putJson('api/pending/'.$tarea->id, [
            'detalle'               => $tarea->detalle,
            'fecha_realizacion'     => '2026-09-21',
            'es_recurrente'         => true,
            'unidad_frecuencia_id'  => $this->unidad('week')->id,
            'cantidad_frecuencia'   => 2,
        ])->assertStatus(200);

        $tarea->refresh();

        $this->assertEquals('2026-09-21 00:00:00', $tarea->fecha_realizacion);
        $this->assertEquals(2, $tarea->cantidad_frecuencia);
    }

    /**
     * (2) Marcar el 15/9 de una mensual del 20 → 422, sin PendingCompleted. Marcar el 20/10 (una
     * ocurrencia real) → 201. Para una puntual, cualquier fecha distinta de la suya → 422.
     *
     * @test
     */
    public function marcar_una_fecha_que_no_es_ocurrencia_da_422_sin_escribir_nada()
    {
        $mensual = $this->crear_tarea_recurrente('month', 1, '2026-09-20');

        $this->marcar_hecha($mensual, '2026-09-15')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertEquals(0, PendingCompleted::where('pending_id', $mensual->id)->count());

        $this->marcar_hecha($mensual, '2026-10-20')->assertStatus(201);

        $puntual = $this->crear_tarea(['fecha_realizacion' => '2026-09-16']);

        $this->marcar_hecha($puntual, '2026-09-17')->assertStatus(422);
        $this->assertEquals(0, PendingCompleted::where('pending_id', $puntual->id)->count());
        $this->assertFalse($puntual->fresh()->completado);

        $this->marcar_hecha($puntual, '2026-09-16')->assertStatus(201);
    }

    /**
     * (3) Un concepto de gasto de otra cuenta no se puede asociar: 422 y la tarea no se crea.
     *
     * @test
     */
    public function un_concepto_de_gasto_ajeno_da_422()
    {
        $otro = User::create([
            'name'     => 'Otro comercio conceptos',
            'email'    => 'agenda-concepto-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajeno = ExpenseConcept::create([
            'num'     => 999,
            'name'    => 'Concepto ajeno',
            'user_id' => $otro->id,
        ]);

        $this->postJson('api/pending', [
            'detalle'               => 'Con concepto ajeno',
            'fecha_realizacion'     => '2026-09-20',
            'expense_concept_id'    => $ajeno->id,
            'expense_amount'        => 100,
        ])->assertStatus(422)->assertJsonStructure(['message']);

        $this->assertEquals(0, Pending::where('detalle', 'Con concepto ajeno')->count());

        $this->postJson('api/pending', [
            'detalle'               => 'Con concepto inexistente',
            'fecha_realizacion'     => '2026-09-20',
            'expense_concept_id'    => 99999999,
        ])->assertStatus(422);
    }

    /**
     * (4) Una puntual con `completado = 1` y sin PendingCompleted (resto de la SPA vieja) se
     * reabre con `PUT pending/{id}` + `completado: false`; `completado: true` por esta vía no
     * cierra nada.
     *
     * @test
     */
    public function put_con_completado_false_reabre_una_puntual_vieja_y_true_no_la_cierra()
    {
        $tarea = $this->crear_tarea(['detalle' => 'Puntual vieja', 'fecha_realizacion' => '2026-09-10']);

        Pending::where('id', $tarea->id)->update(['completado' => 1]);

        // Así la ve la agenda: hecha, sin realizada para deshacer, y por eso no vence.
        $agenda = $this->agenda('2026-09-01', '2026-09-30');
        $de_la_tarea = $this->ocurrencias_de($agenda['ocurrencias'], $tarea);
        $this->assertTrue($de_la_tarea[0]['completado']);
        $this->assertNull($de_la_tarea[0]['pending_completed_id']);
        $this->assertEquals([], $this->ocurrencias_de($agenda['vencidas'], $tarea));

        $body = [
            'detalle'           => 'Puntual vieja',
            'fecha_realizacion' => '2026-09-10',
            'es_recurrente'     => false,
        ];

        $this->putJson('api/pending/'.$tarea->id, $body + ['completado' => false])->assertStatus(200);
        $this->assertFalse($tarea->fresh()->completado);

        // Ahora sí vence (10/9 < 14/9 y no está hecha).
        $agenda = $this->agenda('2026-09-01', '2026-09-30');
        $this->assertEquals(['2026-09-10'], $this->fechas_de($this->ocurrencias_de($agenda['vencidas'], $tarea)));

        $this->putJson('api/pending/'.$tarea->id, $body + ['completado' => true])->assertStatus(200);
        $this->assertFalse($tarea->fresh()->completado, 'Cerrar una tarea es trabajo de marcar como hecha, no de PUT.');

        // Sin la clave, no toca el flag.
        Pending::where('id', $tarea->id)->update(['completado' => 1]);
        $this->putJson('api/pending/'.$tarea->id, $body)->assertStatus(200);
        $this->assertTrue($tarea->fresh()->completado);
    }

    /**
     * (5) Una diaria que arrancó hace dos años devuelve exactamente los días del rango pedido, y
     * una cada 3 semanas y una mensual viejas devuelven las ocurrencias correctas: k_inicial()
     * ahorra vueltas sin saltearse ninguna.
     *
     * @test
     */
    public function la_expansion_desde_una_base_vieja_no_pierde_ocurrencias()
    {
        $diaria = $this->crear_tarea_recurrente('day', 1, '2024-09-01', ['detalle' => 'Diaria vieja']);
        $tres_semanas = $this->crear_tarea_recurrente('week', 3, '2024-09-02', ['detalle' => 'Cada 3 semanas vieja']);
        $mensual = $this->crear_tarea_recurrente('month', 1, '2024-01-31', ['detalle' => 'Mensual del 31 vieja']);

        $agenda = $this->agenda('2026-09-14', '2026-09-20');

        $this->assertEquals(
            ['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20'],
            $this->fechas_de($this->ocurrencias_de($agenda['ocurrencias'], $diaria))
        );

        // 2/9/2024 (lunes) + 3 semanas × k cae siempre en lunes: la ocurrencia 35 es el 7/9/2026
        // y la 36 el 28/9/2026, ninguna entre el 14 y el 20.
        $this->assertEquals([], $this->fechas_de($this->ocurrencias_de($agenda['ocurrencias'], $tres_semanas)));

        $agenda_ancha = $this->agenda('2026-09-01', '2026-10-31');
        $this->assertEquals(['2026-09-07', '2026-09-28', '2026-10-19'], $this->fechas_de($this->ocurrencias_de($agenda_ancha['ocurrencias'], $tres_semanas)));
        $this->assertEquals(['2026-09-30', '2026-10-31'], $this->fechas_de($this->ocurrencias_de($agenda_ancha['ocurrencias'], $mensual)));

        // Y las vencidas de la mensual respetan el tope de 30 (hay 32 ocurrencias vencidas).
        $vencidas_mensual = $this->ocurrencias_de($agenda['vencidas'], $mensual);
        $this->assertCount(30, $vencidas_mensual);
        $this->assertEquals(2, $vencidas_mensual[0]['vencidas_omitidas']);
        $this->assertEquals('2026-08-31', $vencidas_mensual[29]['fecha']);
    }
}
