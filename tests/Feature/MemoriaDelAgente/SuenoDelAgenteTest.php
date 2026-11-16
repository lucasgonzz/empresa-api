<?php

namespace Tests\Feature\MemoriaDelAgente;

use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AsistenteIa\ConsolidacionDeMemoriaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SuenoDelAgenteTest extends MemoriaTestCase
{
    protected $respuesta_actual;
    protected $fake_instalado = false;
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 03:00:00', ConsolidacionDeMemoriaService::ZONA));
        config(['app.USER_ID' => $this->dueno->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function mensaje($texto, $fecha = '2026-11-12 15:00:00', $persona = null, $dueno = null, $rol = 'user')
    {
        $dueno = $dueno ?: $this->dueno;
        $hilo = AiConversation::create(['user_id' => $dueno->id, 'auth_user_id' => $persona ? $persona->id : $dueno->id]);
        return AiMessage::create(['ai_conversation_id' => $hilo->id, 'rol' => $rol, 'contenido' => $texto,
            'estado' => 'listo', 'canal' => 'app', 'created_at' => $fecha, 'updated_at' => $fecha]);
    }

    protected function respuesta(array $memorias = [], array $mejoras = [], $stop = 'end_turn')
    {
        $this->respuesta_actual = ['model' => 'deepseek-fake', 'stop_reason' => $stop,
            'content' => [['type' => 'text', 'text' => json_encode(['memorias' => $memorias, 'mejoras' => $mejoras, 'completo' => true])]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
        // Http::fake acumula callbacks: instalar uno y cambiar el cuerpo evita que un reintento
        // del servicio reciba para siempre la primera respuesta del test.
        if (!$this->fake_instalado) {
            Http::fake(['*' => function () { return Http::response($this->respuesta_actual, 200); }]);
            $this->fake_instalado = true;
        }
    }

    protected function regla($m, $instruccion = 'Al presupuestar cajas de botellas, usá unidades por botella.')
    {
        return ['tema' => 'presupuesto_botellas', 'instruccion' => $instruccion, 'mensaje_id' => $m->id,
            'evidencia' => $m->contenido, 'explicita' => true];
    }

    protected function repasar($fecha = null)
    {
        return app(ConsolidacionDeMemoriaService::class)->repasar($this->dueno->fresh(), $fecha);
    }

    public function test_aprende_regla_con_evidencia_y_la_inyecta_en_otro_prompt_sin_repetirla()
    {
        $m = $this->mensaje('Cuando presupuestes, siempre usá unidades por botella, no por caja.');
        $this->respuesta([$this->regla($m)]);
        $this->assertSame(0, $this->repasar()['errores']);
        $this->assertDatabaseHas('agente_consolidacion_memorias', ['user_id' => $this->dueno->id, 'mensaje_id' => $m->id]);
        $prompt = AgenteContextoHelper::bloque_para_prompt($this->dueno);
        $this->assertStringContainsString('usá unidades por botella', $prompt);
        $version = AgenteContextoHelper::leer($this->dueno, AgenteContextoHelper::CORRECCIONES)['version'];
        $this->repasar();
        Http::assertSentCount(1);
        $this->assertSame($version, AgenteContextoHelper::leer($this->dueno, AgenteContextoHelper::CORRECCIONES)['version']);
        $this->assertDatabaseHas('ai_token_usages', ['user_id' => $this->dueno->id, 'proceso' => 'agente_sueno']);
    }

    public function test_mejora_de_producto_no_se_convierte_en_memoria_del_negocio()
    {
        $m = $this->mensaje('Te pedí exportar esto y no pudiste.');
        $this->respuesta([], [['descripcion' => 'Revisar exportación solicitada.', 'mensaje_id' => $m->id, 'evidencia' => $m->contenido]]);
        $this->repasar();
        $this->assertDatabaseHas('agente_consolidacion_hallazgos', ['user_id' => $this->dueno->id, 'mensaje_id' => $m->id, 'estado' => 'pendiente']);
        $this->assertSame(0, DB::table('agente_consolidacion_memorias')->where('user_id', $this->dueno->id)->count());
    }

    public function test_no_acepta_evidencia_del_assistant_ni_citas_inventadas()
    {
        $m = $this->mensaje('Recordaré vender por unidad.', '2026-11-12 15:00:00', null, null, 'assistant');
        $this->respuesta([$this->regla($m)]);
        $this->assertSame(1, $this->repasar()['errores']);
        $this->assertDatabaseHas('agente_consolidaciones', ['ai_conversation_id' => $m->ai_conversation_id, 'ultimo_mensaje_id' => 0, 'estado' => 'error']);
        $this->respuesta();
        $this->assertSame(0, $this->repasar()['errores']);
    }

    public function test_no_aprende_de_otro_negocio_ni_de_empleado_sin_acceso_admin()
    {
        $this->mensaje('Siempre por botella.', '2026-11-12 15:00:00', $this->empleado());
        $otro = $this->nuevo_dueno('Otro negocio');
        $this->mensaje('Siempre por unidad.', '2026-11-12 15:00:00', null, $otro);
        $this->respuesta();
        $this->repasar();
        Http::assertNothingSent();
    }

    public function test_dia_argentino_tiene_bordes_exclusivos_y_no_toma_hoy()
    {
        $incluido = $this->mensaje('Siempre por botella.', '2026-11-12 00:00:00');
        $excluido = $this->mensaje('Hoy no.', '2026-11-13 00:00:00');
        $this->respuesta();
        $this->repasar();
        $this->assertDatabaseHas('agente_consolidaciones', ['ai_conversation_id' => $incluido->ai_conversation_id, 'estado' => 'listo']);
        $this->assertDatabaseMissing('agente_consolidaciones', ['ai_conversation_id' => $excluido->ai_conversation_id]);
    }

    public function test_respuesta_truncada_no_avanza_y_se_recupera_dias_despues()
    {
        $m = $this->mensaje('Presupuestá siempre por unidad.');
        $this->respuesta([], [], 'max_tokens');
        $this->assertSame(1, $this->repasar()['errores']);
        Carbon::setTestNow(Carbon::parse('2026-11-16 03:00:00', ConsolidacionDeMemoriaService::ZONA));
        $this->respuesta([$this->regla($m)]);
        $this->assertSame(0, $this->repasar()['errores']);
        $this->assertDatabaseHas('agente_consolidaciones', ['ai_conversation_id' => $m->ai_conversation_id, 'estado' => 'listo']);
    }

    public function test_mensaje_largo_avanza_por_fragmentos_sin_perder_el_final()
    {
        $m = $this->mensaje(str_repeat('a', 20000) . ' Al final presupuestá siempre por botella.');
        $this->respuesta();
        $resultado = $this->repasar();
        $this->assertSame(2, $resultado['lotes']);
        $this->assertDatabaseHas('agente_consolidaciones', ['ai_conversation_id' => $m->ai_conversation_id, 'ultimo_mensaje_id' => $m->id, 'desplazamiento' => 0, 'estado' => 'listo']);
        Http::assertSent(function ($request) {
            return strpos(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'Al final presupuestá siempre por botella.') !== false;
        });
    }

    public function test_borrado_humano_no_resucita_y_aprende_otra_regla_posterior()
    {
        $m = $this->mensaje('Siempre por botella.');
        $this->respuesta([$this->regla($m)]);
        $this->repasar();
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00:00', ConsolidacionDeMemoriaService::ZONA));
        AgenteContextoHelper::guardar($this->dueno, AgenteContextoHelper::CORRECCIONES, 'reemplazar', 'Lo edité: prefiero preguntar.', null, null, 'dueno');
        $nuevo = $this->mensaje('Ahora siempre preguntame por caja o botella.', '2026-11-13 11:00:00');
        Carbon::setTestNow(Carbon::parse('2026-11-14 03:00:00', ConsolidacionDeMemoriaService::ZONA));
        $this->respuesta([$this->regla($nuevo, 'Siempre preguntá la unidad.')]);
        $this->assertSame(0, $this->repasar()['errores']);
        $contenido = AgenteContextoHelper::leer($this->dueno, AgenteContextoHelper::CORRECCIONES)['contenido'];
        $this->assertStringContainsString('Lo edité: prefiero preguntar.', $contenido);
        $this->assertStringContainsString('Siempre preguntá la unidad.', $contenido);
        $this->assertStringNotContainsString('usá unidades por botella', $contenido);
    }

    public function test_edicion_del_detalle_no_revive_regla_con_otro_slug()
    {
        $m = $this->mensaje('Siempre por botella.');
        $this->respuesta([$this->regla($m)]);
        $this->repasar();
        $viejo = $this->mensaje('Siempre por botella, recordalo.', '2026-11-12 16:00:00');
        AgenteContextoHelper::guardar($this->dueno, ConsolidacionDeMemoriaService::DETALLE, 'reemplazar', 'Lo cambié: preguntarme.', null, null, 'dueno');
        $regla = $this->regla($viejo);
        $regla['tema'] = 'botellas_otro_slug';
        $this->respuesta([$regla], [['mensaje_id' => $viejo->id, 'evidencia' => $viejo->contenido, 'descripcion' => 'Revisar el pedido del usuario.']]);
        $this->assertSame(0, $this->repasar()['errores']);
        $this->assertDatabaseMissing('agente_consolidacion_memorias', ['user_id' => $this->dueno->id, 'tema' => 'botellas_otro_slug']);
        $this->assertDatabaseHas('agente_consolidacion_hallazgos', ['mensaje_id' => $viejo->id]);
        $this->assertStringContainsString('Lo cambié: preguntarme.', AgenteContextoHelper::leer($this->dueno, ConsolidacionDeMemoriaService::DETALLE)['contenido']);
        $this->assertStringNotContainsString('usá unidades por botella', AgenteContextoHelper::leer($this->dueno, AgenteContextoHelper::CORRECCIONES)['contenido']);
    }

    public function test_dos_aprendizajes_sucesivos_no_parecen_edicion_humana()
    {
        $m = $this->mensaje('Siempre por botella.');
        $this->respuesta([$this->regla($m)]);
        $this->repasar();
        $m2 = $this->mensaje('En compras siempre revisamos con el encargado.');
        $r = $this->regla($m2, 'Al comprar, revisar con el encargado.');
        $r['tema'] = 'compras_encargado';
        $this->respuesta([$r]);
        $this->assertSame(0, $this->repasar()['errores']);
        $detalle = AgenteContextoHelper::leer($this->dueno, ConsolidacionDeMemoriaService::DETALLE)['contenido'];
        $this->assertStringContainsString('usá unidades por botella', $detalle);
        $this->assertStringContainsString('revisar con el encargado', $detalle);
    }

    public function test_recupera_dias_en_que_el_cron_no_arranco()
    {
        $this->respuesta();
        $this->repasar();
        $m1 = $this->mensaje('Siempre revisar compras.', '2026-11-13 15:00:00');
        $m2 = $this->mensaje('Siempre revisar ventas.', '2026-11-14 15:00:00');
        Carbon::setTestNow(Carbon::parse('2026-11-16 03:00:00', ConsolidacionDeMemoriaService::ZONA));
        $this->repasar();
        $this->assertDatabaseHas('agente_consolidaciones', ['ai_conversation_id' => $m1->ai_conversation_id, 'fecha' => '2026-11-13', 'estado' => 'listo']);
        $this->assertDatabaseHas('agente_consolidaciones', ['ai_conversation_id' => $m2->ai_conversation_id, 'fecha' => '2026-11-14', 'estado' => 'listo']);
    }

    public function test_conserva_mas_de_ocho_ensenanzas_del_mismo_mensaje()
    {
        $textos = [];
        for ($i = 1; $i <= 12; $i++) { $textos[] = 'Siempre aplicar procedimiento ' . $i . '.'; }
        $m = $this->mensaje(implode(' ', $textos));
        $reglas = [];
        foreach ($textos as $i => $texto) {
            $reglas[] = ['tema' => 'procedimiento_' . $i, 'instruccion' => $texto, 'mensaje_id' => $m->id, 'evidencia' => $texto, 'explicita' => true];
        }
        $this->respuesta($reglas);
        $this->assertSame(0, $this->repasar()['errores']);
        $this->assertSame(12, DB::table('agente_consolidacion_memorias')->where('user_id', $this->dueno->id)->count());
        $detalle = AgenteContextoHelper::leer($this->dueno, ConsolidacionDeMemoriaService::DETALLE)['contenido'];
        $this->assertStringContainsString($textos[0], $detalle);
        $this->assertStringContainsString($textos[11], $detalle);
    }

    public function test_correccion_en_fragmento_final_del_mismo_mensaje_prevalece()
    {
        $m = $this->mensaje('Regla inicial: siempre por caja.' . str_repeat('a', 18000) . ' Regla final: siempre por botella.');
        Http::fake(['*' => function ($request) use ($m) {
            $entrada = json_decode($request->data()['messages'][0]['content'], true);
            $fragmento = $entrada['mensajes'][0]['texto'];
            $final = strpos($fragmento, 'Regla final:') !== false;
            $memorias = [['tema' => 'unidad_presupuesto', 'instruccion' => $final ? 'Siempre por botella.' : 'Siempre por caja.',
                'mensaje_id' => $m->id, 'evidencia' => $final ? 'Regla final: siempre por botella.' : 'Regla inicial: siempre por caja.', 'explicita' => true]];
            return Http::response(['model' => 'fake', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['memorias' => $memorias, 'mejoras' => [], 'completo' => true])]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200);
        }]);
        $this->assertSame(0, $this->repasar()['errores']);
        $this->assertDatabaseHas('agente_consolidacion_memorias', ['user_id' => $this->dueno->id, 'instruccion' => 'Siempre por botella.', 'mensaje_id' => $m->id, 'mensaje_desplazamiento' => 18000]);
    }

    public function test_salida_incompleta_reduce_lote_sin_confirmar_ensenanzas()
    {
        $m = $this->mensaje('Presupuestá siempre por unidad.');
        $primera = ['model' => 'fake', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['memorias' => [], 'mejoras' => [], 'completo' => false])]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
        $segunda = $primera;
        $segunda['content'][0]['text'] = json_encode(['memorias' => [$this->regla($m)], 'mejoras' => [], 'completo' => true]);
        Http::fake(['*' => Http::sequence()->push($primera)->push($segunda)]);
        $this->assertSame(0, $this->repasar()['errores']);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('agente_consolidaciones', ['ai_conversation_id' => $m->ai_conversation_id, 'tamano_lote' => 9000, 'estado' => 'listo']);
    }

    public function test_memoria_llena_conserva_ensenanza_pendiente_y_no_bloquea_mejora()
    {
        AgenteContextoHelper::guardar($this->dueno, ConsolidacionDeMemoriaService::DETALLE, 'reemplazar', str_repeat('x', 19500), null, null, 'dueno');
        $m = $this->mensaje('Siempre por botella y no pude exportar.');
        $this->respuesta([$this->regla($m)], [['mensaje_id' => $m->id, 'evidencia' => $m->contenido, 'descripcion' => 'Revisar exportación.']]);
        $this->assertSame(0, $this->repasar()['errores']);
        $this->assertDatabaseHas('agente_consolidacion_memorias', ['user_id' => $this->dueno->id, 'estado' => 'pendiente_espacio', 'mensaje_id' => $m->id]);
        $this->assertDatabaseHas('agente_consolidacion_hallazgos', ['mensaje_id' => $m->id]);
        $this->assertStringContainsString('enseñanzas conservadas pendientes', AgenteContextoHelper::bloque_para_prompt($this->dueno));
    }

    public function test_no_exporta_secretos_en_hallazgos_ni_los_reintenta_siempre()
    {
        $m = $this->mensaje('No pude entrar con mi contraseña privada.');
        $this->respuesta([], [['mensaje_id' => $m->id, 'evidencia' => $m->contenido, 'descripcion' => 'Revisar acceso.']]);
        $this->assertSame(0, $this->repasar()['errores']);
        $this->assertDatabaseMissing('agente_consolidacion_hallazgos', ['mensaje_id' => $m->id]);
        $this->assertDatabaseHas('agente_consolidaciones', ['ai_conversation_id' => $m->ai_conversation_id, 'estado' => 'listo']);
    }

    public function test_no_aprende_de_logs_mcp_generados_por_otro_agente()
    {
        $m = $this->mensaje('Siempre por botella.');
        AiConversation::where('id', $m->ai_conversation_id)->update(['origen' => AiConversation::ORIGEN_MCP]);
        $this->respuesta();
        $this->repasar();
        Http::assertNothingSent();
    }

    public function test_revocar_admin_durante_http_impide_guardar_ensenanzas()
    {
        $empleado = $this->empleado(1);
        $m = $this->mensaje('Siempre por botella.', '2026-11-12 15:00:00', $empleado);
        Http::fake(['*' => function () use ($m, $empleado) {
            $empleado->update(['admin_access' => 0]);
            return Http::response(['model' => 'fake', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['memorias' => [$this->regla($m)], 'mejoras' => [], 'completo' => true])]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200);
        }]);
        $this->assertSame(1, $this->repasar()['errores']);
        $this->assertDatabaseMissing('agente_consolidacion_memorias', ['user_id' => $this->dueno->id]);
    }

    public function test_contexto_extenso_no_supera_presupuesto_json_del_analizador()
    {
        for ($i = 0; $i < 6; $i++) {
            AgenteContextoHelper::guardar($this->dueno, 'areas/largo_' . $i . '.md', 'reemplazar', str_repeat('"\\', 8000), null, null, 'dueno');
        }
        $this->mensaje('Siempre por unidad.');
        $this->respuesta();
        $this->assertSame(0, $this->repasar()['errores']);
        Http::assertSent(function ($request) {
            return mb_strlen($request->data()['messages'][0]['content']) <= 50000;
        });
    }

    public function test_sin_extension_no_hace_inferencia()
    {
        $this->dueno->extencions()->detach();
        $this->mensaje('Siempre por unidad.');
        $this->respuesta();
        $this->repasar();
        Http::assertNothingSent();
    }

    public function test_otro_frente_con_la_misma_base_no_infiere_el_mismo_negocio()
    {
        $this->mensaje('Siempre por unidad.');
        $this->respuesta();
        $config = DB::connection()->getConfig();
        $segundo = new \PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'],
            $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $nombre = 'sueno_' . substr(hash('sha256', $config['database'] . ':' . $this->dueno->id), 0, 55);
        $tomar = $segundo->prepare('SELECT GET_LOCK(?, 0)');
        $tomar->execute([$nombre]);
        $this->assertSame(1, (int) $tomar->fetchColumn());
        try {
            $this->assertSame(0, $this->repasar()['lotes']);
            Http::assertNothingSent();
            $this->assertDatabaseMissing('agente_consolidaciones', ['user_id' => $this->dueno->id]);
        } finally {
            $soltar = $segundo->prepare('SELECT RELEASE_LOCK(?)');
            $soltar->execute([$nombre]);
        }
        $this->assertSame(1, $this->repasar()['lotes']);
        Http::assertSentCount(1);
    }

    public function test_el_comando_rechaza_hoy_y_fecha_invalida()
    {
        $this->artisan('agente:consolidar-memoria', ['--fecha' => '2026-11-13'])->assertExitCode(1);
        $this->artisan('agente:consolidar-memoria', ['--fecha' => 'no-es-fecha'])->assertExitCode(1);
    }
}
