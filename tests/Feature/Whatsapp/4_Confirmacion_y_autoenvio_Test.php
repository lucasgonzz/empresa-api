<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Jobs\AutoSendWhatsappAiMessageJob;
use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAgentScheduler;
use App\Services\WhatsappAiAutoSendScheduler;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/4: confirmación humana, auto-envío y descarte.
 *
 * Lo que protege este archivo:
 *
 * - Que con `ai_confirm_delay_seconds = 0` nada cambie respecto de antes de la misión:
 *   la respuesta sale sola, sin pasar por ninguna aprobación.
 * - Que con una espera configurada el mensaje quede `a_confirmar`, con su fecha de
 *   auto-envío, y que NO se haya llamado a Kapso.
 * - Que el token de auto-envío haga imposible mandar dos veces el mismo mensaje o mandar
 *   uno ya descartado.
 * - D2 y D3: un entrante nuevo BORRA el pendiente, y el historial que ve la IA no incluye
 *   lo que todavía no se dijo.
 * - Regresión dura: `handle_delivery_status_event()` sigue avanzando sin retroceder, con
 *   las columnas nuevas en la tabla.
 *
 * 🔴 `QUEUE_CONNECTION=sync` en tests: `->delay()` se ignora y el job correría inline. Por
 * eso todo caso que quiera ver el mensaje QUEDARSE en `a_confirmar` usa `Queue::fake()`.
 * Y todo `Http::fake([...])` lleva stub `'*'` al final: en Laravel 8 una request que no
 * matchea ningún stub sale a la red de verdad.
 */
class Confirmacion_y_autoenvio_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** Teléfono del cliente final. */
    const PHONE = '5493416002233';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    /** Texto que devuelve el fake de Anthropic. */
    protected $texto_de_la_ia = 'Respuesta generada por el agente.';

    /** Payload que viajó a Anthropic. */
    protected $payload_anthropic = null;

    /** Cantidad de llamadas que recibió Kapso. */
    protected $llamadas_a_kapso = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: cada test setea su fake si la necesita.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-4',
            'company_name' => 'Ferreteria F8-4',
            'email'        => 'whatsapp-f8-4-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F8-4',
            'email'    => 'whatsapp-f8-4-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-f8-4',
            'phone_number_id'    => '5491100000004',
            'webhook_secret'     => 'secreto-f8-4',
            'is_active'          => true,
            'ai_enabled_default' => true,
        ]);
    }

    /**
     * Asigna la extensión al comercio (creando la fila del catálogo si la base del slot
     * todavía no la tiene sembrada). El middleware resuelve al owner, así que con dársela
     * al dueño alcanza también para sus empleados.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'WhatsApp',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Stubs de las tres APIs externas. El `'*'` final evita que una request sin stub
     * salga a la red.
     *
     * @return void
     */
    protected function fakes_de_red()
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => function ($request) {
                $this->payload_anthropic = $request->data();

                return Http::response([
                    'model'   => 'claude-de-prueba',
                    'content' => [['type' => 'text', 'text' => $this->texto_de_la_ia]],
                    'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                ], 200);
            },
            'api.kapso.ai/*' => function () {
                $this->llamadas_a_kapso++;

                return Http::response(['messages' => [['id' => 'wamid.CONFIRMADO']]], 200);
            },
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * Chat con la ventana de 24 h abierta y un mensaje entrante sin responder.
     *
     * @param string $body
     * @return WhatsappChat
     */
    protected function chat_con_entrante($body = 'hola, ¿tenés tornillos?')
    {
        $chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => self::PHONE,
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => $body,
        ]);

        return $chat;
    }

    /**
     * @param WhatsappChat $chat
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function salientes(WhatsappChat $chat)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->get();
    }

    /**
     * Con la espera de confirmación en 0, el comportamiento es el de antes de la misión:
     * la respuesta se genera y sale, sin que nadie la apruebe.
     *
     * Acá NO va Queue::fake a propósito: el auto-envío con demora 0 se despacha en `sync`
     * sin `afterResponse`, y eso es justamente lo que se quiere ver correr.
     *
     * @group whatsapp
     * @test
     */
    public function con_la_espera_de_confirmacion_en_cero_la_respuesta_sale_enviada()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->texto_de_la_ia = 'Sí, tenemos tornillos.';
        $this->fakes_de_red();

        $chat = $this->chat_con_entrante();

        // Token de debounce vigente (1): el scheduler lo bumpea sin encolar nada.
        (new WhatsappAgentScheduler())->cancel((int) $chat->id);

        (new GenerateWhatsappAiReplyJob((int) $chat->id, 1))->handle(app(WhatsappAgentScheduler::class));

        $salientes = $this->salientes($chat);
        $this->assertCount(1, $salientes);
        $this->assertEquals('enviado', $salientes[0]->ai_status);
        $this->assertEquals('wamid.CONFIRMADO', $salientes[0]->wa_message_id);
        $this->assertNull($salientes[0]->ai_auto_send_at, 'Un mensaje ya enviado no tiene contador pendiente.');
        $this->assertEquals(1, $this->llamadas_a_kapso, 'Kapso se llama exactamente una vez.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_espera_de_confirmacion_el_mensaje_queda_esperando_y_kapso_no_se_llama()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->texto_de_la_ia = 'Sí, tenemos tornillos.';
        $this->fakes_de_red();

        $this->config->ai_confirm_delay_seconds = 120;
        $this->config->save();

        $chat = $this->chat_con_entrante();
        (new WhatsappAgentScheduler())->cancel((int) $chat->id);

        (new GenerateWhatsappAiReplyJob((int) $chat->id, 1))->handle(app(WhatsappAgentScheduler::class));

        $salientes = $this->salientes($chat);
        $this->assertCount(1, $salientes);

        $mensaje = $salientes[0];
        $this->assertEquals('a_confirmar', $mensaje->ai_status);
        $this->assertNull($mensaje->wa_message_id, 'Todavía no pasó por Kapso, así que no tiene id de Meta.');
        $this->assertNotNull($mensaje->ai_auto_send_at, 'La fecha de auto-envío es lo que el front usa de contador.');
        $this->assertTrue(
            $mensaje->ai_auto_send_at->greaterThan(now()->addSeconds(110))
                && $mensaje->ai_auto_send_at->lessThan(now()->addSeconds(130)),
            'ai_auto_send_at tiene que caer a unos 120 segundos de ahora.'
        );

        $this->assertEquals(0, $this->llamadas_a_kapso, 'Nada puede haber salido antes de la confirmación.');
        Queue::assertPushed(AutoSendWhatsappAiMessageJob::class, 1);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_autoenvio_con_token_obsoleto_no_manda_nada()
    {
        Queue::fake();
        $this->fakes_de_red();

        $this->config->ai_confirm_delay_seconds = 120;
        $this->config->save();

        $chat = $this->chat_con_entrante();
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Respuesta que iba a salir sola.');

        $scheduler = new WhatsappAiAutoSendScheduler();
        $scheduler->schedule_for_message($mensaje, $this->config);

        // Alguien confirmó, descartó, o el cliente volvió a escribir: el token se invalida.
        $scheduler->cancel_for_message((int) $mensaje->id);

        (new AutoSendWhatsappAiMessageJob((int) $mensaje->id, 1))->handle(app(WhatsappAiAutoSendScheduler::class));

        $this->assertEquals(0, $this->llamadas_a_kapso);
        $mensaje->refresh();
        $this->assertEquals('a_confirmar', $mensaje->ai_status, 'El mensaje sigue como estaba: el job se descartó solo.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_autoenvio_con_token_vigente_manda_y_marca_enviado()
    {
        Queue::fake();
        $this->fakes_de_red();

        $this->config->ai_confirm_delay_seconds = 120;
        $this->config->save();

        $chat = $this->chat_con_entrante();
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Respuesta que sale sola al vencer la espera.');

        (new WhatsappAiAutoSendScheduler())->schedule_for_message($mensaje, $this->config);

        (new AutoSendWhatsappAiMessageJob((int) $mensaje->id, 1))->handle(app(WhatsappAiAutoSendScheduler::class));

        $this->assertEquals(1, $this->llamadas_a_kapso);

        $mensaje->refresh();
        $this->assertEquals('enviado', $mensaje->ai_status);
        $this->assertEquals('wamid.CONFIRMADO', $mensaje->wa_message_id);
        $this->assertNull($mensaje->ai_auto_send_at);
    }

    /**
     * D2: si el cliente sigue escribiendo, la respuesta que estaba esperando aprobación ya
     * no sirve y se BORRA (no se marca). Si quedara, la IA leería un 'out' que el cliente
     * nunca recibió y creería que ya contestó.
     *
     * @group whatsapp
     * @test
     */
    public function un_entrante_nuevo_borra_el_pendiente_y_cancela_su_token()
    {
        Queue::fake();
        $this->fakes_de_red();

        $this->config->ai_confirm_delay_seconds = 120;
        $this->config->save();

        $chat = $this->chat_con_entrante();
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Respuesta que quedó vieja.');

        $auto_send_scheduler = new WhatsappAiAutoSendScheduler();
        $auto_send_scheduler->schedule_for_message($mensaje, $this->config);
        $this->assertTrue($auto_send_scheduler->is_token_current((int) $mensaje->id, 1));

        // El cliente manda otro mensaje.
        $payload = ['message' => ['from' => self::PHONE, 'type' => 'text', 'text' => ['body' => 'de 3 pulgadas']]];
        $chat = WhatsappChatHelper::store_inbound_message(
            $this->comercio->id,
            self::PHONE,
            'de 3 pulgadas',
            $payload,
            $this->config
        );
        (new WhatsappAgentScheduler())->schedule_after_inbound($chat, $this->config);

        $this->assertNull(
            WhatsappChatMessage::find($mensaje->id),
            'El pendiente se borra: el cliente nunca lo recibió y la IA no lo puede leer como respuesta dada.'
        );
        $this->assertFalse(
            $auto_send_scheduler->is_token_current((int) $mensaje->id, 1),
            'Y su job de auto-envío queda invalidado.'
        );
        $this->assertEquals(0, $this->llamadas_a_kapso);
    }

    /**
     * D3: un mensaje esperando confirmación todavía no se dijo, así que no entra al
     * historial que ve la IA.
     *
     * @group whatsapp
     * @test
     */
    public function el_historial_que_ve_la_ia_no_incluye_el_pendiente_de_confirmacion()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        $chat = $this->chat_con_entrante('quiero tornillos');
        WhatsappChatHelper::store_pending_ai_message($chat, 'ESTE TEXTO TODAVIA NO SE DIJO');

        (new WhatsappBotAiService())->generate_response($chat, $this->config);

        $this->assertNotNull($this->payload_anthropic);
        $serializado = json_encode($this->payload_anthropic['messages']);

        $this->assertStringNotContainsString(
            'ESTE TEXTO TODAVIA NO SE DIJO',
            $serializado,
            'Un a_confirmar en el historial le haría creer a la IA que ya contestó.'
        );
        $this->assertStringContainsString('quiero tornillos', $serializado);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function confirmar_manda_el_mensaje_y_devuelve_el_modelo()
    {
        $this->dar_extension();
        $this->fakes_de_red();
        $this->actingAs($this->empleado, 'web');

        $chat = $this->chat_con_entrante();
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Respuesta aprobada a mano.');

        $response = $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm');

        $response->assertStatus(200);
        $this->assertEquals('enviado', $response->json('model.ai_status'));
        $this->assertEquals('wamid.CONFIRMADO', $response->json('model.wa_message_id'));
        $this->assertEquals(1, $this->llamadas_a_kapso);

        $mensaje->refresh();
        $this->assertEquals('enviado', $mensaje->ai_status);
        $this->assertNull($mensaje->ai_auto_send_at);
    }

    /**
     * Mismo contrato que `send_message()`: fuera de la ventana de 24 h no se puede mandar
     * texto libre y el front tiene que ofrecer una plantilla.
     *
     * @group whatsapp
     * @test
     */
    public function confirmar_fuera_de_la_ventana_de_24_horas_devuelve_422_y_no_manda_nada()
    {
        $this->dar_extension();
        $this->fakes_de_red();
        $this->actingAs($this->empleado, 'web');

        $chat = $this->chat_con_entrante();
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Respuesta tardía.');

        $chat->last_inbound_at = now()->subHours(25);
        $chat->save();

        $response = $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm');

        $response->assertStatus(422);
        $this->assertEquals('fuera_de_ventana', $response->json('code'));
        $this->assertEquals(0, $this->llamadas_a_kapso);

        $mensaje->refresh();
        $this->assertEquals('a_confirmar', $mensaje->ai_status, 'Un pedido rechazado no puede dejar el mensaje a medio camino.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function confirmar_un_mensaje_de_otra_empresa_devuelve_404()
    {
        $this->dar_extension();
        $this->fakes_de_red();

        $otro_comercio = User::create([
            'name'     => 'Otro comercio F8-4',
            'email'    => 'whatsapp-f8-4-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $chat_ajeno = WhatsappChat::create([
            'user_id'         => $otro_comercio->id,
            'phone'           => '5493416009999',
            'ai_enabled'      => true,
            'unread_count'    => 0,
            'last_inbound_at' => now(),
        ]);
        $mensaje_ajeno = WhatsappChatHelper::store_pending_ai_message($chat_ajeno, 'Respuesta de otra empresa.');

        $this->actingAs($this->empleado, 'web');

        $this->putJson('api/whatsapp-chats/messages/' . $mensaje_ajeno->id . '/confirm')->assertStatus(404);
        $this->deleteJson('api/whatsapp-chats/messages/' . $mensaje_ajeno->id)->assertStatus(404);

        $this->assertEquals(0, $this->llamadas_a_kapso);
        $mensaje_ajeno->refresh();
        $this->assertEquals('a_confirmar', $mensaje_ajeno->ai_status, 'El mensaje ajeno queda intacto.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function descartar_borra_el_mensaje_pendiente()
    {
        // Queue::fake + espera configurada: es el único escenario en el que hay algo que
        // descartar. Con la espera en 0 el mensaje sale solo (en `sync`, inline) y ya no
        // está pendiente de nada.
        Queue::fake();
        $this->dar_extension();
        $this->fakes_de_red();
        $this->actingAs($this->empleado, 'web');

        $this->config->ai_confirm_delay_seconds = 120;
        $this->config->save();

        $chat = $this->chat_con_entrante();
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Respuesta que el operador no quiere mandar.');

        $auto_send_scheduler = new WhatsappAiAutoSendScheduler();
        $auto_send_scheduler->schedule_for_message($mensaje, $this->config);

        $this->deleteJson('api/whatsapp-chats/messages/' . $mensaje->id)->assertStatus(200);

        $this->assertNull(WhatsappChatMessage::find($mensaje->id));
        $this->assertEquals(0, $this->llamadas_a_kapso);
        $this->assertFalse($auto_send_scheduler->is_token_current((int) $mensaje->id, 1));

        // Y descartar dos veces no rompe: el segundo pedido no encuentra el mensaje.
        $this->deleteJson('api/whatsapp-chats/messages/' . $mensaje->id)->assertStatus(404);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function confirmar_un_mensaje_que_ya_se_envio_devuelve_422()
    {
        $this->dar_extension();
        $this->fakes_de_red();
        $this->actingAs($this->empleado, 'web');

        $chat = $this->chat_con_entrante();
        $mensaje = WhatsappChatHelper::store_outbound_ai_message($chat, 'Respuesta ya enviada.', 'wamid.VIEJO');

        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(422);
        $this->assertEquals(0, $this->llamadas_a_kapso);
    }

    /**
     * REGRESIÓN DURA: el eje de Meta (`delivery_status`) sigue avanzando y sin retroceder,
     * ahora que la tabla tiene además el eje de confirmación humana. Meter 'a_confirmar'
     * adentro de `delivery_status` habría roto exactamente esto.
     *
     * @group whatsapp
     * @test
     */
    public function los_estados_de_entrega_siguen_avanzando_y_no_retroceden()
    {
        $chat = $this->chat_con_entrante();
        $mensaje = WhatsappChatHelper::store_outbound_manual_message($chat, 'Mensaje del operador.', 'wamid.RANK', $this->comercio->id);

        $this->assertEquals('pendiente', $mensaje->fresh()->delivery_status);

        $payload = ['message' => ['id' => 'wamid.RANK']];

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.sent', $payload);
        $this->assertEquals('enviado', $mensaje->fresh()->delivery_status);

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.delivered', $payload);
        $this->assertEquals('entregado', $mensaje->fresh()->delivery_status);

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.read', $payload);
        $this->assertEquals('leido', $mensaje->fresh()->delivery_status);

        // Los eventos fuera de orden no pueden hacer retroceder nada.
        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.delivered', $payload);
        $this->assertEquals('leido', $mensaje->fresh()->delivery_status);

        WhatsappChatHelper::handle_delivery_status_event('whatsapp.message.failed', $payload);
        $this->assertEquals('leido', $mensaje->fresh()->delivery_status);

        $this->assertNull(
            $mensaje->fresh()->ai_status,
            'Un mensaje manual nunca toca el eje de confirmación del agente.'
        );
    }
}
