<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AsistenteIa\AsistenteIaService;

/**
 * Misión onboarding-conversacional-whatsapp — CA4: cómo el agente deja constancia de lo que el dueño le contó.
 * `guardar_en_contexto` suma el campo opcional `temas`, y la herramienta nueva `conocimiento_del_negocio`
 * (omitir, empezar, pausar, retomar, estado) se ofrece sólo al dueño, después de las de tareas, y nunca en un
 * turno desatendido.
 */
class Herramientas_Test extends OnboardingTestCase
{
    /** Una conversación del dueño (o de otra persona), con un primer mensaje opcionalmente de tarea programada. */
    protected function conversacion_de($persona = null, $desatendida = false)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $conversacion = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $persona->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => now(),
        ]);

        $this->mensaje($conversacion, 'user', 'listo', $desatendida
            ? ['tipo' => 'tarea_programada', 'contenido' => '[Tarea programada] Algo: hacé algo']
            : ['contenido' => 'Hola']);

        return $conversacion;
    }

    /** Ejecuta una herramienta en una conversación dada; devuelve [is_error, datos]. */
    protected function ejecutar_en($conversacion, $nombre, array $input)
    {
        $assistant = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type' => 'tool_use', 'id' => 'toolu_' . uniqid(), 'name' => $nombre, 'input' => $input,
        ]], $conversacion, $assistant);

        return [!empty($resultados[0]['is_error']), (string) $resultados[0]['content']];
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_guardar_con_temas_marca()
    {
        $resultado = $this->el_agente_guarda(['historia', 'que_decide_solo'], 'dueno/perfil.md');

        $this->assertFalse($resultado['error']);
        $this->assertTrue($resultado['datos']['ok']);
        $this->assertStringContainsString('Dato de prueba', \App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper::leer($this->comercio, 'dueno/perfil.md')['contenido']);

        $marcados = $this->temas_marcados();

        $this->assertSame(['historia', 'que_decide_solo'], array_keys($marcados));

        foreach ($marcados as $fila) {
            $this->assertSame('sabido', $fila->estado);
            $this->assertSame('agente', $fila->fuente);
            $this->assertNotNull($fila->ai_conversation_id, 'Queda la conversación donde lo contó.');
        }
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_tema_desconocido_no_rompe()
    {
        $resultado = $this->el_agente_guarda(['inventado']);

        $this->assertTrue($resultado['datos']['ok'], 'La escritura se hace igual.');
        $this->assertSame(['inventado'], $resultado['datos']['temas_desconocidos']);
        $this->assertCount(32, $resultado['datos']['claves_validas'], 'Le dice cuáles son las claves válidas.');
        $this->assertCount(0, $this->temas_marcados());
    }

    /**
     * Sin `temas`, la respuesta es la de hoy: mismas claves, ni una más (el MCP usa la misma herramienta).
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_sin_temas_igual_que_hoy()
    {
        $resultado = $this->herramienta('guardar_en_contexto', [
            'ruta' => 'negocio/identidad.md', 'operacion' => 'agregar', 'contenido' => '- Algo.', 'motivo' => 'prueba',
        ]);

        $claves = array_keys($resultado['datos']);
        sort($claves);

        $this->assertSame(['creado', 'nota', 'ok', 'ruta', 'sin_cambios', 'version'], $claves);
        $this->assertCount(0, $this->temas_marcados());
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_omitir()
    {
        $resultado = $this->herramienta('conocimiento_del_negocio', ['accion' => 'omitir', 'temas' => ['competencia']]);

        $this->assertFalse($resultado['error']);

        $marcados = $this->temas_marcados();

        $this->assertSame('omitido', $marcados['competencia']->estado);
        $this->assertSame('agente', $marcados['competencia']->fuente);

        $estado = $this->herramienta('conocimiento_del_negocio', ['accion' => 'estado']);

        $this->assertFalse($estado['error']);
        $this->assertStringContainsString('competencia', $estado['texto']);
    }

    /**
     * En un turno desatendido ninguna de las dos marca nada (la guarda de desatendido las rechaza).
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_desatendido_rechaza()
    {
        $conversacion = $this->conversacion_de(null, true);

        list($error_omitir) = $this->ejecutar_en($conversacion, 'conocimiento_del_negocio', ['accion' => 'omitir', 'temas' => ['competencia']]);

        list($error_guardar) = $this->ejecutar_en($conversacion, 'guardar_en_contexto', [
            'ruta' => 'negocio/identidad.md', 'operacion' => 'agregar', 'contenido' => '- Algo.', 'motivo' => 'prueba', 'temas' => ['historia'],
        ]);

        $this->assertTrue($error_omitir);
        $this->assertTrue($error_guardar);
        $this->assertCount(0, $this->temas_marcados());
    }

    /**
     * Se ofrece al dueño (WhatsApp y app), no a un empleado ni en desatendido; queda después de las de tareas.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_quien_la_ve_y_orden()
    {
        $servicio = new AsistenteIaService();

        $nombres = function ($conversacion, $es_whatsapp, $es_app) use ($servicio) {
            return array_map(function ($definicion) {
                return $definicion['name'];
            }, $servicio->build_tools(true, $es_whatsapp, $this->comercio, $es_app, $conversacion));
        };

        $del_dueno = $nombres($this->conversacion_de(), true, false);

        $this->assertContains('conocimiento_del_negocio', $del_dueno, 'Por WhatsApp el dueño la ve.');
        $this->assertGreaterThan(
            array_search('cancelar_tarea_del_asistente', $del_dueno),
            array_search('conocimiento_del_negocio', $del_dueno),
            'Va después de las herramientas de tareas.'
        );
        $this->assertGreaterThan(
            array_search('guardar_horarios_del_dueno', $del_dueno),
            array_search('conocimiento_del_negocio', $del_dueno)
        );

        $this->assertContains('conocimiento_del_negocio', $nombres($this->conversacion_de(), false, true), 'Por la app también.');

        $this->assertNotContains('conocimiento_del_negocio', $nombres($this->conversacion_de($this->empleado), true, false), 'Un empleado no.');
        $this->assertNotContains('conocimiento_del_negocio', $nombres($this->conversacion_de(null, true), true, false), 'En un turno desatendido no.');
    }
}
