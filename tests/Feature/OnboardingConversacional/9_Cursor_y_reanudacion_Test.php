<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Http\Controllers\Helpers\asistente_ia\CuestionarioDeInicioIaHelper;

/**
 * Misión onboarding-conversacional-whatsapp — CA14 y CA15 (retomada del 24/11): la pregunta en curso es UNA,
 * queda guardada (cursor persistido por pregunta) y el agente la retoma aunque pasen días; saltear una
 * pregunta avanza el cursor, saltear el formulario de la app no corta la charla de WhatsApp, y «no me preguntes
 * más» pausa y cancela los recordatorios.
 */
class Cursor_y_reanudacion_Test extends OnboardingTestCase
{
    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_cursor_persistido_y_reanudacion_tras_dias()
    {
        // Lunes 10:00: el primer mensaje deja el cursor en la primera pregunta.
        $this->el_dueno_escribe('Hola, ¿andás?');

        $dueno = $this->onboarding();

        $this->assertSame('historia', $dueno->ia_onboarding_cursor);
        $this->assertSame('2026-11-23 10:00:00', substr((string) $dueno->ia_onboarding_cursor_desde, 0, 19));

        $bloque = $this->bloque_de_onboarding();

        $this->assertStringContainsString('Pregunta en curso: historia', $bloque);
        $this->assertStringNotContainsString('Se la hiciste el', $bloque, 'Mismo día: no hay nada que retomar.');

        // 5 días después, sin respuesta: el dueño vuelve con otra cosa. El agente retoma la MISMA pregunta.
        $this->ahora('2026-11-28 10:00:00');
        $this->el_dueno_escribe('perdón, estuve a full');

        $dueno = $this->onboarding();

        $this->assertSame('historia', $dueno->ia_onboarding_cursor, 'Sigue la misma pregunta.');
        $this->assertSame('2026-11-23 10:00:00', substr((string) $dueno->ia_onboarding_cursor_desde, 0, 19), 'Y desde cuándo se le hizo no cambia.');

        $bloque = $this->bloque_de_onboarding();

        $this->assertStringContainsString('Pregunta en curso: historia', $bloque);
        // asistente-diario-recuperacion-20261127 (P1, opción A): el sistema no sabe si la pregunta llegó a hacerse (el
        // cursor_desde es cuándo CAMBIÓ el cursor), así que dice «Pendiente desde» en vez de afirmar «Se la hiciste».
        $this->assertStringContainsString('Pendiente desde el 23/11', $bloque);

        // Contesta la historia: el cursor salta a la siguiente y la fecha es nueva.
        $this->el_agente_guarda(['historia'], 'dueno/perfil.md');

        $dueno = $this->onboarding();

        $this->assertSame('como_lo_describen', $dueno->ia_onboarding_cursor);
        $this->assertSame('2026-11-28 10:00:00', substr((string) $dueno->ia_onboarding_cursor_desde, 0, 19));

        // Omite la pregunta: avanza y no se vuelve a preguntar.
        $this->herramienta('conocimiento_del_negocio', ['accion' => 'omitir', 'temas' => ['como_lo_describen']]);

        $this->assertSame('posicionamiento', $this->onboarding()->ia_onboarding_cursor);

        // Un audio largo contesta varias de una: el cursor salta a la primera que sigue faltando, no a la contigua.
        $this->el_agente_guarda(['posicionamiento', 'que_vende_y_a_quien', 'reglas_del_negocio']);

        $this->assertSame('preguntas_frecuentes', $this->onboarding()->ia_onboarding_cursor);

        // El admin ve el cursor (C1).
        $this->getJson('api/admin-sync/agente-contexto/estado', $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('onboarding.cursor', 'preguntas_frecuentes')
            ->assertJsonStructure(['onboarding' => ['cursor_desde']]);

        // Con los 32, no hay cursor.
        $this->el_agente_guarda(self::TEMAS);

        $dueno = $this->onboarding();

        $this->assertNull($dueno->ia_onboarding_cursor);
        $this->assertSame('completo', $dueno->ia_onboarding_estado);
    }

    /**
     * Saltear el formulario de la app NO corta la charla por WhatsApp; saltear antes del primer mensaje tampoco
     * lo impide; «no me preguntes más» pausa y no se agenda nada en los turnos siguientes.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_saltear()
    {
        // saltear() con el onboarding en curso: sigue en curso, la tarea sigue activa y el bloque sigue.
        $this->el_dueno_escribe('Hola, ¿andás?');

        $tarea = $this->tareas_de_onboarding('activa')[0];

        CuestionarioDeInicioIaHelper::saltear($this->comercio);

        $this->assertNotNull($this->onboarding()->ia_cuestionario_salteado_at);
        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);
        $this->assertSame('activa', $this->estado_de_tarea($tarea->id));

        $this->el_dueno_escribe('Seguimos');

        $this->assertStringContainsString('<onboarding_del_negocio>', $this->system_del_ultimo_pedido());

        // «No me preguntes más»: pausa, tarea cancelada y nada nuevo en los 3 turnos siguientes del dueño.
        $this->herramienta('conocimiento_del_negocio', ['accion' => 'pausar']);

        $this->assertSame('pausado_por_el_dueno', $this->onboarding()->ia_onboarding_estado);
        $this->assertSame('cancelada', $this->estado_de_tarea($tarea->id));

        for ($i = 1; $i <= 3; $i++) {
            $this->el_dueno_escribe('Mensaje ' . $i);
            $this->assertCount(0, $this->tareas_de_onboarding('activa'), 'Turno ' . $i . ' tras pausar: no se agenda ningún recordatorio.');
        }
    }

    /**
     * Con el formulario salteado y sin onboarding todavía, el primer WhatsApp arranca igual (CA1).
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_saltear_antes_del_primer_whatsapp_no_impide_arrancar()
    {
        CuestionarioDeInicioIaHelper::saltear($this->comercio);

        $this->assertNull($this->onboarding()->ia_onboarding_estado);

        $this->el_dueno_escribe('Hola, ¿me escuchás?');

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);
    }
}
