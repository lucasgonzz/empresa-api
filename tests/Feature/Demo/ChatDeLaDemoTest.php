<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoIngresoToken;
use App\Models\DemoTrackingConfig;
use App\Models\User;
use App\Services\DemoChatProxy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatDeLaDemoTest extends TestCase
{
    use DatabaseTransactions;

    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'null']);
        $this->dueno = User::create(['name' => 'Prueba chat demo', 'email' => uniqid('demo-chat-') . '@test.local', 'password' => bcrypt('prueba-local')]);
        DemoTrackingConfig::query()->delete();
        DemoTrackingConfigHelper::olvidar_cache();
        $this->withHeader('Referer', rtrim(config('app.url'), '/') . '/');
        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        DemoTrackingConfigHelper::olvidar_cache();
        parent::tearDown();
    }

    protected function demo()
    {
        $token = DemoIngresoToken::create(['token_hash' => hash('sha256', uniqid('chat-', true)), 'user_id' => $this->dueno->id, 'expires_at' => now()->addHours(2)]);
        DemoTrackingConfigHelper::guardar('clave-interna-de-prueba', 'https://admin.test/api/demo-eventos');
        DemoTrackingConfigHelper::olvidar_cache();
        return $this->withSession(['demo_ingreso_token_id' => $token->id]);
    }

    protected function snapshot()
    {
        return ['disponible' => true, 'version' => 1, 'conversacion' => ['revision' => 1, 'estado' => 'esperando', 'auto_responder' => true], 'mensajes' => []];
    }

    public function test_fuera_de_demo_no_consulta_el_admin_ni_crea_conversacion()
    {
        Http::fake();
        $this->getJson('/api/demo/chat')->assertStatus(204);
        $this->postJson('/api/demo/chat/iniciar')->assertStatus(204);
        $this->postJson('/api/demo/chat/mensajes', [])->assertStatus(204);
        Http::assertNothingSent();
    }

    public function test_lectura_pura_y_bienvenida_usan_metodos_distintos_y_credencial_privada()
    {
        Http::fake(['admin.test/*' => Http::response($this->snapshot(), 200)]);
        $this->demo()->getJson('/api/demo/chat')->assertOk()->assertExactJson($this->snapshot());
        $this->postJson('/api/demo/chat/iniciar')->assertOk();
        Http::assertSent(function ($request) { return $request->method() === 'GET' && $request->url() === 'https://admin.test/api/demo-chat' && $request->hasHeader('X-Demo-Eventos-Key', 'clave-interna-de-prueba'); });
        Http::assertSent(function ($request) { return $request->method() === 'POST' && $request->url() === 'https://admin.test/api/demo-chat/iniciar'; });
    }

    public function test_mensaje_conserva_uuid_para_idempotencia_y_descarta_identidad_elegida_por_navegador()
    {
        Http::fake(['admin.test/*' => Http::response($this->snapshot(), 202)]);
        $uuid = '7c3f10ec-a624-4357-b30a-95186697001a';
        $this->demo()->postJson('/api/demo/chat/mensajes', ['contenido' => 'Quiero ver el agente', 'cliente_uuid' => $uuid, 'lead_id' => 22, 'token' => 'otro'])->assertStatus(202);
        Http::assertSent(function ($request) use ($uuid) {
            return $request->url() === 'https://admin.test/api/demo-chat/mensajes'
                && $request->data() === ['contenido' => 'Quiero ver el agente', 'cliente_uuid' => $uuid];
        });
    }

    public function test_admin_anterior_conserva_el_recorrido_legacy()
    {
        Http::fake(['admin.test/*' => Http::response([], 404)]);
        $this->demo()->getJson('/api/demo/chat')->assertOk()->assertExactJson(['disponible' => false]);
    }

    public function test_error_del_admin_no_expone_credenciales_ni_contenido_interno()
    {
        Http::fake(['admin.test/*' => Http::response(['error' => 'clave-interna-de-prueba'], 500)]);
        $response = $this->demo()->getJson('/api/demo/chat')->assertStatus(503);
        $this->assertStringNotContainsString('clave-interna-de-prueba', $response->getContent());
    }

    public function test_progreso_por_segundos_conserva_origen_y_rechaza_fuera_de_rango()
    {
        Http::fake(['admin.test/*' => Http::response($this->snapshot(), 200)]);
        $payload = ['clave' => 'recurso:T1.1', 'segundos_vistos' => [0, 1, 2], 'duracion' => 25.5];
        $this->demo()->postJson('/api/demo/chat/videos/progreso', $payload)->assertOk();
        Http::assertSent(function ($request) use ($payload) { return $request->url() === 'https://admin.test/api/demo-chat/videos/progreso' && $request->data() === $payload; });
        $final = ['clave' => 'recurso:T1.1', 'segundos_vistos' => [], 'terminado' => true];
        $this->postJson('/api/demo/chat/videos/progreso', $final)->assertOk();
        Http::assertSent(function ($request) use ($final) { return $request->data() === $final; });
        $this->postJson('/api/demo/chat/videos/progreso', ['clave' => 'demo:1.1', 'terminado' => true])->assertStatus(422);
        $this->postJson('/api/demo/chat/videos/progreso', ['clave' => 'demo:1.1', 'segundos_vistos' => [-1]])->assertStatus(422);
        $this->postJson('/api/demo/chat/videos/omitir', ['clave' => '../otro'])->assertStatus(422);
    }

    public function test_uuid_invalido_no_sale_del_servidor()
    {
        Http::fake();
        $this->demo()->postJson('/api/demo/chat/mensajes', ['contenido' => 'Hola', 'cliente_uuid' => 'otro'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_deriva_solo_el_segmento_final_del_endpoint()
    {
        $this->assertSame('https://admin.test/demo-media-urls/api/demo-chat/mensajes?x=1', DemoChatProxy::url('https://admin.test/demo-media-urls/api/demo-eventos?x=1', '/mensajes'));
        $this->assertNull(DemoChatProxy::url('https://admin.test/api/otra-cosa'));
    }
}
