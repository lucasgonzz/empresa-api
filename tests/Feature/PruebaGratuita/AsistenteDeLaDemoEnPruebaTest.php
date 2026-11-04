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
 * El asistente lateral y la guía de la demo NO existen en una prueba gratuita (4/11/2026, misión
 * prueba-defectos-asistente-y-vender).
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
            'name'     => 'Prueba sin asistente',
            'email'    => uniqid('prueba-sin-asistente-') . '@test.local',
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
}
