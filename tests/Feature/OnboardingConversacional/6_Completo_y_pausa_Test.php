<?php

namespace Tests\Feature\OnboardingConversacional;

/**
 * Misión onboarding-conversacional-whatsapp — CA7 y CA8: cuándo el onboarding está completo (y deja de
 * insistir) y cómo se pausa, se retoma y se pide.
 */
class Completo_y_pausa_Test extends OnboardingTestCase
{
    /**
     * El 13.º esencial avisa «lo esencial».
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_esenciales()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $faltante = 'meta_del_anio';

        $primeros = array_values(array_diff(self::ESENCIALES, [$faltante]));

        $parcial = $this->el_agente_guarda($primeros);

        $this->assertStringNotContainsString('lo esencial', $parcial['texto'], 'Con 12 de 13 todavía no cruzó los esenciales.');
        $this->assertNull($this->onboarding()->ia_onboarding_esenciales_at);

        $ultimo = $this->el_agente_guarda([$faltante]);

        $this->assertStringContainsString('lo esencial', $ultimo['texto']);
        $this->assertNotNull($this->onboarding()->ia_onboarding_esenciales_at);
        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado, 'Sigue preguntando hasta los 32.');
    }

    /**
     * Los 32 → completo: se cancela la tarea, se le pide al modelo un resumen y el bloque ya no insiste.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_completo_cancela_y_pide_resumen()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $tarea = $this->tareas_de_onboarding('activa')[0];

        $this->el_agente_guarda(array_slice(self::TEMAS, 0, 31));

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);

        $ultimo = $this->herramienta('conocimiento_del_negocio', ['accion' => 'omitir', 'temas' => [self::TEMAS[31]]]);

        $this->assertStringContainsString('resumen', $ultimo['texto']);

        $dueno = $this->onboarding();

        $this->assertSame('completo', $dueno->ia_onboarding_estado);
        $this->assertNotNull($dueno->ia_onboarding_completado_at);
        $this->assertNull($dueno->ia_onboarding_cursor);
        $this->assertSame('cancelada', $this->estado_de_tarea($tarea->id), 'La tarea de seguimiento queda cancelada: no hay más recordatorios.');
        $this->assertCount(0, $this->tareas_de_onboarding('activa'));

        $this->el_dueno_escribe('Gracias por todo');

        $bloque = $this->bloque_de_onboarding();

        $this->assertTrue($bloque === '' || mb_strlen($bloque) < 600, 'Completo: el bloque ya no insiste (a lo sumo una línea con temas viejos).');
        $this->assertStringNotContainsString('Faltan', $bloque);
        $this->assertCount(0, $this->tareas_de_onboarding('activa'), 'Y no se vuelve a agendar nada.');
    }

    /**
     * pausar, retomar y empezar.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_pausar_retomar_empezar()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        // pausar: «no me preguntes más».
        $this->herramienta('conocimiento_del_negocio', ['accion' => 'pausar']);

        $this->assertSame('pausado_por_el_dueno', $this->onboarding()->ia_onboarding_estado);
        $this->assertCount(0, $this->tareas_de_onboarding('activa'));

        $this->el_dueno_escribe('Che, ¿cuánto vendí hoy?');

        $bloque = $this->bloque_de_onboarding();

        $this->assertStringContainsString('<onboarding_del_negocio>', $bloque);
        $this->assertLessThan(600, mb_strlen($bloque), 'Pausado por el dueño: una línea.');
        $this->assertStringNotContainsString('Faltan', $bloque);
        $this->assertSame('pausado_por_el_dueno', $this->onboarding()->ia_onboarding_estado, 'Escribirle no lo retoma: sólo se retoma si lo pide.');
        $this->assertCount(0, $this->tareas_de_onboarding('activa'));

        // retomar.
        $this->herramienta('conocimiento_del_negocio', ['accion' => 'retomar']);

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);
        $this->assertCount(1, $this->tareas_de_onboarding('activa'));

        // empezar con estado null (dueño con historial) → origen pedido.
        $this->onboarding_en(null);

        $this->herramienta('conocimiento_del_negocio', ['accion' => 'empezar']);

        $dueno = $this->onboarding();

        $this->assertSame('en_curso', $dueno->ia_onboarding_estado);
        $this->assertSame('pedido', $dueno->ia_onboarding_origen);

        // empezar con completo → no cambia y lo dice.
        $this->onboarding_en('completo');

        $resultado = $this->herramienta('conocimiento_del_negocio', ['accion' => 'empezar']);

        $this->assertSame('completo', $this->onboarding()->ia_onboarding_estado);
        $this->assertStringContainsString('completo', mb_strtolower($resultado['texto']));
    }
}

