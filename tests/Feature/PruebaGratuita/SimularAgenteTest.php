<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Jobs\AutoSendWhatsappAiMessageJob;
use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\Article;
use App\Models\Client;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\RecordatorioCobroSenderService;
use App\Services\WhatsappAgentScheduler;
use App\Services\WhatsappAiAutoSendScheduler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;

class SimularAgenteTest extends EmpresaTestCase
{
    use OtorgaAgentesIa;

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
        $this->otorgar_agentes_ia($this->dueno);
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

    public function test_recorrido_reutiliza_un_chat_de_prueba_automatico_y_respeta_el_modo_elegido(): void
    {
        $payload = ['is_test' => true, 'trial_demo' => true];
        $this->postJson('/api/whatsapp-chats', $payload)->assertStatus(422)
            ->assertJsonPath('code', 'catalogo_sin_indexar');

        $this->crear_articulo_indexado($this->dueno, 'Tuerca para estantes');
        $id = $this->postJson('/api/whatsapp-chats', $payload)->assertCreated()
            ->assertJsonPath('model.is_test', true)
            ->assertJsonPath('model.ai_mode', 'automatic')
            ->assertJsonPath('model.display_name', 'Cliente de prueba')->json('model.id');

        $this->putJson('/api/whatsapp-chats/'.$id.'/ai-mode', ['ai_mode' => 'semi_automatic'])->assertOk()
            ->assertJsonPath('model.ai_enabled', false);
        $this->postJson('/api/whatsapp-chats', $payload)->assertCreated()
            ->assertJsonPath('model.id', $id)
            ->assertJsonPath('model.ai_mode', 'semi_automatic');

        $this->assertSame(1, WhatsappChat::where('user_id', $this->dueno->id)->count());
        $this->assertFalse(WhatsappBotConfig::where('user_id', $this->dueno->id)->exists());
        Http::assertNothingSent();
    }

    public function test_chat_de_prueba_automatico_semi_y_manual_sin_envios_a_kapso(): void
    {
        $id = $this->postJson('/api/whatsapp-chats', ['is_test' => true])->assertCreated()->json('model.id');
        $ruta_simulacion = '/api/whatsapp-bot/simulate-inbound';
        $pregunta = ['chat_id' => $id, 'body' => self::PREGUNTA];
        $chat = WhatsappChat::findOrFail($id);

        $this->postJson($ruta_simulacion, $pregunta)->assertCreated()
            ->assertJsonPath('model.id', $id)
            ->assertJsonPath('message.direction', 'in');
        (new GenerateWhatsappAiReplyJob($id, $chat->fresh()->ai_schedule_token))->handle(app(WhatsappAgentScheduler::class));

        $pendiente = $chat->messages()->where('ai_status', 'a_confirmar')->firstOrFail();
        Queue::assertPushed(AutoSendWhatsappAiMessageJob::class);
        (new AutoSendWhatsappAiMessageJob($pendiente->id, $pendiente->ai_auto_send_token))
            ->handle(app(WhatsappAiAutoSendScheduler::class));
        $this->assertSame('enviado', $pendiente->fresh()->ai_status);

        Queue::fake();
        $this->putJson('/api/whatsapp-chats/'.$id.'/ai-mode', ['ai_mode' => 'semi_automatic'])->assertOk();
        $this->postJson($ruta_simulacion, $pregunta)->assertCreated();
        (new GenerateWhatsappAiReplyJob($id, $chat->fresh()->ai_schedule_token))->handle(app(WhatsappAgentScheduler::class));
        Queue::assertNotPushed(AutoSendWhatsappAiMessageJob::class);

        $pendiente = $chat->messages()->where('ai_status', 'a_confirmar')->firstOrFail();
        $this->assertNull($pendiente->ai_auto_send_at);
        $ruta_pendiente = '/api/whatsapp-chats/messages/'.$pendiente->id;
        $this->putJson($ruta_pendiente, ['body' => 'Tenemos tuercas nuevas.'])->assertOk();
        $this->putJson($ruta_pendiente.'/confirm')->assertOk();
        $this->assertSame('Tenemos tuercas nuevas.', $pendiente->fresh()->body);
        $this->assertSame('enviado', $pendiente->fresh()->ai_status);

        Queue::fake();
        $this->putJson('/api/whatsapp-chats/'.$id.'/ai-mode', ['ai_mode' => 'manual'])->assertOk();
        $this->postJson($ruta_simulacion, $pregunta)->assertCreated();
        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);
        $this->assertFalse($chat->messages()->where('ai_status', 'a_confirmar')->exists());

        Http::assertNotSent(function (Request $request) {
            return strpos($request->url(), 'api.openai.com/') === false && strpos($request->url(), 'https://anthropic.test/') !== 0;
        });
    }

    public function test_cambiar_a_semi_invalida_el_envio_automatico_ya_encolado(): void
    {
        $id = $this->postJson('/api/whatsapp-chats', ['is_test' => true])->assertCreated()->json('model.id');
        $this->postJson('/api/whatsapp-bot/simulate-inbound', ['chat_id' => $id, 'body' => self::PREGUNTA])->assertCreated();
        $chat = WhatsappChat::findOrFail($id);
        (new GenerateWhatsappAiReplyJob($id, $chat->ai_schedule_token))->handle(app(WhatsappAgentScheduler::class));
        $pendiente = $chat->messages()->where('ai_status', 'a_confirmar')->firstOrFail();
        Queue::assertPushed(AutoSendWhatsappAiMessageJob::class);

        $this->putJson('/api/whatsapp-chats/'.$id.'/ai-mode', ['ai_mode' => 'semi_automatic'])->assertOk();
        (new AutoSendWhatsappAiMessageJob($pendiente->id, $pendiente->ai_auto_send_token))
            ->handle(app(WhatsappAiAutoSendScheduler::class));

        $this->assertSame('a_confirmar', $pendiente->fresh()->ai_status);
        $this->deleteJson('/api/whatsapp-chats/messages/'.$pendiente->id)->assertOk();
        $this->assertNull($pendiente->fresh());
    }

    public function test_cliente_vinculado_conserva_un_chat_real_separado_del_chat_de_prueba(): void
    {
        $cliente_ajeno = Client::forceCreate(['name' => 'Otro comercio', 'user_id' => $this->crear_usuario()->id]);
        $this->postJson('/api/whatsapp-chats', ['is_test' => true, 'client_id' => $cliente_ajeno->id])->assertStatus(422);

        $cliente = Client::forceCreate(['name' => 'Cliente del negocio', 'user_id' => $this->dueno->id, 'phone' => '5493416001234']);
        $id_prueba = $this->postJson('/api/whatsapp-chats', ['is_test' => true, 'client_id' => $cliente->id])
            ->assertCreated()->assertJsonPath('model.client_id', $cliente->id)->json('model.id');
        $this->assertNull(RecordatorioCobroSenderService::buscar_chat($this->dueno->id, $cliente));

        $id_real = $this->postJson('/api/whatsapp-chats', ['phone' => $cliente->phone, 'client_id' => $cliente->id])
            ->assertCreated()->assertJsonPath('model.is_test', false)->json('model.id');
        $this->assertNotSame($id_prueba, $id_real);
        $this->assertSame($id_real, RecordatorioCobroSenderService::buscar_chat($this->dueno->id, $cliente)->id);
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

    public function test_el_limite_de_treinta_consultas_se_acumula_en_distintos_minutos(): void
    {
        $this->crear_articulo_indexado($this->dueno, 'Tuerca para estantes');
        $inicio = now();

        try {
            for ($tanda = 0; $tanda < 3; $tanda++) {
                $this->travelTo($inicio->copy()->addSeconds($tanda * 61));
                for ($intento = 0; $intento < 10; $intento++) {
                    $this->postJson(self::RUTA, ['pregunta' => self::PREGUNTA])->assertOk();
                }
            }

            $this->travelTo($inicio->copy()->addSeconds(183));
            $this->postJson(self::RUTA, ['pregunta' => self::PREGUNTA])
                ->assertStatus(429)
                ->assertHeader('Retry-After')
                ->assertJsonPath('message', 'Alcanzaste el límite de 30 consultas en 24 horas para el simulador. Podés volver a probar cuando se renueve el cupo.');

            Http::assertSentCount(60);
            $this->travelTo($inicio->copy()->addDay()->addSecond());
            $this->postJson(self::RUTA, ['pregunta' => self::PREGUNTA])->assertOk();
        } finally {
            $this->travelBack();
        }
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
