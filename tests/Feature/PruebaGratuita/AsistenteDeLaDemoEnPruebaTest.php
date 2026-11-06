<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\DemoIngresoToken;
use App\Models\DemoTrackingConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El asistente de demo no se activa en una prueba gratuita. La prueba tiene su propio chat de ayuda
 * para el dueño mientras su período siga vigente.
 *
 * La prueba entra por el mismo link mágico que una demo, así que su sesión lleva el marcador
 * `demo_ingreso_token_id` y el SPA montaba «Tu asistente de la demo». Ese chat vive del lado del
 * admin y solo atiende a un lead de demo: para el negocio de una prueba contestaba 503 en cada
 * intento (el proxy convierte el 401 del admin en 503). Una prueba no tiene que mostrar un asistente
 * que no puede funcionar, ni con texto de demo.
 *
 *  1. En una prueba, `es_sesion_demo` viaja en false aunque la sesión traiga el marcador.
 *  2. En una prueba, `demo/chat*` y `demo/plan` contestan 204 mudo y no le hablan al admin (un SPA
 *     viejo en caché los sigue pidiendo).
 *  3. En una demo de verdad nada cambia.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe ni argumentos nombrados.
 */
class AsistenteDeLaDemoEnPruebaTest extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);

        $this->dueno = User::create([
            'name'     => 'Prueba del asistente lateral',
            'email'    => uniqid('prueba-asistente-') . '@test.local',
            'password' => bcrypt('prueba-local'),
        ]);

        DemoTrackingConfig::query()->delete();
        DemoTrackingConfigHelper::olvidar_cache();
        PruebaHelper::olvidar_cache();

        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        DemoTrackingConfigHelper::olvidar_cache();
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    /** Sesión que entró por el link mágico, con el canal al admin armado. */
    protected function sesion_de_link_magico()
    {
        $token = DemoIngresoToken::create([
            'token_hash' => hash('sha256', uniqid('asistente-prueba-', true)),
            'user_id'    => $this->dueno->id,
            'expires_at' => now()->addHours(2),
        ]);

        DemoTrackingConfigHelper::guardar('clave-interna-de-prueba', 'https://admin.test/api/demo-eventos', array(array('id' => 1, 'titulo' => 'Seccion', 'clips' => array())), null);
        DemoTrackingConfigHelper::olvidar_cache();

        return $this->withSession(['demo_ingreso_token_id' => $token->id]);
    }

    protected function snapshot()
    {
        return ['disponible' => true, 'version' => 1, 'conversacion' => ['revision' => 1, 'estado' => 'esperando'], 'mensajes' => []];
    }

    public function test_en_una_prueba_la_sesion_del_link_no_se_marca_como_demo()
    {
        config(['app.FOR_USER' => 'prueba']);

        $respuesta = $this->sesion_de_link_magico()->getJson('/api/user');

        $respuesta->assertStatus(200);
        $this->assertFalse(
            $respuesta->json('user.es_sesion_demo'),
            'En una prueba el SPA no tiene que montar el asistente ni la guía de la demo.'
        );
    }

    public function test_en_una_demo_la_sesion_del_link_sigue_marcada_como_demo()
    {
        config(['app.FOR_USER' => 'demo']);

        $respuesta = $this->sesion_de_link_magico()->getJson('/api/user');

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('user.es_sesion_demo'));
    }

    public function test_en_una_prueba_el_chat_contesta_204_y_no_le_habla_al_admin()
    {
        config(['app.FOR_USER' => 'prueba']);
        Http::fake(['admin.test/*' => Http::response([], 401)]);

        $sesion = $this->sesion_de_link_magico();

        $sesion->getJson('/api/demo/chat')->assertStatus(204);
        $this->postJson('/api/demo/chat/iniciar')->assertStatus(204);
        $this->postJson('/api/demo/chat/mensajes', [])->assertStatus(204);
        $this->postJson('/api/demo/chat/videos/progreso', [])->assertStatus(204);
        $this->postJson('/api/demo/chat/videos/omitir', [])->assertStatus(204);
        $this->postJson('/api/demo/chat/videos/seleccionar', [])->assertStatus(204);

        Http::assertNothingSent();
    }

    public function test_en_una_prueba_el_plan_de_la_demo_contesta_204()
    {
        config(['app.FOR_USER' => 'prueba']);
        Http::fake();

        $this->sesion_de_link_magico()->getJson('/api/demo/plan')->assertStatus(204);

        Http::assertNothingSent();
    }

    public function test_en_una_demo_el_chat_sigue_hablando_con_el_admin()
    {
        config(['app.FOR_USER' => 'demo']);
        Http::fake(['admin.test/*' => Http::response($this->snapshot(), 200)]);

        $this->sesion_de_link_magico()->getJson('/api/demo/chat')->assertOk()->assertExactJson($this->snapshot());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://admin.test/api/demo-chat';
        });
    }

    /**
     * El marcador de la sesión se conserva en una prueba: la sesión del link sigue exenta del candado
     * de sesión única. Si el candado la tomara, entrar por el link con otro dispositivo abierto daría
     * 403 (se rompió una vez en el desarrollo de este arreglo).
     */
    public function test_en_una_prueba_la_sesion_del_link_sigue_sin_tomar_el_candado_de_sesion_unica()
    {
        config(['app.FOR_USER' => 'prueba']);

        $this->dueno->session_id = 'otro-dispositivo';
        $this->dueno->last_activity = now();
        $this->dueno->save();

        $respuesta = $this->sesion_de_link_magico()->getJson('/api/user');

        $respuesta->assertStatus(200);
        $this->assertNotNull($respuesta->json('user'));
    }

    private function activar_prueba()
    {
        config([
            'app.FOR_USER' => 'prueba',
            'services.admin_api.url' => 'https://admin.test',
            'services.admin_api.inbound_key' => 'clave-interna-de-la-instancia',
        ]);
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        DemoTrackingConfigHelper::guardar('clave-interna-de-prueba', 'https://tracking.test/api/demo-eventos');
    }

    public function test_el_dueno_de_prueba_activa_ve_el_chat_con_o_sin_link_magico()
    {
        $this->activar_prueba();
        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('user.puede_usar_chat_prueba', true)
            ->assertJsonPath('user.es_sesion_demo', false);
        $this->sesion_de_link_magico()->getJson('/api/user')->assertOk()
            ->assertJsonPath('user.puede_usar_chat_prueba', true)
            ->assertJsonPath('user.es_sesion_demo', false);
    }

    public function test_chat_propio_de_prueba_usa_el_canal_autenticado_sin_datos_de_demo()
    {
        $this->activar_prueba();
        Http::fake(['admin.test/*' => Http::response($this->snapshot(), 200)]);
        $uuid = 'c7bbd2f1-87f8-4f48-a534-319f43e33d7b';

        $this->getJson('/api/prueba/chat')->assertOk()->assertExactJson($this->snapshot());
        $this->postJson('/api/prueba/chat/iniciar')->assertOk()->assertExactJson($this->snapshot());
        $this->postJson('/api/prueba/chat/mensajes', [
            'contenido' => '¿Cómo cargo mis artículos?',
            'cliente_uuid' => $uuid,
            'lead_id' => 42,
            'guia' => ['version' => 1, 'pantalla' => 'vender'],
        ])->assertOk()->assertExactJson($this->snapshot());
        $this->postJson('/api/prueba/chat/mensajes', ['contenido' => 'Falta el UUID'])->assertStatus(422);
        Http::assertSentCount(3);
        Http::assertSent(function ($request) use ($uuid) {
            return $request->url() === 'https://admin.test/api/prueba-chat/mensajes'
                && $request->hasHeader('X-Demo-Eventos-Key', 'clave-interna-de-prueba')
                && $request->hasHeader('X-Admin-Api-Key', 'clave-interna-de-la-instancia')
                && $request->data() === ['contenido' => '¿Cómo cargo mis artículos?', 'cliente_uuid' => $uuid];
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://admin.test/api/prueba-chat/iniciar'
                && $request->method() === 'POST';
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://admin.test/api/prueba-chat' && $request->method() === 'GET';
        });
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'https://tracking.test/') === 0;
        });
    }

    public function test_un_admin_viejo_no_rebota_al_canal_demo()
    {
        $this->activar_prueba();
        Http::fake(['admin.test/*' => Http::response([], 404)]);
        $this->postJson('/api/prueba/chat/iniciar')->assertOk()->assertExactJson(['disponible' => false]);
        Http::assertSentCount(1);
    }

    public function test_la_prueba_vencida_conserva_la_respuesta_de_vencimiento()
    {
        $this->activar_prueba();
        PruebaHelper::fijar_vencimiento($this->dueno->id, now()->subMinute());
        Http::fake();

        $this->getJson('/api/prueba/chat')->assertForbidden()->assertJsonPath('error', 'prueba_vencida');
        Http::assertNothingSent();
    }

    public function test_cuenta_convertida_a_pago_no_conserva_el_chat()
    {
        $this->activar_prueba();
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        Http::fake();

        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.puede_usar_chat_prueba', false);
        $this->getJson('/api/prueba/chat')->assertStatus(204);
        Http::assertNothingSent();
    }

    public function test_solo_el_dueno_puede_leer_el_hilo_de_la_prueba()
    {
        $this->activar_prueba();
        $empleado = User::create([
            'name' => 'Empleado',
            'email' => uniqid('empleado-prueba-') . '@test.local',
            'password' => bcrypt('prueba-local'),
            'owner_id' => $this->dueno->id,
        ]);
        Http::fake();

        $this->actingAs($empleado, 'web')->getJson('/api/prueba/chat')->assertStatus(204);
        $this->actingAs($this->dueno, 'web')->withSession(['master_login_bypass_user_last_activity' => true])
            ->getJson('/api/prueba/chat')->assertStatus(204);
        Http::assertNothingSent();
    }

    public function test_demo_y_visita_anonima_no_usan_el_chat_de_prueba()
    {
        config(['app.FOR_USER' => 'demo']);
        Http::fake();
        $this->sesion_de_link_magico()->getJson('/api/prueba/chat')->assertStatus(204);

        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->getJson('/api/prueba/chat')->assertUnauthorized();
        Http::assertNothingSent();
    }
}
