<?php

namespace Tests\Feature\Whatsapp;

use App\Exceptions\SaleWhatsappSendException;
use App\Jobs\SendSaleWhatsappJob;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTemplate;
use App\Services\SaleWhatsappSenderService;
use App\Services\WhatsappBotSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/9: el comprobante de venta y el freno de simulación.
 *
 * Este archivo nace de un bloqueante del revisor de merge, y cubre DOS cosas distintas que
 * el resto de la suite no miraba:
 *
 * 1. QUE EL FRENO DE SIMULACIÓN NO SE FILTRE AL COMPROBANTE. El freno vive en
 *    `WhatsappBotSendService::send_text()` y tiene que quedarse ahí. Estuvo también en
 *    `send_document()`, que parece del módulo de WhatsApp pero su único caller es
 *    `SaleWhatsappSenderService`: con el freno puesto ahí, si el dueño simulaba un mensaje
 *    sobre el teléfono de un cliente real (el modal de simulación tiene un buscador de
 *    clientes), CADA venta a ese cliente generaba un comprobante que nunca salía, con un log
 *    que afirmaba que había salido. El vendedor no veía nada: la pantalla de ventas no sabe
 *    nada de la simulación.
 *
 * 2. QUE UN FALLO DE KAPSO NO SE DÉ POR ENVIADO. Esto es un bug PREEXISTENTE y más grande
 *    que la simulación, que solo lo hizo alcanzable más seguido: los tres métodos de
 *    `WhatsappBotSendService` se tragan sus errores y devuelven null, y
 *    `SaleWhatsappSenderService` no miraba ese null. O sea que con la clave de Kapso vencida
 *    (o el servicio caído, o rate limit) el comprobante se persistía como enviado y
 *    `SendSaleWhatsappJob` logueaba 'comprobante enviado automáticamente' sobre un envío que
 *    nunca ocurrió. El cliente se quedaba sin comprobante y nadie se enteraba.
 *
 * 🔴 Todo `Http::fake([...])` lleva stub `'*'` al final: en Laravel 8 una request que no
 * matchea ningún stub sale a la red de verdad.
 */
class Comprobante_de_venta_Test extends TestCase
{
    use DatabaseTransactions;

    /** Teléfono del cliente final, el mismo que va a quedar marcado por la simulación. */
    const PHONE = '5493416009988';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var Client */
    protected $cliente;

    /** @var Sale */
    protected $venta;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: cada test setea su fake si la necesita.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-9',
            'company_name' => 'Ferreteria F8-9',
            'email'        => 'whatsapp-f8-9-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-f8-9',
            'phone_number_id'    => '5491100000009',
            'webhook_secret'     => 'secreto-f8-9',
            'is_active'          => true,
            'ai_enabled_default' => true,
            // Opt-in del envío automático: sin esto el job no hace nada.
            'auto_send_sale_pdf' => true,
        ]);

        $this->cliente = Client::create([
            'name'    => 'Cliente real de la ferreteria',
            'user_id' => $this->comercio->id,
            'phone'   => self::PHONE,
        ]);

        $this->venta = Sale::create([
            'user_id'   => $this->comercio->id,
            'client_id' => $this->cliente->id,
            'moneda_id' => 1,
            'total'     => 1000,
            'terminada' => 1,
        ]);
    }

    /**
     * Chat del cliente MARCADO por una simulación: el dueño inyectó un entrante desde
     * "Simular mensaje" sobre el teléfono de un cliente real, así que la ventana de 24 h
     * quedó forzada abierta y `last_inbound_simulated` en 1.
     *
     * @return WhatsappChat
     */
    protected function chat_en_simulacion()
    {
        return WhatsappChat::create([
            'user_id'                => $this->comercio->id,
            'client_id'              => $this->cliente->id,
            'phone'                  => self::PHONE,
            'ai_enabled'             => true,
            'unread_count'           => 0,
            'last_message_at'        => now(),
            'last_inbound_at'        => now(),
            'last_inbound_simulated' => 1,
        ]);
    }

    /**
     * Chat con la ventana de 24 h CERRADA: el último entrante real fue hace 30 horas. Es el
     * caso que obliga a mandar el comprobante por la plantilla `cc_cli_comprobante`.
     *
     * @return WhatsappChat
     */
    protected function chat_fuera_de_ventana()
    {
        return WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'client_id'       => $this->cliente->id,
            'phone'           => self::PHONE,
            'ai_enabled'      => true,
            'unread_count'    => 0,
            'last_message_at' => now()->subHours(30),
            'last_inbound_at' => now()->subHours(30),
        ]);
    }

    /**
     * Plantilla estándar del comprobante, ya aprobada en Meta.
     *
     * @return WhatsappTemplate
     */
    protected function plantilla_aprobada()
    {
        return WhatsappTemplate::create([
            'user_id'            => $this->comercio->id,
            'name'               => 'Comprobante de compra',
            'meta_template_name' => SaleWhatsappSenderService::TEMPLATE_META_NAME,
            'category'           => 'utility',
            'language'           => 'es_AR',
            'body_preview'       => 'Hola {{1}}, te dejamos el comprobante {{2}} de tu compra en {{3}}.',
            'variables'          => ['Nombre', 'Nº de comprobante', 'Negocio'],
            'status'             => 'aprobada',
            'is_system'          => true,
        ]);
    }

    /**
     * El comprobante de una venta NO lo frena la simulación: lo dispara una venta real, no
     * la prueba. Es el bloqueante que cerró la misión — con el freno puesto en
     * `send_document()` esta request a Kapso no existía y la venta quedaba sin comprobante.
     *
     * @group whatsapp
     * @test
     */
    public function el_comprobante_de_venta_sale_aunque_el_chat_este_en_simulacion()
    {
        $this->chat_en_simulacion();

        Http::fake([
            'api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.COMPROBANTE']]], 200),
            '*' => Http::response([], 200),
        ]);

        $message = (new SaleWhatsappSenderService())->send_sale($this->venta, null);

        // Salió a Kapso de verdad, y salió como documento (ventana abierta).
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['type']) && $body['type'] === 'document';
        });

        // Y el mensaje quedó persistido con el id que devolvió Kapso.
        $this->assertSame('wamid.COMPROBANTE', $message->wa_message_id);
    }

    /**
     * Con Kapso rechazando el envío, el job NO puede loguear un éxito ni dejar una fila
     * diciendo que se mandó algo. Cubre el bug preexistente: cualquier fallo de Kapso (clave
     * vencida, caída, rate limit) se daba por enviado igual.
     *
     * @group whatsapp
     * @test
     */
    public function un_fallo_de_kapso_no_queda_logueado_como_exito()
    {
        $chat = $this->chat_en_simulacion();

        Http::fake([
            'api.kapso.ai/*' => Http::response(['error' => 'clave vencida'], 401),
            '*' => Http::response([], 200),
        ]);

        $log = Log::spy();
        // El service loguea con `Log::channel('daily')->error(...)`: que devuelva el propio spy.
        $log->shouldReceive('channel')->andReturnSelf();

        (new SendSaleWhatsappJob($this->venta->id))->handle();

        // 1) El log de éxito NO se emitió.
        $log->shouldNotHaveReceived('info', [
            'SendSaleWhatsappJob: comprobante enviado automáticamente.',
            ['sale_id' => $this->venta->id],
        ]);

        // 2) Sí se emitió el de fallo, y como error: no es una condición esperada del negocio.
        $log->shouldHaveReceived('error')
            ->with('SendSaleWhatsappJob: WhatsApp no confirmó el envío del comprobante.', \Mockery::type('array'))
            ->once();

        // 3) No quedó ninguna fila diciendo que se mandó algo.
        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->count());
    }

    /**
     * El freno sigue puesto donde tiene que estar: el texto libre es lo que de verdad se
     * apoya en la ventana de 24 h falseada por la simulación. Sin este test, "sacar el freno
     * de `send_document()`" podría degenerar en sacarlo de los dos lados.
     *
     * @group whatsapp
     * @test
     */
    public function el_freno_sigue_puesto_en_el_texto_libre()
    {
        $chat = $this->chat_en_simulacion();

        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $wa_message_id = (new WhatsappBotSendService())
            ->send_text($chat->phone, 'esto no le tiene que llegar a nadie', $this->config);

        $this->assertNull($wa_message_id);
        Http::assertNothingSent();
    }

    /**
     * La otra rama de `send_sale()`: con la ventana cerrada el comprobante va por la
     * plantilla `cc_cli_comprobante`, y `send_template()` devuelve null igual que
     * `send_document()` cuando Kapso falla. El job elige una rama u otra según la ventana,
     * así que tapar una sola dejaba el mismo log mentiroso disponible la mitad de las veces.
     *
     * @group whatsapp
     * @test
     */
    public function la_rama_de_plantilla_tambien_falla_ruidosamente()
    {
        $chat = $this->chat_fuera_de_ventana();
        $this->plantilla_aprobada();

        Http::fake([
            'api.kapso.ai/*' => Http::response(['error' => 'kapso caido'], 500),
            '*' => Http::response([], 200),
        ]);

        try {
            (new SaleWhatsappSenderService())->send_sale($this->venta, null);

            $this->fail('Tenía que lanzar SaleWhatsappSendException: Kapso no confirmó el envío.');
        } catch (SaleWhatsappSendException $e) {
            $this->assertSame(SaleWhatsappSenderService::CODE_ENVIO_NO_CONFIRMADO, $e->error_code());
        }

        // Se intentó por la plantilla, no por el documento: la ventana estaba cerrada.
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['type']) && $body['type'] === 'template';
        });

        // Y tampoco acá quedó una fila diciendo que se mandó algo.
        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->count());
    }
}
