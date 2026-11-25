<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Http\Controllers\Helpers\asistente_ia\TareasDelAsistenteIaHelper;

/**
 * Misión onboarding-conversacional-whatsapp — CA6: la persistencia. El seguimiento es UNA tarea del motor de
 * tareas del asistente (área `onboarding`) con una escalera de recordatorios de 1, 3 y 7 días, tope de 3 sin
 * respuesta, que respeta lo que el dueño reprogramó o canceló y que nunca rompe el turno.
 */
class Seguimientos_Test extends OnboardingTestCase
{
    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_escalera_1_3_7_y_pausa()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        // Lunes 10:00 → exactamente una tarea activa, una vez, para el martes a las 17:00.
        $activas = $this->tareas_de_onboarding('activa');

        $this->assertCount(1, $activas);
        $this->assertSame('una_vez', $activas[0]->recurrencia);
        $this->assertSame('2026-11-24 17:00:00', $activas[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'));
        $this->assertSame('unas preguntas más para conocer tu negocio', $activas[0]->titulo, 'El título es lo que dice la plantilla con la ventana cerrada.');

        // El dueño no contesta. El martes a las 17:00 se despierta la tarea (queda terminada, hay una ejecución)
        // y el turno desatendido vuelve a agendar: +3 días a las 17:00.
        $this->despierta_y_corre_el_turno();

        $segunda = $this->tareas_de_onboarding('activa');

        $this->assertCount(1, $segunda, 'Nunca dos tareas de onboarding activas.');
        $this->assertSame('2026-11-27 17:00:00', $segunda[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'), '1 ejecución sin respuesta → +3 días.');

        // Sigue sin contestar: otra ejecución → +7 días.
        $this->despierta_y_corre_el_turno();

        $tercera = $this->tareas_de_onboarding('activa');

        $this->assertCount(1, $tercera);
        $this->assertSame('2026-12-04 17:00:00', $tercera[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'), '2 ejecuciones sin respuesta → +7 días.');

        // La tercera despierta y todavía no contesta: tope. Pausa y ningún recordatorio más.
        $this->despierta_y_corre_el_turno();

        $this->assertCount(0, $this->tareas_de_onboarding('activa'), 'Con 3 sin respuesta no se agenda un cuarto.');
        $this->assertSame('pausado_sin_respuesta', $this->onboarding()->ia_onboarding_estado);

        // El dueño vuelve a escribir: vuelve a en_curso y la escalera empieza de nuevo (+1 día).
        $this->ahora('2026-12-10 11:00:00');
        $this->el_dueno_escribe('Perdón, estuve a full');

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);

        $nueva = $this->tareas_de_onboarding('activa');

        $this->assertCount(1, $nueva);
        $this->assertSame('2026-12-11 17:00:00', $nueva[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'));
    }

    /**
     * «Hoy no, preguntame el viernes»: la reprogramación del dueño no se pisa en el turno siguiente.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_respeta_la_reprogramacion()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $tarea = $this->tareas_de_onboarding('activa')[0];

        $reprogramada = $this->herramienta('reprogramar_tarea_del_asistente', ['tarea_id' => $tarea->id, 'fecha' => '2026-11-27', 'franja' => 'tarde']);

        $this->assertFalse($reprogramada['error'], $reprogramada['texto']);

        $this->el_dueno_escribe('Che, una consulta de otra cosa');

        $activas = $this->tareas_de_onboarding('activa');

        $this->assertCount(1, $activas, 'No se crea otra tarea.');
        $this->assertSame($tarea->id, $activas[0]->id);
        $this->assertSame('2026-11-27 17:00:00', $activas[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'), 'El viernes que pidió el dueño se respeta.');
    }

    /**
     * Si la última tarea la canceló el dueño (a través del modelo), no se vuelve a crear: es una pausa suya.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_cancelada_es_pausa_del_dueno()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $tarea = $this->tareas_de_onboarding('activa')[0];

        $this->herramienta('cancelar_tarea_del_asistente', ['tarea_id' => $tarea->id]);

        $this->el_dueno_escribe('Gracias, seguimos');

        $this->assertSame('pausado_por_el_dueno', $this->onboarding()->ia_onboarding_estado);
        $this->assertCount(0, $this->tareas_de_onboarding('activa'));
        $this->assertCount(1, $this->tareas_de_onboarding(), 'No se crea ninguna tarea nueva.');
    }

    /**
     * Convivencia con el estado real del asistente (test 39 de AsistenteWhatsapp): el dueño con el onboarding en
     * curso (su propia tarea de seguimiento, área `onboarding`) crea una tarea suya y la cancela. En el turno
     * siguiente el modelo tiene que verla «cancelada hace», sin aviso activo propio, y el onboarding sigue vivo:
     * cancelar la tarea del dueño no es pausar el onboarding.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_cancelar_una_tarea_propia_convive_con_el_onboarding_en_curso()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);
        $this->assertCount(1, $this->tareas_de_onboarding('activa'), 'Precondición: el onboarding tiene su seguimiento activo.');

        $propia = $this->tarea_del_asistente(['titulo' => 'Aviso de reposición']);

        $cancelacion = $this->herramienta('cancelar_tarea_del_asistente', ['tarea_id' => $propia->id]);

        $this->assertFalse($cancelacion['error'], $cancelacion['texto']);

        $this->el_dueno_escribe('Gracias, ¿y ahora?');

        $system = $this->system_del_ultimo_pedido();

        $linea_propia = '';

        foreach (explode("\n", $system) as $linea) {
            if (strpos($linea, '[id ' . $propia->id . ']') !== false) {
                $linea_propia = $linea;
            }
        }

        $this->assertNotSame('', $linea_propia, 'La tarea cancelada tiene que figurar en el estado que ve el modelo.');
        $this->assertStringContainsString('«Aviso de reposición»', $linea_propia);
        $this->assertStringContainsString('cancelada hace', $linea_propia);
        $this->assertStringNotContainsString('(activa)', $linea_propia, 'La tarea propia cancelada no puede figurar como viva.');

        $this->assertSame('cancelada', $this->estado_de_tarea($propia->id));
        $this->assertCount(1, $this->tareas_de_onboarding('activa'), 'El seguimiento del onboarding sigue activo.');
        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado, 'Cancelar una tarea propia no pausa el onboarding.');
    }

    /**
     * Con el tope de tareas activas lleno no hay seguimiento, pero el turno contesta igual y no hay excepción.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_tope_de_10_tareas()
    {
        for ($i = 1; $i <= TareasDelAsistenteIaHelper::MAX_ACTIVAS; $i++) {
            $this->tarea_del_asistente(['titulo' => 'Tarea ' . $i, 'fecha_inicio' => '2026-12-' . str_pad($i, 2, '0', STR_PAD_LEFT), 'proxima_ejecucion_at' => '2026-12-' . str_pad($i, 2, '0', STR_PAD_LEFT) . ' 17:00:00']);
        }

        $assistant = $this->el_dueno_escribe('Hola, ¿andás?');

        $this->assertSame('listo', $assistant->estado, 'El turno contesta aunque no haya lugar para el seguimiento.');
        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);
        $this->assertCount(0, $this->tareas_de_onboarding(), 'Sin lugar, sin tarea de onboarding.');
    }
}
