<?php

namespace Tests\Feature\Agenda;

use App\Http\Controllers\Helpers\agenda\AgendaHelper;
use App\Models\Pending;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Misión agenda-tareas-calendario (14/9/2026) — expansión de ocurrencias y endpoint
 * `GET pending-agenda/{desde}/{hasta}` (AgendaHelper + PendingController::agenda()).
 *
 * Cubre el defecto 4 del plan (la recurrencia mensual iterativa desbordaba: 31/1 + 1 mes = 3/3)
 * y el contrato de la respuesta: `hoy`, `vencidas` (con tope de 30 por tarea) y `ocurrencias`
 * (con `completado` según exista el PendingCompleted de esa fecha).
 *
 * @group agenda
 */
class Ocurrencias_Test extends AgendaTestCase
{
    /**
     * Todos los tests de esta clase viven en el mismo "hoy" para que `vencida` y `vencidas` sean
     * deterministas. Se resetea en limpiar_escenarios() (tearDown).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fijar_reloj_en('2026-09-14 10:00:00');
    }

    /**
     * (a) Una mensual con base 31/1 cae el 28/2, el 31/3 y el 30/4: se calcula desde la base
     * multiplicando por k, no iterando, así "el 31 de cada mes" no deriva al 3.
     *
     * @test
     */
    public function una_mensual_del_31_no_desborda_y_no_deriva()
    {
        $tarea = $this->crear_tarea_recurrente('month', 1, '2026-01-31');

        // 31/1 → 31/5 son 120 días justos, el máximo que acepta el endpoint.
        $agenda = $this->agenda('2026-01-31', '2026-05-31');

        $fechas = $this->fechas_de($this->ocurrencias_de($agenda['ocurrencias'], $tarea));

        $this->assertEquals(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            $fechas
        );

        // El helper directo, por si alguien cambia el endpoint sin tocar el cálculo.
        $this->assertEquals('2026-02-28', AgendaHelper::ocurrencia($tarea->fresh(), 1)->format('Y-m-d'));
        $this->assertEquals('2026-04-30', AgendaHelper::ocurrencia($tarea->fresh(), 3)->format('Y-m-d'));
        $this->assertEquals('2027-01-31', AgendaHelper::ocurrencia($tarea->fresh(), 12)->format('Y-m-d'));
    }

    /**
     * (a bis) Lo mismo para una anual del 29/2: cae el 28/2 en los años no bisiestos.
     *
     * @test
     */
    public function una_anual_del_29_de_febrero_cae_el_28_en_los_no_bisiestos()
    {
        $tarea = $this->crear_tarea_recurrente('year', 1, '2024-02-29');

        $this->assertEquals('2025-02-28', AgendaHelper::ocurrencia($tarea->fresh(), 1)->format('Y-m-d'));
        $this->assertEquals('2028-02-29', AgendaHelper::ocurrencia($tarea->fresh(), 4)->format('Y-m-d'));
    }

    /**
     * (b) Una semanal cada 2 semanas en un rango de 5 semanas da 3 ocurrencias.
     *
     * @test
     */
    public function una_semanal_cada_dos_semanas_da_tres_ocurrencias_en_cinco_semanas()
    {
        $tarea = $this->crear_tarea_recurrente('week', 2, '2026-03-02');

        $agenda = $this->agenda('2026-03-02', '2026-04-05');

        $this->assertEquals(
            ['2026-03-02', '2026-03-16', '2026-03-30'],
            $this->fechas_de($this->ocurrencias_de($agenda['ocurrencias'], $tarea))
        );
    }

    /**
     * (c) `fecha_fin_recurrencia` corta la expansión (inclusive).
     *
     * @test
     */
    public function la_fecha_de_fin_corta_la_expansion()
    {
        $tarea = $this->crear_tarea_recurrente('day', 1, '2026-05-01', [
            'fecha_fin_recurrencia' => '2026-05-03',
        ]);

        $this->assertEquals('2026-05-03', $tarea->fecha_fin_recurrencia);

        $agenda = $this->agenda('2026-05-01', '2026-05-10');

        $ocurrencias = $this->ocurrencias_de($agenda['ocurrencias'], $tarea);

        $this->assertEquals(['2026-05-01', '2026-05-02', '2026-05-03'], $this->fechas_de($ocurrencias));

        $this->assertEquals('2026-05-03', $ocurrencias[0]['fecha_fin_recurrencia']);
    }

    /**
     * (d) La ocurrencia que tiene su PendingCompleted viene `completado = true` (con
     * `pending_completed_id`), las demás `false`. Y una puntual hecha sigue apareciendo en el
     * rango, marcada.
     *
     * @test
     */
    public function la_ocurrencia_marcada_viene_completada_y_las_demas_no()
    {
        $recurrente = $this->crear_tarea_recurrente('week', 1, '2026-06-01');
        $puntual = $this->crear_tarea(['detalle' => 'Puntual hecha', 'fecha_realizacion' => '2026-06-10']);

        $marcada = $this->marcar_hecha($recurrente, '2026-06-08');
        $marcada->assertStatus(201);

        $this->marcar_hecha($puntual, '2026-06-10')->assertStatus(201);

        $agenda = $this->agenda('2026-06-01', '2026-06-22');

        $ocurrencias = $this->ocurrencias_de($agenda['ocurrencias'], $recurrente);

        $this->assertEquals(['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22'], $this->fechas_de($ocurrencias));

        $this->assertFalse($ocurrencias[0]['completado']);
        $this->assertNull($ocurrencias[0]['pending_completed_id']);

        $this->assertTrue($ocurrencias[1]['completado']);
        $this->assertEquals($marcada->json('model.id'), $ocurrencias[1]['pending_completed_id']);
        $this->assertNull($ocurrencias[1]['expense_id']);
        // Hecha y en el pasado: no está vencida.
        $this->assertFalse($ocurrencias[1]['vencida']);

        $this->assertFalse($ocurrencias[2]['completado']);
        $this->assertFalse($ocurrencias[3]['completado']);

        $de_la_puntual = $this->ocurrencias_de($agenda['ocurrencias'], $puntual);

        $this->assertCount(1, $de_la_puntual);
        $this->assertTrue($de_la_puntual[0]['completado']);
        $this->assertFalse($de_la_puntual[0]['es_recurrente']);
    }

    /**
     * Forma de una Ocurrencia del contrato §2: todas las claves, con los tipos que promete.
     *
     * @test
     */
    public function la_ocurrencia_tiene_la_forma_del_contrato()
    {
        $tarea = $this->crear_tarea_con_gasto(850000, [
            'detalle'               => 'Pagar sueldo',
            'notas'                 => 'Antes del 5',
            'fecha_realizacion'     => '2026-09-20',
            'es_recurrente'         => true,
            'unidad_frecuencia_id'  => $this->unidad('month')->id,
            'cantidad_frecuencia'   => 1,
            'fecha_fin_recurrencia' => '2026-12-31',
        ]);

        $agenda = $this->agenda('2026-09-14', '2026-09-30');

        $this->assertEquals('2026-09-14', $agenda['hoy']);

        $ocurrencias = $this->ocurrencias_de($agenda['ocurrencias'], $tarea);

        $this->assertCount(1, $ocurrencias);

        $ocurrencia = $ocurrencias[0];

        $this->assertEquals($tarea->id.'_2026-09-20', $ocurrencia['key']);
        $this->assertEquals($tarea->id, $ocurrencia['pending_id']);
        $this->assertEquals('Pagar sueldo', $ocurrencia['detalle']);
        $this->assertEquals('Antes del 5', $ocurrencia['notas']);
        $this->assertEquals('2026-09-20', $ocurrencia['fecha']);
        $this->assertTrue($ocurrencia['es_recurrente']);
        $this->assertEquals($this->unidad('month')->id, $ocurrencia['unidad_frecuencia_id']);
        $this->assertEquals(['id' => $this->unidad('month')->id, 'name' => 'Mes', 'slug' => 'month'], $ocurrencia['unidad_frecuencia']);
        $this->assertSame(1, $ocurrencia['cantidad_frecuencia']);
        $this->assertEquals('2026-12-31', $ocurrencia['fecha_fin_recurrencia']);
        $this->assertEquals($tarea->expense_concept_id, $ocurrencia['expense_concept_id']);
        $this->assertEquals(['id' => $tarea->expense_concept_id, 'name' => 'Alquiler'], $ocurrencia['expense_concept']);
        $this->assertEquals(850000, $ocurrencia['expense_amount']);
        $this->assertFalse($ocurrencia['completado']);
        $this->assertNull($ocurrencia['pending_completed_id']);
        $this->assertNull($ocurrencia['expense_id']);
        $this->assertFalse($ocurrencia['vencida']);
        $this->assertSame(0, $ocurrencia['vencidas_omitidas']);

        // Una puntual sin nada: los opcionales vienen null, no ausentes.
        $pelada = $this->crear_tarea(['detalle' => 'Pelada', 'fecha_realizacion' => '2026-09-15']);

        $agenda = $this->agenda('2026-09-15', '2026-09-15');

        $de_la_pelada = $this->ocurrencias_de($agenda['ocurrencias'], $pelada);

        $this->assertCount(1, $de_la_pelada);

        foreach (['unidad_frecuencia_id', 'unidad_frecuencia', 'cantidad_frecuencia', 'fecha_fin_recurrencia', 'expense_concept_id', 'expense_concept', 'expense_amount', 'notas'] as $clave) {
            $this->assertArrayHasKey($clave, $de_la_pelada[0]);
            $this->assertNull($de_la_pelada[0][$clave], 'La clave '.$clave.' tendría que venir null.');
        }
    }

    /**
     * (e) `vencidas` no incluye completadas ni futuras, marca `vencida = true`, y respeta el tope
     * de 30 por tarea informando cuántas se omitieron.
     *
     * @test
     */
    public function las_vencidas_excluyen_hechas_y_futuras_y_respetan_el_tope()
    {
        $vencida = $this->crear_tarea(['detalle' => 'Vencida', 'fecha_realizacion' => '2026-09-01']);
        $hecha = $this->crear_tarea(['detalle' => 'Hecha', 'fecha_realizacion' => '2026-09-10']);
        $futura = $this->crear_tarea(['detalle' => 'Futura', 'fecha_realizacion' => '2026-09-20']);
        $de_hoy = $this->crear_tarea(['detalle' => 'De hoy', 'fecha_realizacion' => '2026-09-14']);

        // Diaria desde el 1/8: 44 ocurrencias antes de hoy (1/8 al 13/9).
        $diaria = $this->crear_tarea_recurrente('day', 1, '2026-08-01');

        $this->marcar_hecha($hecha, '2026-09-10')->assertStatus(201);
        $this->marcar_hecha($diaria, '2026-09-13')->assertStatus(201);

        $agenda = $this->agenda('2026-09-14', '2026-09-20');

        $ids_vencidas = array_unique(array_map(function ($ocurrencia) {
            return $ocurrencia['pending_id'];
        }, $agenda['vencidas']));

        $this->assertContains($vencida->id, $ids_vencidas);
        $this->assertContains($diaria->id, $ids_vencidas);
        $this->assertNotContains($hecha->id, $ids_vencidas, 'Una puntual hecha no puede figurar como vencida.');
        $this->assertNotContains($futura->id, $ids_vencidas, 'Una puntual futura no puede figurar como vencida.');
        $this->assertNotContains($de_hoy->id, $ids_vencidas, 'Lo de hoy no está vencido.');

        $de_la_vencida = $this->ocurrencias_de($agenda['vencidas'], $vencida);

        $this->assertCount(1, $de_la_vencida);
        $this->assertTrue($de_la_vencida[0]['vencida']);
        $this->assertFalse($de_la_vencida[0]['completado']);

        // 43 no hechas (44 menos la del 13/9): quedan las 30 más recientes, 14/8 al 12/9.
        $de_la_diaria = $this->ocurrencias_de($agenda['vencidas'], $diaria);

        $this->assertCount(30, $de_la_diaria);
        $this->assertEquals('2026-08-14', $de_la_diaria[0]['fecha']);
        $this->assertEquals('2026-09-12', $de_la_diaria[29]['fecha']);
        $this->assertNotContains('2026-09-13', $this->fechas_de($de_la_diaria));

        foreach ($de_la_diaria as $ocurrencia) {
            $this->assertSame(13, $ocurrencia['vencidas_omitidas']);
            $this->assertTrue($ocurrencia['vencida']);
        }

        // Las vencidas vienen ordenadas por fecha y no pisan las ocurrencias del rango.
        $fechas = $this->fechas_de($agenda['vencidas']);
        $ordenadas = $fechas;
        sort($ordenadas);
        $this->assertEquals($ordenadas, $fechas);

        $en_rango = $this->ocurrencias_de($agenda['ocurrencias'], $diaria);
        $this->assertEquals(['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20'], $this->fechas_de($en_rango));
        $this->assertFalse($en_rango[0]['vencida']);

        $this->assertCount(1, $this->ocurrencias_de($agenda['ocurrencias'], $de_hoy));
        $this->assertFalse($this->ocurrencias_de($agenda['ocurrencias'], $de_hoy)[0]['vencida']);
    }

    /**
     * (f) 422 con rango > 120 días, invertido o con fechas que no existen; 200 justo en el límite.
     *
     * @test
     */
    public function el_endpoint_rechaza_rangos_invalidos_con_422()
    {
        $this->getJson('api/pending-agenda/2026-01-01/2026-05-02')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->getJson('api/pending-agenda/2026-01-01/2026-05-01')->assertStatus(200);

        $this->getJson('api/pending-agenda/2026-03-10/2026-03-01')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->getJson('api/pending-agenda/2026-02-30/2026-03-01')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->getJson('api/pending-agenda/hoy/2026-03-01')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        // Un solo día es un rango válido.
        $this->getJson('api/pending-agenda/2026-09-14/2026-09-14')->assertStatus(200);
    }

    /**
     * (g) El endpoint no devuelve tareas de otra cuenta, ni en ocurrencias ni en vencidas.
     *
     * @test
     */
    public function el_endpoint_no_devuelve_tareas_de_otra_cuenta()
    {
        $otro = User::create([
            'name'     => 'Otro comercio agenda',
            'email'    => 'agenda-otro-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        // Setup, no comportamiento bajo prueba: se inserta directo para no cambiar de usuario.
        $ajena_puntual = Pending::create([
            'detalle'           => 'Ajena vencida',
            'fecha_realizacion' => '2026-09-01 00:00:00',
            'es_recurrente'     => 0,
            'completado'        => 0,
            'user_id'           => $otro->id,
        ]);

        $ajena_recurrente = Pending::create([
            'detalle'               => 'Ajena diaria',
            'fecha_realizacion'     => '2026-09-01 00:00:00',
            'es_recurrente'         => 1,
            'unidad_frecuencia_id'  => $this->unidad('day')->id,
            'cantidad_frecuencia'   => 1,
            'completado'            => 0,
            'user_id'               => $otro->id,
        ]);

        $this->tareas_creadas[] = $ajena_puntual->id;
        $this->tareas_creadas[] = $ajena_recurrente->id;

        $propia = $this->crear_tarea(['detalle' => 'Propia', 'fecha_realizacion' => '2026-09-15']);

        $agenda = $this->agenda('2026-09-01', '2026-09-30');

        $ids = array_map(function ($ocurrencia) {
            return $ocurrencia['pending_id'];
        }, array_merge($agenda['ocurrencias'], $agenda['vencidas']));

        $this->assertContains($propia->id, $ids);
        $this->assertNotContains($ajena_puntual->id, $ids);
        $this->assertNotContains($ajena_recurrente->id, $ids);
    }
}
