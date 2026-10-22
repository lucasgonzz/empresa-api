<?php

namespace Tests\Feature\AgentesIa;

use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Support\Facades\Queue;

/**
 * Misión agentes-ia-extension-unica (22/10/2026) — el webhook de Kapso (`POST whatsapp-bot/webhook`).
 *
 * Es una ruta SIN sesión: Kapso no manda un token. El dueño se resuelve por la configuración activa del bot
 * (no por `Auth`). Lo que protege:
 *
 *  - Sin la extensión, el webhook responde **200 sin procesar**: no persiste chat ni mensaje y no encola ningún
 *    job (Kapso reintenta ante cualquier otro código; un 403 o un 404 lo dejaría reintentando para siempre).
 *  - Con la extensión, el comportamiento es el de siempre: persiste el chat y agenda al agente.
 *  - La firma HMAC inválida sigue contestando 401 (con o sin extensión): la extensión se mira DESPUÉS de
 *    autenticar a Kapso.
 *  - Los eventos de estado de entrega (enviado/leído) también se descartan sin la extensión.
 */
class Webhook_de_kapso_Test extends AgentesIaTestCase
{
    /** Teléfono del cliente final. */
    const TELEFONO = '5493416007788';

    /** Secreto del webhook con el que se firman los payloads. */
    const SECRETO = 'secreto-webhook-agentes-ia';

    /** @var WhatsappBotConfig */
    protected $config_del_bot;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        /* Demora larga: el agente se agenda en la cola (fakeada) y no corre inline después de la respuesta. */
        $this->config_del_bot = WhatsappBotConfig::create([
            'user_id'                => $this->comercio()->id,
            'kapso_api_key'          => 'kapso-agentes-ia',
            'phone_number_id'        => '5491100000099',
            'webhook_secret'         => self::SECRETO,
            'is_active'              => true,
            'ai_enabled_default'     => true,
            'ai_reply_delay_seconds' => 30,
        ]);
    }

    /**
     * Postea un mensaje entrante firmado con HMAC-SHA256, igual que Kapso.
     *
     * @param  string       $texto
     * @param  string|null  $secreto  null = el secreto real.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_mensaje($texto, $secreto = null)
    {
        $payload = [
            'message' => [
                'from' => self::TELEFONO,
                'type' => 'text',
                'text' => ['body' => $texto],
            ],
        ];

        $firma = hash_hmac('sha256', json_encode($payload), $secreto ?: self::SECRETO);

        return $this->postJson('api/whatsapp-bot/webhook', $payload, ['X-Kapso-Signature' => $firma]);
    }

    /**
     * @return int
     */
    protected function chats_de_este_cliente()
    {
        return WhatsappChat::where('user_id', $this->comercio()->id)->count();
    }

    /**
     * @test
     * @return void
     */
    public function sin_la_extension_responde_200_sin_procesar_ni_encolar()
    {
        $this->quitar_extension();

        $antes = $this->chats_de_este_cliente();

        $this->postear_mensaje('Hola, ¿tienen martillos?')
             ->assertStatus(200)
             ->assertJson(['ok' => true]);

        $this->assertSame($antes, $this->chats_de_este_cliente(), 'El webhook sin la extensión persistió un chat.');
        $this->assertSame(0, WhatsappChatMessage::where('body', 'Hola, ¿tienen martillos?')->count(), 'El webhook sin la extensión persistió el mensaje.');

        Queue::assertNothingPushed();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /**
     * @test
     * @return void
     */
    public function con_la_extension_procesa_el_mensaje_y_agenda_al_agente()
    {
        $this->dar_extension();

        $antes = $this->chats_de_este_cliente();

        $this->postear_mensaje('Hola, ¿tienen taladros?')
             ->assertStatus(200)
             ->assertJson(['ok' => true]);

        $this->assertSame($antes + 1, $this->chats_de_este_cliente(), 'El webhook con la extensión no persistió el chat.');
        $this->assertSame(1, WhatsappChatMessage::where('body', 'Hola, ¿tienen taladros?')->count());

        Queue::assertPushed(GenerateWhatsappAiReplyJob::class, 1);
    }

    /**
     * La firma inválida sigue siendo 401, con la extensión y sin ella: no se revela nada de la extensión a quien
     * no es Kapso.
     *
     * @test
     * @return void
     */
    public function la_firma_invalida_sigue_contestando_401_con_y_sin_la_extension()
    {
        $this->quitar_extension();
        $this->postear_mensaje('Mensaje con firma falsa', 'otro-secreto')->assertStatus(401);

        $this->dar_extension();
        $this->postear_mensaje('Mensaje con firma falsa', 'otro-secreto')->assertStatus(401);

        Queue::assertNothingPushed();
    }

    /**
     * Los estados de entrega (enviado, entregado, leído) también se descartan sin la extensión: el módulo de
     * WhatsApp entero es de `agentes_ia`.
     *
     * @test
     * @return void
     */
    public function los_eventos_de_estado_de_entrega_se_descartan_sin_la_extension()
    {
        $this->quitar_extension();

        $chat = WhatsappChat::create([
            'user_id'    => $this->comercio()->id,
            'phone'      => self::TELEFONO,
            'ai_enabled' => true,
        ]);

        $mensaje = WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => 'operador',
            'body'             => 'Ya te lo mandamos',
            'wa_message_id'    => 'wamid.AGENTESIA1',
            'delivery_status'  => 'enviado',
        ]);

        $payload = [
            'event'   => 'whatsapp.message.read',
            'message' => ['id' => 'wamid.AGENTESIA1'],
        ];

        $firma = hash_hmac('sha256', json_encode($payload), self::SECRETO);

        /* Control: CON la extensión el estado de entrega sí avanza (si no, el test no mediría nada). */
        $this->dar_extension();

        $this->postJson('api/whatsapp-bot/webhook', $payload, [
            'X-Kapso-Signature' => $firma,
            'X-Webhook-Event'   => 'whatsapp.message.read',
        ])->assertStatus(200);

        $this->assertSame('leido', $mensaje->fresh()->delivery_status, 'Con la extensión el estado de entrega tiene que procesarse.');

        /* Sin la extensión: se vuelve a «enviado» y el mismo evento se descarta. */
        /* update directo: el modelo en memoria se quedó con 'enviado' y un save() no vería nada sucio. */
        WhatsappChatMessage::where('id', $mensaje->id)->update(['delivery_status' => 'enviado']);

        $this->quitar_extension();

        $this->postJson('api/whatsapp-bot/webhook', $payload, [
            'X-Kapso-Signature' => $firma,
            'X-Webhook-Event'   => 'whatsapp.message.read',
        ])->assertStatus(200);

        $this->assertSame('enviado', $mensaje->fresh()->delivery_status, 'El estado de entrega se procesó sin la extensión.');
    }
}
