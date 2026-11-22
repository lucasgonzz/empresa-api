<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;
use Tests\TestCase;

/**
 * Misión asistente-reacciones-wa (22/11/2026) — las reacciones (👍 / 👎 / 😡 …) del CLIENTE FINAL a las
 * respuestas del agente de WhatsApp del comercio.
 *
 * Pedido de Lucas: «quiero que los usuarios puedan reaccionar por WhatsApp a los mensajes del asistente, y si
 * reaccionan con un pulgar para abajo, con una cara enojada, quiero que analices ese mensaje para ver por qué no
 * les gustó y lo mejores». Acá se prueba el agente que atiende a los clientes del comercio (webhook de Kapso
 * `whatsapp-bot/webhook`); el asistente personal del dueño tiene su propio archivo en AsistenteWhatsapp.
 *
 * Lo que protege este archivo, en orden de importancia:
 *
 * 🔴 UNA REACCIÓN NUNCA ES UN MENSAJE. Hoy un `type: reaction` se descarta en silencio, pero el formato legado de
 * Kapso (`type: text` con el cuerpo «Reacted with 👎 to message wamid.…») se guarda como entrante y DISPARA AL
 * AGENTE: el cliente reacciona con un pulgar y el agente le contesta. Los casos de CA7 y CA9 lo denuncian.
 * - Que la valoración caiga SÓLO en una respuesta del agente (`source = ia`) del chat de ESE teléfono dentro del
 *   comercio de la configuración: la de un empleado (`manual`), la de otro teléfono y la de otro comercio no.
 * - Que quitar la reacción o cambiarla por una neutra (😂) limpie la valoración: WhatsApp admite una sola
 *   reacción por persona y mensaje.
 *
 * 🔴 UN SOLO `Http::fake()` POR MÉTODO y con el stub `'*'` al final (Laravel 8 acumula stubs y una request sin
 * stub sale a la red de verdad).
 */
class Reacciones_al_agente_Test extends TestCase
{
    use DatabaseTransactions;
    use OtorgaAgentesIa;

    /** Teléfono del cliente final que reacciona. */
    const PHONE = '5493415551234';

    /** Secreto del webhook con el que se firman los payloads. */
    const SECRET = 'secreto-webhook-reacciones';

    /** Wamid de la respuesta del agente a la que se reacciona en casi todos los casos. */
    const WAMID_SALIENTE = 'wamid.OUT1';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat Chat del cliente PHONE dentro del comercio. */
    protected $chat;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio reacciones',
            'company_name' => 'Ferretería reacciones',
            'email'        => 'reacciones-agente-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otorgar_agentes_ia($this->comercio);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-reacciones',
            'phone_number_id'    => '5491100000035',
            'webhook_secret'     => self::SECRET,
            'is_active'          => true,
            // Agente apagado: acá se mide la valoración, no la respuesta (salvo en los casos que lo prenden).
            'ai_enabled_default' => false,
        ]);

        $this->chat = $this->chat_de($this->comercio, self::PHONE);
    }

    /**
     * Un chat de un comercio con un teléfono.
     *
     * @param  User  $comercio
     * @param  string  $telefono
     * @return WhatsappChat
     */
    protected function chat_de(User $comercio, $telefono)
    {
        return WhatsappChat::create([
            'user_id'         => $comercio->id,
            'phone'           => $telefono,
            'ai_enabled'      => false,
            'unread_count'    => 0,
            'last_message_at' => now()->subHour(),
            'last_inbound_at' => now()->subHour(),
        ]);
    }

    /**
     * Un saliente en un chat con su wamid.
     *
     * @param  WhatsappChat  $chat
     * @param  string  $wamid
     * @param  string  $source  ia | manual | plantilla.
     * @return WhatsappChatMessage
     */
    protected function saliente(WhatsappChat $chat, $wamid, $source = 'ia')
    {
        return WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => $source,
            'body'             => 'Respuesta del agente.',
            'wa_message_id'    => $wamid,
            'delivery_status'  => 'entregado',
        ]);
    }

    /**
     * Postea un payload firmado con HMAC-SHA256, igual que Kapso.
     *
     * @param  array  $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_payload(array $payload)
    {
        $firma = hash_hmac('sha256', json_encode($payload), self::SECRET);

        return $this->postJson('api/whatsapp-bot/webhook', $payload, [
            'X-Webhook-Event'   => 'whatsapp.message.received',
            'X-Kapso-Signature' => $firma,
        ]);
    }

    /**
     * Postea una reacción en el formato nativo de Kapso (`type: reaction`).
     *
     * @param  string  $emoji  Emoji tal cual; '' = quitó la reacción.
     * @param  string  $wamid_reaccionado
     * @param  string  $desde  Teléfono de quien reacciona.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function reaccionar($emoji, $wamid_reaccionado = self::WAMID_SALIENTE, $desde = self::PHONE)
    {
        return $this->postear_payload([
            'message' => [
                'id'        => 'wamid.REACT-' . uniqid(),
                'from'      => $desde,
                'timestamp' => '1763790000',
                'type'      => 'reaction',
                'reaction'  => ['message_id' => $wamid_reaccionado, 'emoji' => $emoji],
            ],
            'conversation' => ['phone_number' => '+' . $desde],
        ]);
    }

    /**
     * Postea una reacción en el formato legado de Kapso: texto plano «Reacted with … to message …».
     *
     * @param  string  $cuerpo
     * @return \Illuminate\Testing\TestResponse
     */
    protected function reaccion_legada($cuerpo)
    {
        return $this->postear_payload([
            'message' => [
                'id'   => 'wamid.REACT-' . uniqid(),
                'from' => self::PHONE,
                'type' => 'text',
                'text' => ['body' => $cuerpo],
            ],
            'conversation' => ['phone_number' => '+' . self::PHONE],
        ]);
    }

    /**
     * Las tres columnas de la valoración de un mensaje, tal como están en la base.
     *
     * @param  WhatsappChatMessage  $mensaje
     * @return array
     */
    protected function valoracion_de(WhatsappChatMessage $mensaje)
    {
        $fresco = WhatsappChatMessage::find($mensaje->id);

        return [
            'valoracion'            => $fresco->valoracion,
            'valoracion_comentario' => $fresco->valoracion_comentario,
            'valorado_at'           => $fresco->valorado_at,
        ];
    }

    /**
     * CA1 — 👎 y 😡 (también con tono de piel) valoran «no me gusta» con el comentario exacto.
     *
     * @group whatsapp
     * @test
     */
    public function test_pulgar_abajo_y_cara_enojada_valoran_no_me_gusta()
    {
        $mensaje = $this->saliente($this->chat, self::WAMID_SALIENTE);

        foreach (['👎', '😡', '👎🏽'] as $emoji) {
            // Cada vuelta parte de la respuesta sin valorar: quitar la reacción es lo que la deja así.
            $this->reaccionar('')->assertStatus(200);

            $this->reaccionar($emoji)->assertStatus(200);

            $valoracion = $this->valoracion_de($mensaje);
            $this->assertSame('no_me_gusta', $valoracion['valoracion'], 'Con ' . $emoji);
            $this->assertSame('reacción ' . $emoji . ' por WhatsApp', $valoracion['valoracion_comentario']);
            $this->assertNotNull($valoracion['valorado_at'], 'Tiene que quedar la fecha de la valoración.');
        }
    }

    /**
     * CA2 — 👍 y ❤️ valoran «me gusta».
     *
     * @group whatsapp
     * @test
     */
    public function test_pulgar_arriba_y_corazon_valoran_me_gusta()
    {
        $mensaje = $this->saliente($this->chat, self::WAMID_SALIENTE);

        $this->reaccionar('👍')->assertStatus(200);
        $valoracion = $this->valoracion_de($mensaje);
        $this->assertSame('me_gusta', $valoracion['valoracion']);
        $this->assertSame('reacción 👍 por WhatsApp', $valoracion['valoracion_comentario']);

        $this->reaccionar('❤️')->assertStatus(200);
        $valoracion = $this->valoracion_de($mensaje);
        $this->assertSame('me_gusta', $valoracion['valoracion']);
        $this->assertSame('reacción ❤️ por WhatsApp', $valoracion['valoracion_comentario']);
        $this->assertNotNull($valoracion['valorado_at']);
    }

    /**
     * CA3 — quitar la reacción (emoji vacío) o cambiarla por una neutra (😂) limpia las tres columnas.
     *
     * @group whatsapp
     * @test
     */
    public function test_quitar_o_cambiar_a_neutra_limpia_la_valoracion()
    {
        $mensaje = $this->saliente($this->chat, self::WAMID_SALIENTE);

        $this->reaccionar('👎')->assertStatus(200);
        $this->assertSame('no_me_gusta', $this->valoracion_de($mensaje)['valoracion']);

        $this->reaccionar('')->assertStatus(200);
        $this->assertSame(
            ['valoracion' => null, 'valoracion_comentario' => null, 'valorado_at' => null],
            $this->valoracion_de($mensaje),
            'Quitar la reacción retira la valoración.'
        );

        $this->reaccionar('👎')->assertStatus(200);
        $this->assertSame('no_me_gusta', $this->valoracion_de($mensaje)['valoracion']);

        $this->reaccionar('😂')->assertStatus(200);
        $this->assertSame(
            ['valoracion' => null, 'valoracion_comentario' => null, 'valorado_at' => null],
            $this->valoracion_de($mensaje),
            'Cambiar el 👎 por una neutra es retirar el 👎 (una sola reacción por persona y mensaje).'
        );
    }

    /**
     * CA6 — una reacción a algo que no es una respuesta del agente a ESE cliente se ignora sin error:
     * wamid inexistente, saliente `manual`, saliente `ia` de un chat de OTRO teléfono y saliente `ia` de
     * un chat de OTRO comercio. En los cuatro casos 200 y ninguna fila cambia.
     *
     * @group whatsapp
     * @test
     */
    public function test_reaccion_a_mensaje_ajeno_o_desconocido_se_ignora()
    {
        $manual = $this->saliente($this->chat, 'wamid.MANUAL1', 'manual');

        $chat_de_otro_telefono = $this->chat_de($this->comercio, '5493410000999');
        $de_otro_telefono      = $this->saliente($chat_de_otro_telefono, 'wamid.OTROTEL1');

        $otro_comercio       = User::create([
            'name'     => 'Otro comercio', 'company_name' => 'Otro negocio',
            'email'    => 'reacciones-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $chat_de_otro_comercio = $this->chat_de($otro_comercio, self::PHONE);
        $de_otro_comercio      = $this->saliente($chat_de_otro_comercio, 'wamid.OTROCOM1');

        $antes = [
            $manual->id               => $this->valoracion_de($manual),
            $de_otro_telefono->id     => $this->valoracion_de($de_otro_telefono),
            $de_otro_comercio->id     => $this->valoracion_de($de_otro_comercio),
        ];
        $filas_antes = WhatsappChatMessage::count();

        // Wamid que no existe en ninguna parte.
        $this->reaccionar('👎', 'wamid.NOEXISTE')->assertStatus(200);
        // Un mensaje de un empleado: la valoración mide al agente, no a la persona.
        $this->reaccionar('👎', 'wamid.MANUAL1')->assertStatus(200);
        // Una respuesta del agente, pero a OTRO teléfono: sólo el cliente de ese chat puede valorarla.
        $this->reaccionar('👎', 'wamid.OTROTEL1')->assertStatus(200);
        // Una respuesta del agente de OTRO comercio (el config activo es el de este comercio).
        $this->reaccionar('👎', 'wamid.OTROCOM1')->assertStatus(200);

        foreach ($antes as $id => $valoracion) {
            $this->assertSame(
                $valoracion,
                $this->valoracion_de(WhatsappChatMessage::find($id)),
                'La fila ' . $id . ' no se puede haber tocado.'
            );
        }
        $this->assertSame($filas_antes, WhatsappChatMessage::count(), 'No nace ningún mensaje.');
    }

    /**
     * CA7 — una reacción no dispara al agente: no se despacha el job de respuesta, no se crea ningún mensaje
     * y el chat no cambia (`last_inbound_at`, `last_message_at`, `unread_count`). El chat tiene el agente
     * PRENDIDO a propósito: si la reacción entrara como entrante, el scheduler lo despacharía.
     *
     * @group whatsapp
     * @test
     */
    public function test_la_reaccion_no_crea_entrante_ni_agenda_al_agente()
    {
        Queue::fake();
        Http::fake(['*' => Http::response([], 200)]);

        $this->config->ai_enabled_default = true;
        $this->config->save();
        $this->chat->ai_enabled = true;
        $this->chat->save();

        $this->saliente($this->chat, self::WAMID_SALIENTE);

        $chat_antes = WhatsappChat::find($this->chat->id);
        $filas      = WhatsappChatMessage::where('whatsapp_chat_id', $this->chat->id)->count();

        foreach (['👎', '👍', '😂', ''] as $emoji) {
            $this->reaccionar($emoji)->assertStatus(200);
        }
        // Una reacción a algo desconocido tampoco.
        $this->reaccionar('👎', 'wamid.NOEXISTE')->assertStatus(200);

        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(
            $filas,
            WhatsappChatMessage::where('whatsapp_chat_id', $this->chat->id)->count(),
            'Una reacción no es un mensaje: no puede crear ninguna fila.'
        );

        $chat_despues = WhatsappChat::find($this->chat->id);
        $this->assertEquals($chat_antes->last_inbound_at, $chat_despues->last_inbound_at);
        $this->assertEquals($chat_antes->last_message_at, $chat_despues->last_message_at);
        $this->assertSame((int) $chat_antes->unread_count, (int) $chat_despues->unread_count);
    }

    /**
     * CA9 — el formato legado de Kapso («Reacted with 👎 to message wamid.OUT1») se trata como reacción: se
     * valora igual que en CA1 y NO entra como mensaje del cliente ni agenda al agente. «Removed reaction from
     * message …» la quita.
     *
     * @group whatsapp
     * @test
     */
    public function test_formato_legado_de_kapso_se_trata_como_reaccion()
    {
        Queue::fake();
        Http::fake(['*' => Http::response([], 200)]);

        // Con el agente prendido: el texto legado sería hoy un entrante que dispara la respuesta.
        $this->config->ai_enabled_default = true;
        $this->config->save();
        $this->chat->ai_enabled = true;
        $this->chat->save();

        $mensaje = $this->saliente($this->chat, self::WAMID_SALIENTE);
        $filas   = WhatsappChatMessage::where('whatsapp_chat_id', $this->chat->id)->count();

        $this->reaccion_legada('Reacted with 👎 to message wamid.OUT1')->assertStatus(200);

        $valoracion = $this->valoracion_de($mensaje);
        $this->assertSame('no_me_gusta', $valoracion['valoracion']);
        $this->assertSame('reacción 👎 por WhatsApp', $valoracion['valoracion_comentario']);

        $this->reaccion_legada('Removed reaction from message wamid.OUT1')->assertStatus(200);
        $this->assertSame(
            ['valoracion' => null, 'valoracion_comentario' => null, 'valorado_at' => null],
            $this->valoracion_de($mensaje)
        );

        $this->assertSame(
            $filas,
            WhatsappChatMessage::where('whatsapp_chat_id', $this->chat->id)->count(),
            'El texto legado de una reacción no se guarda como mensaje entrante.'
        );
        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);
    }

    /**
     * CA10 — el saliente del agente guarda su wamid y por eso se lo puede valorar. Se hace el recorrido real:
     * entra un mensaje del cliente por el webhook, el agente contesta (Anthropic y Kapso fakeados, Kapso
     * devuelve `wamid.OUT9`) y después el cliente reacciona a ese wamid.
     *
     * La primera mitad (el wamid queda guardado) ya pasa hoy y es guarda de regresión; la segunda falla hoy.
     *
     * @group whatsapp
     * @test
     */
    public function test_el_saliente_del_agente_guarda_su_wamid_y_se_puede_valorar()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.openai.com/*'    => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => Http::response([
                'model'   => 'claude-de-prueba',
                'content' => [['type' => 'text', 'text' => 'Sí, tenemos.']],
                'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
            ], 200),
            'api.kapso.ai/*'      => Http::response(['messages' => [['id' => 'wamid.OUT9']]], 200),
            '*'                   => Http::response([], 200),
        ]);

        $this->config->ai_enabled_default = true;
        $this->config->save();

        // Chat nuevo (con el agente prendido por defecto): se borra el del setUp para que lo cree el webhook.
        WhatsappChat::where('id', $this->chat->id)->delete();

        $this->postear_payload([
            'message' => [
                'id'   => 'wamid.ENTRANTE9',
                'from' => self::PHONE,
                'type' => 'text',
                'text' => ['body' => 'hola, ¿tenés tornillos?'],
            ],
        ])->assertStatus(200);

        $chat      = WhatsappChat::where('user_id', $this->comercio->id)->where('phone', self::PHONE)->first();
        $respuesta = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')->where('source', 'ia')->first();

        $this->assertNotNull($respuesta, 'El agente tendría que haber contestado.');
        $this->assertSame('wamid.OUT9', $respuesta->wa_message_id, 'El saliente guarda el wamid que devolvió Kapso.');

        $this->reaccionar('👎', 'wamid.OUT9')->assertStatus(200);

        $valoracion = $this->valoracion_de($respuesta);
        $this->assertSame('no_me_gusta', $valoracion['valoracion']);
        $this->assertSame('reacción 👎 por WhatsApp', $valoracion['valoracion_comentario']);
    }

    /**
     * CA12 — sin la extensión `agentes_ia` el webhook contesta 200 y no procesa nada (igual que hoy).
     *
     * @group whatsapp
     * @test
     */
    public function test_sin_extension_no_procesa_la_reaccion()
    {
        $mensaje = $this->saliente($this->chat, self::WAMID_SALIENTE);

        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');

        $this->reaccionar('👎')->assertStatus(200);

        $this->assertSame(
            ['valoracion' => null, 'valoracion_comentario' => null, 'valorado_at' => null],
            $this->valoracion_de($mensaje),
            'Sin la extensión no se valora nada.'
        );
    }
}
