<?php

namespace Tests\Feature\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Jobs\AutoSendWhatsappAiMessageJob;
use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAiAutoSendScheduler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;
use Tests\TestCase;

/**
 * Misión whatsapp-canal-independiente (27/11/2026) — «apagar el agente no apaga el canal».
 *
 * Síntoma (Truvari): un mensaje manual llegó al teléfono pero quedó `pendiente` en la pantalla y la respuesta
 * del cliente no apareció. Causa: `WhatsappBotController::receive()` elegía la config con `is_active = true` y,
 * sin fila activa, contestaba 200 ANTES de validar la firma y descartaba todo (entrantes, estados, ecos, reacciones).
 *
 * Lo que fija este archivo:
 *
 * 🔴 `is_active` decide sólo si EL AGENTE genera y manda solo. El canal (persistir entrantes y estados, emitir
 * `WhatsappChatUpdated`) funciona siempre que la firma sea válida.
 * 🔴 La config se resuelve por `phone_number_id` (raíz o `conversation`), con fallback sólo cuando no es ambiguo.
 * 🔴 Un `webhook_secret` vacío NUNCA autentica (una firma calculada con clave vacía no vale).
 *
 * Todos los casos entran por el webhook firmado real. Un solo `Http::fake()` por método.
 */
class Canal_independiente_del_bot_Test extends TestCase
{
    use DatabaseTransactions;
    use OtorgaAgentesIa;

    const PHONE = '5493444622139';
    const PNID = '5491100000039';
    const PNID_B = '5491100000040';
    const SECRET = 'secreto-canal-39';
    const SECRET_B = 'secreto-canal-39-b';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig Config del comercio A: agente APAGADO. */
    protected $config;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = $this->crear_comercio('A');
        $this->config = $this->crear_config($this->comercio, self::PNID, self::SECRET, false);
    }

    // ------------------------------------------------------------------------------------------------ helpers

    protected function crear_comercio($etiqueta)
    {
        $comercio = User::create([
            'name'         => 'Comercio canal ' . $etiqueta,
            'company_name' => 'Ferretería canal ' . $etiqueta,
            'email'        => 'canal-39-' . strtolower($etiqueta) . '-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
        $this->otorgar_agentes_ia($comercio);

        return $comercio;
    }

    protected function crear_config(User $comercio, $pnid, $secreto, $activo)
    {
        return WhatsappBotConfig::create([
            'user_id'            => $comercio->id,
            'kapso_api_key'      => 'kapso-canal-39',
            'phone_number_id'    => $pnid,
            'webhook_secret'     => $secreto,
            'is_active'          => $activo,
            'ai_enabled_default' => true,
            // Con demora 0 el scheduler corre el job en sync y `Queue::fake()` no lo ve: con demora > 0 va a la cola.
            'ai_reply_delay_seconds' => 30,
        ]);
    }

    /** Eventos y cola registrados (no salen), y red falsa. */
    protected function fakes()
    {
        Event::fake([WhatsappChatUpdated::class]);
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    protected function payload_entrante($texto = 'FT-PRUEBA-39', $wamid = 'wamid.IN39', array $raiz = [])
    {
        $payload = array_merge([
            'message' => [
                'id'        => $wamid,
                'from'      => self::PHONE,
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => $texto],
            ],
            'conversation'    => ['phone_number' => '+' . self::PHONE],
            'phone_number_id' => self::PNID,
        ], $raiz);
        foreach ($payload as $clave => $valor) {
            if (is_null($valor)) {
                unset($payload[$clave]);
            }
        }

        return $payload;
    }

    protected function payload_estado($wamid, $pnid = self::PNID, array $extra = [])
    {
        $payload = [
            'message' => array_merge([
                'id'   => $wamid,
                'to'   => self::PHONE,
                'type' => 'text',
            ], $extra),
            'conversation' => ['phone_number' => '+' . self::PHONE],
        ];
        if (! is_null($pnid)) {
            $payload['phone_number_id'] = $pnid;
        }

        return $payload;
    }

    protected function post_firmado($evento, array $payload, $secreto = self::SECRET)
    {
        $firma = hash_hmac('sha256', json_encode($payload), $secreto);

        return $this->postJson('api/whatsapp-bot/webhook', $payload, [
            'X-Webhook-Event'   => $evento,
            'X-Kapso-Signature' => $firma,
        ]);
    }

    protected function chat_de(User $comercio, array $extra = [])
    {
        return WhatsappChat::create(array_merge([
            'user_id'         => $comercio->id,
            'phone'           => self::PHONE,
            'ai_enabled'      => true,
            'unread_count'    => 0,
            'last_message_at' => now()->subHour(),
            'last_inbound_at' => now()->subHour(),
        ], $extra));
    }

    protected function saliente(WhatsappChat $chat, $wamid, $source = 'plantilla', $estado = 'pendiente', array $extra = [])
    {
        return WhatsappChatMessage::create(array_merge([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => $source,
            'body'             => 'Mensaje manual de prueba.',
            'wa_message_id'    => $wamid,
            'delivery_status'  => $estado,
        ], $extra));
    }

    protected function chats_del_telefono(User $comercio)
    {
        return WhatsappChat::where('user_id', $comercio->id)->where('phone', self::PHONE)->get();
    }

    protected function mensajes_in()
    {
        return WhatsappChatMessage::whereIn('whatsapp_chat_id', WhatsappChat::where('phone', self::PHONE)->pluck('id'))
            ->where('direction', 'in')->count();
    }

    // ------------------------------------------------------------------------------------------------ CA1

    /**
     * @group whatsapp
     * @test
     */
    public function entrante_con_bot_apagado_se_guarda_y_emite()
    {
        $this->fakes();

        $this->post_firmado('whatsapp.message.received', $this->payload_entrante())
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        $chats = $this->chats_del_telefono($this->comercio);
        $this->assertCount(1, $chats, 'Con el agente apagado el entrante igual tiene que crear el chat.');
        $chat = $chats->first();
        $this->assertSame(1, (int) $chat->unread_count);
        $this->assertNotNull($chat->last_inbound_at);

        $mensajes = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->get();
        $this->assertCount(1, $mensajes);
        $mensaje = $mensajes->first();
        $this->assertSame('in', $mensaje->direction);
        $this->assertSame('cliente', $mensaje->source);
        $this->assertSame(0, (int) $mensaje->is_simulated);
        $this->assertSame('wamid.IN39', $mensaje->wa_message_id);

        Event::assertDispatchedTimes(WhatsappChatUpdated::class, 1);
        Event::assertDispatched(WhatsappChatUpdated::class, function ($evento) use ($chat, $mensaje) {
            return (int) $evento->owner_id === (int) $this->comercio->id
                && (int) $evento->chat->id === (int) $chat->id
                && ! is_null($evento->message)
                && (int) $evento->message->id === (int) $mensaje->id;
        });

        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------------------------------------ CA2

    /**
     * @group whatsapp
     * @test
     */
    public function estados_avanzan_con_bot_apagado_y_no_retroceden()
    {
        $this->fakes();
        $chat = $this->chat_de($this->comercio);
        $saliente = $this->saliente($chat, 'wamid.SAL39');

        $this->post_firmado('whatsapp.message.delivered', $this->payload_estado('wamid.SAL39'))->assertStatus(200);
        $this->assertSame('entregado', WhatsappChatMessage::find($saliente->id)->delivery_status);
        Event::assertDispatchedTimes(WhatsappChatUpdated::class, 1);
        Event::assertDispatched(WhatsappChatUpdated::class, function ($evento) use ($saliente) {
            return (int) $evento->owner_id === (int) $this->comercio->id
                && ! is_null($evento->message) && (int) $evento->message->id === (int) $saliente->id;
        });

        $this->post_firmado('whatsapp.message.read', $this->payload_estado('wamid.SAL39'))->assertStatus(200);
        $this->assertSame('leido', WhatsappChatMessage::find($saliente->id)->delivery_status);

        $this->post_firmado('whatsapp.message.delivered', $this->payload_estado('wamid.SAL39'))->assertStatus(200);
        $this->assertSame('leido', WhatsappChatMessage::find($saliente->id)->delivery_status, 'Un estado viejo no hace retroceder.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function failed_con_bot_apagado_guarda_motivo()
    {
        $this->fakes();
        $chat = $this->chat_de($this->comercio);
        $saliente = $this->saliente($chat, 'wamid.SAL39F');

        $this->post_firmado('whatsapp.message.failed', $this->payload_estado('wamid.SAL39F', self::PNID, [
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message', 'message' => 'Fuera de la ventana de 24 horas']],
        ]))->assertStatus(200);

        $fresco = WhatsappChatMessage::find($saliente->id);
        $this->assertSame('fallido', $fresco->delivery_status);
        $this->assertNotSame('', trim((string) $fresco->send_error));
    }

    // ------------------------------------------------------------------------------------------------ CA3

    /**
     * @group whatsapp
     * @test
     */
    public function firma_invalida_o_ausente_con_bot_apagado_es_401()
    {
        $this->fakes();
        $payload = $this->payload_entrante();

        $this->post_firmado('whatsapp.message.received', $payload, 'otro-secreto')->assertStatus(401);

        $this->postJson('api/whatsapp-bot/webhook', $payload, ['X-Webhook-Event' => 'whatsapp.message.received'])
            ->assertStatus(401);

        $this->assertCount(0, $this->chats_del_telefono($this->comercio));
        $this->assertSame(0, $this->mensajes_in());
        Event::assertNotDispatched(WhatsappChatUpdated::class);
        Queue::assertNothingPushed();
    }

    /**
     * @group whatsapp
     * @test
     */
    public function secreto_vacio_no_procesa()
    {
        $this->fakes();
        $this->config->webhook_secret = '';
        $this->config->save();

        // Firma calculada con clave vacía: si el secreto vacío autenticara, el entrante se guardaría.
        $this->post_firmado('whatsapp.message.received', $this->payload_entrante(), '')->assertStatus(200);

        $this->assertCount(0, $this->chats_del_telefono($this->comercio));
        $this->assertSame(0, $this->mensajes_in());
        Event::assertNotDispatched(WhatsappChatUpdated::class);
        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------------------------------------ CA4

    /**
     * @group whatsapp
     * @test
     */
    public function resuelve_por_phone_number_id()
    {
        $this->fakes();
        $comercio_b = $this->crear_comercio('B');
        $this->crear_config($comercio_b, self::PNID_B, self::SECRET_B, true);

        // A (inactivo) con su pnid y su secreto: se guarda en A, no en B (la única activa).
        $this->post_firmado('whatsapp.message.received', $this->payload_entrante())->assertStatus(200);
        $this->assertCount(1, $this->chats_del_telefono($this->comercio));
        $this->assertCount(0, $this->chats_del_telefono($comercio_b));
        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);

        // pnid de B firmado con el secreto de A: la config es B, la firma no le corresponde.
        $this->post_firmado('whatsapp.message.received', $this->payload_entrante('cruzado', 'wamid.CRUZ', ['phone_number_id' => self::PNID_B]))
            ->assertStatus(401);
        $this->assertCount(0, $this->chats_del_telefono($comercio_b));
        $this->assertSame(1, $this->mensajes_in());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function resuelve_por_phone_number_id_dentro_de_conversation()
    {
        $this->fakes();
        $comercio_b = $this->crear_comercio('B');
        $this->crear_config($comercio_b, self::PNID_B, self::SECRET_B, true);

        $payload = $this->payload_entrante('por conversation', 'wamid.CONV39', ['phone_number_id' => null]);
        $payload['conversation']['phone_number_id'] = self::PNID;

        $this->post_firmado('whatsapp.message.received', $payload)->assertStatus(200);

        $this->assertCount(1, $this->chats_del_telefono($this->comercio));
        $this->assertCount(0, $this->chats_del_telefono($comercio_b));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function pnid_desconocido_con_varias_filas_descarta()
    {
        $this->fakes();
        $comercio_b = $this->crear_comercio('B');
        $this->crear_config($comercio_b, self::PNID_B, self::SECRET_B, true);

        // Con dos filas y un pnid que no es de ninguna, no se adivina: ni con el secreto de A ni con el de B.
        $this->post_firmado('whatsapp.message.received', $this->payload_entrante('x', 'wamid.X1', ['phone_number_id' => '999']))->assertStatus(200);
        $this->post_firmado('whatsapp.message.received', $this->payload_entrante('y', 'wamid.X2', ['phone_number_id' => '999']), self::SECRET_B)->assertStatus(200);

        $this->assertCount(0, $this->chats_del_telefono($this->comercio));
        $this->assertCount(0, $this->chats_del_telefono($comercio_b));
        $this->assertSame(0, $this->mensajes_in());
        Event::assertNotDispatched(WhatsappChatUpdated::class);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function sin_pnid_y_una_fila_usa_esa()
    {
        $this->fakes();

        $this->post_firmado('whatsapp.message.received', $this->payload_entrante('sin pnid', 'wamid.SINPNID', ['phone_number_id' => null]))
            ->assertStatus(200);

        $this->assertCount(1, $this->chats_del_telefono($this->comercio));
        $this->assertSame(1, $this->mensajes_in());
        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function sin_pnid_y_varias_filas_usa_la_unica_activa()
    {
        $this->fakes();
        $comercio_b = $this->crear_comercio('B');
        $this->crear_config($comercio_b, self::PNID_B, self::SECRET_B, true);

        // Payload sin pnid, firmado con el secreto de B (la única activa): se guarda en B.
        $this->post_firmado('whatsapp.message.received', $this->payload_entrante('sin pnid', 'wamid.SINPNID2', ['phone_number_id' => null]), self::SECRET_B)
            ->assertStatus(200);

        $this->assertCount(1, $this->chats_del_telefono($comercio_b));
        $this->assertCount(0, $this->chats_del_telefono($this->comercio));
    }

    // ------------------------------------------------------------------------------------------------ CA5

    /**
     * @group whatsapp
     * @test
     */
    public function bot_apagado_no_responde_aunque_el_chat_tenga_ia()
    {
        $this->fakes();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->config->ai_reply_delay_seconds = 0; // camino sync/afterResponse
        $this->config->save();

        $chat = $this->chat_de($this->comercio, ['ai_enabled' => true]);
        $token_antes = (int) WhatsappChat::find($chat->id)->ai_schedule_token;

        $this->post_firmado('whatsapp.message.received', $this->payload_entrante('hola, ¿tienen pintura?', 'wamid.IA39'))->assertStatus(200);

        $this->assertSame(1, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('direction', 'in')->count(), 'El entrante tiene que guardarse.');
        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('direction', 'out')->count());
        $this->assertSame($token_antes, (int) WhatsappChat::find($chat->id)->ai_schedule_token);
        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);
        Http::assertNothingSent();
    }

    /**
     * @group whatsapp
     * @test
     */
    public function autoenvio_no_sale_con_bot_apagado()
    {
        $this->fakes();
        $chat = $this->chat_de($this->comercio, ['ai_enabled' => true]);
        $mensaje = $this->saliente($chat, null, 'ia', 'pendiente', ['ai_status' => 'a_confirmar', 'body' => 'Respuesta que iba a salir sola.']);

        $this->config->ai_confirm_delay_seconds = 120;
        $this->config->save();
        (new WhatsappAiAutoSendScheduler())->schedule_for_message($mensaje, $this->config);

        (new AutoSendWhatsappAiMessageJob((int) $mensaje->id, 1))->handle(app(WhatsappAiAutoSendScheduler::class));

        $fresco = WhatsappChatMessage::find($mensaje->id);
        $this->assertSame('a_confirmar', $fresco->ai_status);
        $this->assertStringContainsString('No se envió automáticamente', (string) $fresco->send_error);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------------------------------------ CA6

    /**
     * @group whatsapp
     * @test
     */
    public function bot_prendido_sigue_agendando_al_agente()
    {
        $this->fakes();
        $this->config->is_active = true;
        $this->config->save();

        $this->post_firmado('whatsapp.message.received', $this->payload_entrante('hola', 'wamid.ON39'))->assertStatus(200);

        $this->assertCount(1, $this->chats_del_telefono($this->comercio));
        Queue::assertPushed(GenerateWhatsappAiReplyJob::class, 1);
        Event::assertDispatched(WhatsappChatUpdated::class);
    }

    // ------------------------------------------------------------------------------------------------ CA7

    /**
     * @group whatsapp
     * @test
     */
    public function eco_y_reaccion_se_registran_con_bot_apagado()
    {
        $this->fakes();
        $chat = $this->chat_de($this->comercio);
        $respuesta = $this->saliente($chat, 'wamid.IA-REACT', 'ia', 'entregado');

        // Eco de lo que el dueño escribió desde el celular.
        $eco = [
            'message' => [
                'id'        => 'wamid.ECO39',
                'to'        => self::PHONE,
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Te lo guardo hasta el sábado'],
                'kapso'     => ['direction' => 'outbound', 'origin' => 'business_app'],
            ],
            'conversation'    => ['phone_number' => '+' . self::PHONE],
            'phone_number_id' => self::PNID,
        ];
        $this->post_firmado('whatsapp.message.sent', $eco)->assertStatus(200);
        $this->assertSame(1, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('wa_message_id', 'wamid.ECO39')->where('direction', 'out')->count());

        // Reacción 👍 del cliente a la respuesta del agente.
        $reaccion = [
            'message' => [
                'id'        => 'wamid.REACT39',
                'from'      => self::PHONE,
                'timestamp' => (string) time(),
                'type'      => 'reaction',
                'reaction'  => ['message_id' => 'wamid.IA-REACT', 'emoji' => '👍'],
            ],
            'conversation'    => ['phone_number' => '+' . self::PHONE],
            'phone_number_id' => self::PNID,
        ];
        $this->post_firmado('whatsapp.message.received', $reaccion)->assertStatus(200);

        $this->assertSame('me_gusta', WhatsappChatMessage::find($respuesta->id)->valoracion, 'La reacción tiene que quedar en la respuesta del agente.');
        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('direction', 'in')->count(), 'Una reacción no es un entrante.');
        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);
    }
}
