<?php

namespace Tests\Feature\OnboardingConversacional;

use Illuminate\Support\Facades\DB;

/**
 * Misión onboarding-conversacional-whatsapp — CA1 y CA2: el primer mensaje del dueño por WhatsApp arranca el
 * onboarding (sin que nadie lo pida), y el bloque <onboarding_del_negocio> viaja en los turnos siguientes sin
 * cambiar el censo de bloques del system.
 */
class Disparo_por_whatsapp_Test extends OnboardingTestCase
{
    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_el_primer_mensaje_por_whatsapp_arranca_el_onboarding()
    {
        $this->assertNull($this->onboarding()->ia_onboarding_estado, 'Antes del primer mensaje no hay onboarding.');

        $this->el_dueno_escribe('Hola, ¿andás?');

        $dueno = $this->onboarding();

        $this->assertSame('en_curso', $dueno->ia_onboarding_estado);
        $this->assertSame('whatsapp', $dueno->ia_onboarding_origen);
        $this->assertNotNull($dueno->ia_onboarding_iniciado_at);

        $bloque = $this->bloque_de_onboarding();

        $this->assertNotSame('', $bloque, 'El system del primer turno tiene que llevar <onboarding_del_negocio>.');
        $this->assertStringContainsString('PRIMER mensaje', $bloque);
        $this->assertStringContainsString('audios', $bloque);
        $this->assertStringContainsString('historia', $bloque, 'La primera pregunta que faltan es la historia del negocio.');
    }

    /**
     * Lo que NO arranca: historial previo por WhatsApp, canal sistema, empleado, turno desatendido y sin la extensión.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_no_arranca_con_historial_sistema_empleado_desatendido_o_sin_extension()
    {
        // 1. El dueño ya le había escrito por WhatsApp.
        $this->con_historial_de_whatsapp();
        $this->el_dueno_escribe('Hola otra vez');
        $this->assertNull($this->onboarding()->ia_onboarding_estado, 'Con historial por WhatsApp no arranca solo.');
        $this->assertSame('', $this->bloque_de_onboarding());

        $this->borrar_conversaciones_del_dueno();

        // 2. Canal sistema (el panel del ERP).
        $this->turno_manual('sistema', 'Hola desde el panel');
        $this->assertNull($this->onboarding()->ia_onboarding_estado, 'El panel del sistema no arranca el onboarding.');
        $this->assertSame('', $this->bloque_de_onboarding());

        // 3. Un empleado por WhatsApp.
        $this->turno_manual('whatsapp', 'Hola, soy el empleado', $this->empleado);
        $this->assertNull($this->onboarding()->ia_onboarding_estado, 'Un empleado no arranca el onboarding del dueño.');
        $this->assertSame('', $this->bloque_de_onboarding());

        // 4. Un turno desatendido (lo despertó una tarea, no lo escribió el dueño).
        $this->turno_manual('whatsapp', '[Tarea programada] Algo: hacé algo', null, 'tarea_programada');
        $this->assertNull($this->onboarding()->ia_onboarding_estado, 'Un turno desatendido no arranca el onboarding.');
        $this->assertSame('', $this->bloque_de_onboarding());

        // 5. Sin la extensión Agentes de IA.
        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');
        $this->turno_manual('whatsapp', 'Hola sin extensión');
        $this->assertNull($this->onboarding()->ia_onboarding_estado, 'Sin Agentes de IA no hay onboarding.');
        $this->assertSame('', $this->bloque_de_onboarding());
    }

    /**
     * Deja al dueño sin conversaciones (sólo las suyas) para armar la escena siguiente.
     *
     * @return void
     */
    protected function borrar_conversaciones_del_dueno()
    {
        $ids = DB::table('ai_conversations')->where('user_id', $this->comercio->id)->pluck('id')->all();

        DB::table('ai_messages')->whereIn('ai_conversation_id', $ids)->delete();
        DB::table('ai_conversations')->whereIn('id', $ids)->delete();
    }

    /**
     * El segundo mensaje por WhatsApp y uno por la app llevan el bloque, sin la línea del primer turno; el del
     * panel no. Lista de hasta 5 faltantes con los esenciales primero, contadores y tarea de seguimiento.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_los_turnos_siguientes_llevan_el_bloque_sin_el_primer_turno()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $tarea = $this->tareas_de_onboarding('activa')[0];

        $this->el_dueno_escribe('Ahora sí, ¿me contás cómo seguimos?');

        $bloque = $this->bloque_de_onboarding();

        $this->assertNotSame('', $bloque);
        $this->assertStringNotContainsString('PRIMER mensaje', $bloque, 'La línea del primer turno es sólo del primer turno.');
        $this->assertStringContainsString('0 de 32', $bloque);
        $this->assertStringContainsString('0 de 13', $bloque);
        $this->assertStringContainsString('#' . $tarea->id, $bloque, 'El bloque nombra la tarea de seguimiento para que el modelo la reprograme.');
        $this->assertLessThanOrEqual(2500, mb_strlen($bloque));

        preg_match_all('/^- ([a-z0-9_]+) → /m', $bloque, $faltantes);

        $this->assertSame(
            ['historia', 'como_lo_describen', 'posicionamiento', 'que_vende_y_a_quien', 'reglas_del_negocio'],
            $faltantes[1],
            'Hasta 5 faltantes, los esenciales primero y en el orden del catálogo.'
        );

        // Por la app también.
        $this->turno_manual('app', 'Buenas');
        $this->assertStringContainsString('<onboarding_del_negocio>', $this->system_del_ultimo_pedido());
        $this->assertStringNotContainsString('PRIMER mensaje', $this->system_del_ultimo_pedido());

        // Por el panel del sistema, no.
        $this->turno_manual('sistema', 'Buenas desde el panel');
        $this->assertStringNotContainsString('<onboarding_del_negocio>', $this->system_del_ultimo_pedido());
    }

    /**
     * El bloque se pega al último bloque no cacheado: el número de bloques del system es el de siempre (lo fijan
     * MemoriaDelAgente/2 y ChatIa).
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_el_censo_de_bloques_no_cambia()
    {
        $this->con_historial_de_whatsapp();

        $this->el_dueno_escribe('Primer turno sin onboarding');
        $sin = $this->bloques_del_ultimo_pedido();
        $this->assertSame('', $this->bloque_de_onboarding());

        $this->onboarding_en('en_curso');

        $this->el_dueno_escribe('Segundo turno con onboarding');
        $con = $this->bloques_del_ultimo_pedido();

        $this->assertNotSame('', $this->bloque_de_onboarding(), 'El escenario tiene que llevar el bloque.');
        $this->assertSame($sin, $con, 'El bloque de onboarding no abre un bloque nuevo del system.');
    }
}
