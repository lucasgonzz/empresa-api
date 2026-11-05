<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

class SimularAgenteTest extends EmpresaTestCase
{
    private const RUTA = '/api/prueba/simular-agente';
    private const PREGUNTA = '¿Tenés tuercas para estantes?';

    /** @var User */
    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data'  => [['embedding' => [1.0, 0.0, 0.0]]],
                'usage' => ['prompt_tokens' => 9, 'total_tokens' => 9],
            ]),
            'anthropic.test/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Sí, tengo tuercas para estantes.']],
                'usage'   => ['input_tokens' => 30, 'output_tokens' => 10],
            ]),
            '*' => Http::response([], 500),
        ]);
        Queue::fake();

        $this->dueno = $this->crear_usuario();
        $this->actingAs($this->dueno, 'web');

        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID' => $this->dueno->id,
            'services.anthropic.api_key' => 'clave-falsa',
            'services.anthropic.base_url' => 'https://anthropic.test',
            'services.deepseek.api_key' => null,
            'services.openai.api_key' => 'clave-falsa',
        ]);
        $extension = ExtencionEmpresa::firstOrCreate(['slug' => 'agentes_ia'], ['name' => 'Agentes de IA']);
        $this->dueno->extencions()->attach($extension->id);
        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();

        parent::tearDown();
    }

    public function test_responde_con_el_catalogo_del_dueno_sin_chat_ni_numero_conectado(): void
    {
        $this->crear_articulo_indexado($this->dueno, 'Tuerca para estantes');
        $this->crear_articulo_indexado($this->crear_usuario(), 'Artículo ajeno');
        $mensajes_antes = WhatsappChatMessage::count();

        $this->postJson(self::RUTA, ['pregunta' => self::PREGUNTA])
            ->assertOk()
            ->assertExactJson(['respuesta' => 'Sí, tengo tuercas para estantes.']);

        Http::assertSent(function (Request $request) {
            return strpos($request->url(), 'api.openai.com') !== false && $request['input'] === self::PREGUNTA;
        });
        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://anthropic.test/v1/messages') {
                return false;
            }
            $mensaje = $request['messages'][0];

            return $mensaje['role'] === 'user'
                && strpos($mensaje['content'], self::PREGUNTA) !== false
                && strpos($mensaje['content'], 'Tuerca para estantes') !== false
                && strpos($mensaje['content'], 'Artículo ajeno') === false;
        });
        Http::assertSentCount(2);
        $this->assertFalse(WhatsappChat::where('user_id', $this->dueno->id)->exists());
        $this->assertFalse(WhatsappBotConfig::where('user_id', $this->dueno->id)->exists());
        $this->assertSame($mensajes_antes, WhatsappChatMessage::count());
    }

    public function test_avisa_si_el_catalogo_propio_todavia_no_esta_indexado(): void
    {
        $this->crear_articulo_indexado($this->crear_usuario(), 'Artículo ajeno');

        $this->postJson(self::RUTA, ['pregunta' => self::PREGUNTA])
            ->assertStatus(422)
            ->assertJsonPath('code', 'catalogo_sin_indexar');

        Http::assertNothingSent();
    }

    public function test_rechaza_empleados_y_cuentas_que_ya_contrataron(): void
    {
        $empleado = $this->crear_usuario($this->dueno->id);
        $this->actingAs($empleado, 'web');
        $this->postJson(self::RUTA, ['pregunta' => self::PREGUNTA])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Solo el dueño puede probar el agente.');

        $this->actingAs($this->dueno, 'web');
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $this->postJson(self::RUTA, ['pregunta' => self::PREGUNTA])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_valida_la_pregunta_y_limita_a_diez_consultas_por_minuto(): void
    {
        for ($intento = 0; $intento < 10; $intento++) {
            $this->postJson(self::RUTA, ['pregunta' => str_repeat('a', 501)])
                ->assertStatus(422)
                ->assertJsonValidationErrors('pregunta');
        }

        $this->postJson(self::RUTA, ['pregunta' => self::PREGUNTA])->assertStatus(429);

        Http::assertNothingSent();
    }

    private function crear_usuario(?int $owner_id = null): User
    {
        return User::create([
            'name' => 'Comercio en prueba',
            'email' => 'simulador-' . uniqid() . '@test.local',
            'password' => bcrypt('fixture-no-utilizable'),
            'owner_id' => $owner_id,
        ]);
    }

    private function crear_articulo_indexado(User $dueno, string $nombre): void
    {
        $articulo = Article::create(['user_id' => $dueno->id, 'name' => $nombre, 'status' => 'active']);
        DB::table('articles')->where('id', $articulo->id)->update([
            'embedding' => json_encode([1.0, 0.0, 0.0]),
            'embedding_generated_at' => now(),
        ]);
    }
}
