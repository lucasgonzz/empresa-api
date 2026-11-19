<?php

namespace Tests\Feature\Mcp;

use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PresupuestoDeOperacionesIaHelper as Cupos;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PresupuestoCompartidoTest extends McpTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conversacion($persona = null)
    {
        return AiConversation::create(['user_id' => $this->dueno->id,
            'auth_user_id' => $persona ? $persona->id : $this->dueno->id, 'origen' => 'usuario']);
    }

    private function consumir($conversacion)
    {
        $reserva = [];
        $rechazo = Cupos::herramienta($conversacion, $reserva);
        Cupos::liberar_herramienta($reserva);
        return $rechazo;
    }

    public function test_batch_20_aceptado_21_y_cuerpo_grande_rechazados_antes_de_crear_sesion()
    {
        $mensajes = array_fill(0, 20, $this->mensaje('ping'));
        $this->postJson('api/mcp', $mensajes, $this->headers())->assertStatus(200)->assertJsonCount(20);
        $antes = AiConversation::count();
        $mensajes[] = $this->mensaje('initialize');
        $this->postJson('api/mcp', $mensajes, $this->headers())->assertStatus(413);
        $this->assertSame($antes, AiConversation::count());
        $this->rpc('initialize', ['relleno' => str_repeat('a', 262145)])->assertStatus(413);
        $this->assertSame($antes, AiConversation::count());
    }

    public function test_tools_de_batch_y_notifications_comparten_presupuesto_con_el_asistente()
    {
        config(['proteccion_asistente.herramientas.persona_minuto' => 2]);
        $sesion = $this->inicializar();
        $mensajes = [
            $this->mensaje('tools/call', ['name' => 'consultar_proveedores', 'arguments' => []], null),
            $this->mensaje('tools/call', ['name' => 'consultar_proveedores', 'arguments' => []], 2),
            $this->mensaje('tools/call', ['name' => 'consultar_proveedores', 'arguments' => []], 3),
        ];
        $respuesta = $this->postJson('api/mcp', $mensajes, $this->headers(null, $sesion))->assertStatus(200);
        $this->assertFalse((bool) $respuesta->json('0.result.isError'));
        $this->assertTrue($respuesta->json('1.result.isError'));
        $conversacion = $this->conversacion();
        $servicio = new AsistenteIaService();
        $resultado = $servicio->execute_tool_calls([['type' => 'tool_use', 'id' => 'interno',
            'name' => 'consultar_proveedores', 'input' => []]], $conversacion);
        $this->assertTrue($resultado[0]['is_error']);
        $this->assertStringContainsString('asistente_ocupado', $resultado[0]['content']);
    }

    public function test_el_rechazo_mcp_no_crea_historial_ni_cobra_dos_veces_la_admision()
    {
        config(['proteccion_asistente.herramientas.persona_minuto' => 1]);
        $sesion = $this->inicializar();
        $this->rpc('tools/call', ['name' => 'consultar_proveedores'], 1, null, $sesion)->assertStatus(200);
        $antes = AiMessage::count();
        $respuesta = $this->rpc('tools/call', ['name' => 'proponer_actualizacion_masiva', 'arguments' => []], 2, null, $sesion);
        $this->assertTrue($respuesta->json('result.isError'));
        $this->assertSame($antes, AiMessage::count());
    }

    public function test_ventanas_minuto_hora_e_identidad_del_negocio_compartida_por_empleados()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00'));
        config(['proteccion_asistente.herramientas.persona_minuto' => 1,
            'proteccion_asistente.herramientas.persona_hora' => 2,
            'proteccion_asistente.herramientas.negocio_minuto' => 2]);
        $dueno = $this->conversacion();
        $empleado = $this->conversacion($this->empleado());
        $this->assertNull($this->consumir($dueno));
        $this->assertSame(429, $this->consumir($dueno)['status']);
        $this->assertNull($this->consumir($empleado));
        Carbon::setTestNow(now()->addMinute());
        $this->assertNull($this->consumir($dueno));
        Carbon::setTestNow(now()->addMinute());
        $this->assertSame(429, $this->consumir($dueno)['status']);
        Carbon::setTestNow(now()->addHour());
        $this->assertNull($this->consumir($dueno));
    }

    public function test_concurrencia_de_tools_es_compartida_y_libera_reservas_internas()
    {
        $conversacion = $this->conversacion();
        $servicio = new AsistenteIaService();
        $this->assertNull($servicio->reservar_herramienta($conversacion, 'consultar_proveedores'));
        $this->assertSame(429, $this->consumir($conversacion)['status']);
        $servicio->liberar_reservas();
        $this->assertNull($this->consumir($conversacion));
        $empleado1 = $this->conversacion($this->empleado());
        $empleado2 = $this->conversacion($this->empleado());
        $reservas = [];
        $this->assertNull(Cupos::herramienta($conversacion, $reservas));
        $otra = [];
        $this->assertNull(Cupos::herramienta($empleado1, $otra));
        try {
            $this->assertSame(429, $this->consumir($empleado2)['status']);
        } finally {
            Cupos::liberar_herramienta($reservas);
            Cupos::liberar_herramienta($otra);
        }
        $this->assertNull($this->consumir($empleado2));
    }

    public function test_confirmacion_de_articulo_rechazada_conserva_la_tarjeta_reintentable_y_no_toca_ventas()
    {
        $conversacion = $this->conversacion();
        $mensaje = AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'assistant',
            'estado' => 'listo', 'acciones_habilitadas' => true]);
        $accion = AiMessageAction::create(['ai_conversation_id' => $conversacion->id, 'ai_message_id' => $mensaje->id,
            'user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id, 'clave' => 'prueba', 'presentacion' => [],
            'tipo' => AiMessageAction::TIPO_ACTUALIZACION_MASIVA, 'estado' => 'propuesta', 'datos' => []]);
        config(['proteccion_asistente.escrituras.persona_minuto' => 1]);
        $this->assertSame('una', Cupos::escritura($conversacion, $accion, function () { return 'una'; }));
        $respuesta = EjecutorAccionesIaHelper::confirmar($conversacion, $accion->id, $this->dueno, null);
        $this->assertSame(429, $respuesta['status']);
        $this->assertSame('propuesta', $accion->fresh()->estado);
        $this->assertNull($accion->fresh()->error_mensaje);
        $venta = new AiMessageAction(['tipo' => AiMessageAction::TIPO_VENTA]);
        $this->assertFalse(Cupos::es_de_articulos($venta));
        $generica = new AiMessageAction(['tipo' => AiMessageAction::TIPO_EDICION, 'datos' => ['entidad' => 'article', 'operacion' => 'edicion']]);
        $this->assertTrue(Cupos::es_de_articulos($generica));
    }

    public function test_array_null_configuracion_invalida_y_cache_caida_fallan_cerrados()
    {
        $conversacion = $this->conversacion();
        config(['proteccion_asistente.store' => 'array']);
        $this->assertSame(503, $this->consumir($conversacion)['status']);
        config(['cache.stores.cupos_null' => ['driver' => 'null'], 'proteccion_asistente.store' => 'cupos_null']);
        $this->assertSame(503, $this->consumir($conversacion)['status']);
        config(['proteccion_asistente.store' => 'recursos_articulos_testing', 'proteccion_asistente.herramientas.persona_minuto' => 0]);
        $this->assertSame(503, $this->consumir($conversacion)['status']);
        config(['proteccion_asistente.herramientas.persona_minuto' => 30]);
        Cache::shouldReceive('store')->andThrow(new \RuntimeException('Falla simulada'));
        $this->assertSame(503, $this->consumir($conversacion)['status']);
    }
}
