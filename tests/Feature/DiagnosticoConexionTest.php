<?php

namespace Tests\Feature;

use App\Http\Middleware\DiagnosticoConexion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\EmpresaTestCase;

class DiagnosticoConexionTest extends EmpresaTestCase
{
    private $registros = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['diagnostico_conexion.usuarios' => []]);
    }

    private function habilitar()
    {
        config(['diagnostico_conexion.usuarios' => [(string) auth()->id()]]);
        $this->withHeader('X-Diagnostico-Persona', (string) auth()->id());
    }

    private function capturar_log($fallar = false)
    {
        $canal = \Mockery::mock();
        if ($fallar) {
            $canal->shouldReceive('info')->andThrow(new \RuntimeException('SECRETO del logger'));
        } else {
            $canal->shouldReceive('info')->andReturnUsing(function ($mensaje, $datos) {
                $this->registros[] = ['mensaje' => $mensaje, 'datos' => $datos];
            });
        }
        Log::partialMock()->shouldReceive('channel')->with('diagnostico_conexion')->andReturn($canal);
    }

    private function evento($extra = [])
    {
        return array_merge([
            'at_ms' => (int) floor(microtime(true) * 1000), 'duracion_ms' => 8000,
            'tipo' => 'timeout', 'metodo' => 'GET', 'endpoint' => '/sanctum/csrf-cookie',
            'ruta' => 'provider', 'status' => 0, 'origen' => 'ninguno',
            'nav_online' => true, 'auth_online' => false, 'sesion_offline' => false,
            'pendientes' => 3, 'exitos_cercanos' => 2, 'skip_global' => true,
            'skip_navegacion' => true, 'cancelable' => false, 'request_id' => str_repeat('a', 32),
        ], $extra);
    }

    public function test_configuracion_apagada_por_defecto_y_persona_explicita()
    {
        $this->getJson('/api/diagnostico-conexion/config')->assertOk()->assertJson(['habilitado' => false, 'persona' => auth()->id()]);
        $this->habilitar();
        $this->getJson('/api/diagnostico-conexion/config')->assertOk()->assertJson(['habilitado' => true]);
    }

    public function test_no_habilitado_no_puede_subir_ni_suplantar_al_dueno()
    {
        config(['diagnostico_conexion.usuarios' => ['99999999']]);
        $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento()]])->assertForbidden();
        $this->habilitar();
        $this->withHeader('X-Diagnostico-Persona', '99999999');
        $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento()]])->assertStatus(409);
    }

    public function test_sin_autenticacion_ambos_endpoints_devuelven_401()
    {
        auth()->logout();
        $this->getJson('/api/diagnostico-conexion/config')->assertUnauthorized();
        $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento()]])->assertUnauthorized();
    }

    public function test_log_sanea_nuevamente_ruta_y_endpoint_y_atribuye_identidad_desde_auth()
    {
        $this->habilitar(); $this->capturar_log();
        $id = auth()->id();
        $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento([
            'endpoint' => 'https://api.test/api/provider-order/123/clienteJuan?token=SECRETO',
            'ruta' => 'SECRETO pantalla',
        ])]])->assertStatus(202);
        $this->assertCount(1, $this->registros);
        $datos = $this->registros[0]['datos'];
        $this->assertSame($id, $datos['auth_user_id']);
        $this->assertSame($id, $datos['owner_id']);
        $this->assertSame('/provider-order/:valor/:valor', $datos['evento']['endpoint']);
        $this->assertSame('otra', $datos['evento']['ruta']);
        $this->assertStringNotContainsString('SECRETO', json_encode($this->registros));
        $this->assertStringNotContainsString('clienteJuan', json_encode($this->registros));
    }

    public function test_no_acepta_campos_ajenos_ids_payload_tokens_ni_errores_en_bruto()
    {
        $this->habilitar(); $this->capturar_log();
        foreach (['user_id', 'owner_id', 'payload', 'headers', 'message', 'stack', 'url'] as $campo) {
            $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento([$campo => 'SECRETO'])]])->assertStatus(422);
        }
        $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento()], 'user_id' => 999])->assertStatus(422);
        $this->assertCount(0, $this->registros);
    }

    public function test_limites_de_lote_tamano_duracion_contadores_fecha_y_request_id()
    {
        $this->habilitar(); $this->capturar_log();
        $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => array_fill(0, 11, $this->evento())])->assertStatus(422);
        $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento(['endpoint' => str_repeat('x', 25000)])]])->assertStatus(413);
        foreach ([['duracion_ms' => -1], ['pendientes' => 201], ['exitos_cercanos' => 21], ['at_ms' => 1], ['request_id' => 'SECRETO'], ['status' => 999]] as $invalido) {
            $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento($invalido)]])->assertStatus(422);
        }
        $this->assertCount(0, $this->registros);
    }

    public function test_log_separado_es_diario_y_conserva_siete_dias()
    {
        $this->assertSame('daily', config('logging.channels.diagnostico_conexion.driver'));
        $this->assertSame(7, config('logging.channels.diagnostico_conexion.days'));
        $this->assertStringEndsWith('diagnostico-conexion.log', config('logging.channels.diagnostico_conexion.path'));
    }

    public function test_falla_de_logger_no_reporta_ni_interrumpe_operacion_de_negocio()
    {
        $this->habilitar(); $this->capturar_log(true);
        $this->withHeader('X-Diagnostico-Id', str_repeat('a', 32));
        Route::middleware(['api', 'auth:sanctum', DiagnosticoConexion::class])->get('/api/prueba-diagnostico/{id}', function () { return response()->json(['negocio' => 'bien']); });
        $this->getJson('/api/prueba-diagnostico/123?token=SECRETO')->assertOk()->assertJson(['negocio' => 'bien']);
        $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento()]])->assertStatus(503);
    }

    public function test_correlacion_registra_inicio_fin_template_sin_valores_ni_query()
    {
        $this->habilitar(); $this->capturar_log();
        $this->withHeader('X-Diagnostico-Id', str_repeat('b', 32));
        Route::middleware(['api', 'auth:sanctum', DiagnosticoConexion::class])->get('/api/prueba-diagnostico/{id}', function () { return response()->json(['secreto' => 'SECRETO']); });
        $this->getJson('/api/prueba-diagnostico/clienteJuan?token=SECRETO')->assertOk();
        $this->assertSame(['INICIO', 'FIN'], array_column($this->registros, 'mensaje'));
        $this->assertSame('api/prueba-diagnostico/{id}', $this->registros[0]['datos']['ruta_template']);
        $this->assertSame(200, $this->registros[1]['datos']['status']);
        $this->assertStringNotContainsString('SECRETO', json_encode($this->registros));
        $this->assertStringNotContainsString('clienteJuan', json_encode($this->registros));
    }

    public function test_correlacion_web_del_sondeo_csrf_no_duplica_registros()
    {
        $this->habilitar(); $this->capturar_log(); $this->withHeader('X-Diagnostico-Id', str_repeat('c', 32));
        $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $this->assertSame(['INICIO', 'FIN'], array_column($this->registros, 'mensaje'));
        $this->assertSame('sanctum/csrf-cookie', $this->registros[1]['datos']['ruta_template']);
        $this->assertSame(204, $this->registros[1]['datos']['status']);
    }

    public function test_id_ajeno_o_malformado_no_activa_correlacion()
    {
        $this->habilitar(); $this->capturar_log();
        $this->withHeader('X-Diagnostico-Id', 'SECRETO');
        $this->getJson('/api/diagnostico-conexion/config')->assertOk();
        $this->withHeader('X-Diagnostico-Id', str_repeat('c', 32))->withHeader('X-Diagnostico-Persona', '99999');
        $this->getJson('/api/diagnostico-conexion/config')->assertOk();
        $this->assertCount(0, $this->registros);
    }

    public function test_cors_permite_los_headers_de_correlacion_sin_cambiar_configuracion()
    {
        config(['cors.allowed_origins' => ['https://spa.ejemplo.test']]);
        $this->options('/api/provider-order', [], [
            'Origin' => 'https://spa.ejemplo.test', 'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-diagnostico-id,x-diagnostico-persona,content-type',
        ])->assertSuccessful()->assertHeader('Access-Control-Allow-Headers', 'x-diagnostico-id,x-diagnostico-persona,content-type');
    }

    public function test_excepcion_conserva_error_http_y_registra_clase_sin_mensaje_ni_datos()
    {
        $this->habilitar(); $this->capturar_log(); $this->withHeader('X-Diagnostico-Id', str_repeat('d', 32));
        Route::middleware(['api', 'auth:sanctum', DiagnosticoConexion::class])->get('/api/prueba-diagnostico-error', function () {
            throw \Illuminate\Validation\ValidationException::withMessages(['dato' => 'SECRETO']);
        });
        $this->getJson('/api/prueba-diagnostico-error')->assertStatus(422);
        $this->assertSame(['INICIO', 'EXCEPCION'], array_column($this->registros, 'mensaje'));
        $this->assertSame(422, $this->registros[1]['datos']['status']);
        $this->assertSame(\Illuminate\Validation\ValidationException::class, $this->registros[1]['datos']['clase']);
        $this->assertStringNotContainsString('SECRETO', json_encode($this->registros));
    }

    public function test_diagnostico_tiene_throttle_propio_para_no_inundar_log()
    {
        $this->habilitar(); $this->capturar_log();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento()]])->assertStatus(202);
        }
        $this->postJson('/api/diagnostico-conexion/eventos', ['eventos' => [$this->evento()]])->assertStatus(429);
        $this->assertCount(10, $this->registros);
    }
}
