<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAgentScheduler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/3: debounce, agrupación y salida de la IA del webhook.
 *
 * Es el corazón de la misión. Lo que protege:
 *
 * - Que tres mensajes seguidos del cliente se contesten UNA sola vez (el token de
 *   debounce descarta los jobs viejos) y que la respuesta contemple los tres, tanto en
 *   la búsqueda del catálogo (D5) como en el turno que ve el modelo.
 * - Que con demora configurada el webhook YA NO llame a Anthropic adentro del request:
 *   ese era el motivo de la fase, Kapso esperaba varios segundos por un tercero.
 * - Que con las dos demoras en 0 el comportamiento sea idéntico al de antes de la
 *   misión (respuesta al toque, dentro del mismo ciclo del request). Esto es lo que
 *   hace seguro el merge.
 * - Los guards del job: bot apagado, IA del chat apagada y ventana de 24 h cerrada.
 *
 * 🔴 Sobre las colas en tests: `QUEUE_CONNECTION=sync` (phpunit.xml), así que `->delay()`
 * se IGNORA y el job correría inline. Por eso todo test que quiera observar la rama con
 * demora usa `Queue::fake()` y assertea el despacho, en vez de esperar. Y todo
 * `Http::fake([...])` de esta suite lleva un stub `'*'` al final: en Laravel 8, una
 * request que no matchea ningún stub SALE A LA RED DE VERDAD.
 */
class Debounce_agrupacion_Test extends TestCase
{
    use DatabaseTransactions;

    /** Teléfono del cliente final usado en todos los casos. */
    const PHONE = '5493416001122';

    /** Secreto del webhook con el que se firman los payloads. */
    const SECRET = 'secreto-webhook-f8-3';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** Texto que devuelve el fake de Anthropic; se lee al momento de la llamada. */
    protected $texto_de_la_ia = 'Respuesta del agente.';

    /** Payload que viajó a Anthropic (lo captura el stub). */
    protected $payload_anthropic = null;

    /** Payload que viajó a OpenAI para el embedding del RAG. */
    protected $payload_openai = null;

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
            'name'         => 'Comercio whatsapp F8-3',
            'company_name' => 'Ferreteria F8-3',
            'email'        => 'whatsapp-f8-3-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-f8-3',
            'phone_number_id'    => '5491100000003',
            'webhook_secret'     => self::SECRET,
            'is_active'          => true,
            'ai_enabled_default' => true,
        ]);
    }

    /**
     * Registra los stubs de las tres APIs externas y captura lo que viaja en cada una.
     * El `'*'` final no es decorativo: sin él, cualquier request que no matchee sale a
     * la red de verdad (comportamiento de Laravel 8).
     *
     * @return void
     */
    protected function fakes_de_red()
    {
        Http::fake([
            'api.openai.com/*' => function ($request) {
                $this->payload_openai = $request->data();

                return Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200);
            },
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

                return Http::response(['messages' => [['id' => 'wamid.DEPRUEBA']]], 200);
            },
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * Persiste un mensaje entrante y dispara el scheduler del agente, que es exactamente
     * lo que hace `WhatsappBotController::process_inbound()`.
     *
     * @param string $body
     * @return WhatsappChat
     */
    protected function entrante($body)
    {
        $payload = [
            'message' => [
                'from' => self::PHONE,
                'type' => 'text',
                'text' => ['body' => $body],
            ],
        ];

        $chat = WhatsappChatHelper::store_inbound_message(
            $this->comercio->id,
            self::PHONE,
            $body,
            $payload,
            $this->config
        );

        (new WhatsappAgentScheduler())->schedule_after_inbound($chat, $this->config);

        return $chat;
    }

    /**
     * Postea al webhook público firmando el cuerpo con HMAC-SHA256, igual que Kapso.
     * La firma se calcula sobre el MISMO json_encode que usa postJson internamente.
     *
     * @param string $body
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_webhook($body)
    {
        $payload = [
            'message' => [
                'from' => self::PHONE,
                'type' => 'text',
                'text' => ['body' => $body],
            ],
        ];

        $firma = hash_hmac('sha256', json_encode($payload), self::SECRET);

        return $this->postJson('api/whatsapp-bot/webhook', $payload, [
            'X-Kapso-Signature' => $firma,
        ]);
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
     * @group whatsapp
     * @test
     */
    public function tres_entrantes_seguidos_encolan_tres_jobs_y_el_token_viejo_no_genera_nada()
    {
        Queue::fake();
        Http::fake(['*' => Http::response([], 200)]);

        $this->config->ai_reply_delay_seconds = 30;
        $this->config->save();

        $this->entrante('hola');
        $this->entrante('tenés tornillos');
        $chat = $this->entrante('de 3 pulgadas');

        Queue::assertPushed(GenerateWhatsappAiReplyJob::class, 3);

        // El job del PRIMER token se despierta y encuentra que ya hay dos programaciones
        // posteriores: se descarta solo, sin gastar una llamada a Anthropic.
        (new GenerateWhatsappAiReplyJob((int) $chat->id, 1))->handle(app(WhatsappAgentScheduler::class));

        Http::assertNothingSent();
        $this->assertCount(
            0,
            $this->salientes($chat),
            'Un token de debounce obsoleto no puede dejar ni un mensaje saliente.'
        );
    }

    /**
     * D5: el RAG se dispara sobre TODOS los entrantes sin responder, y el modelo ve un
     * solo turno con los tres fundidos. Buscar en el catálogo solo por el último mensaje
     * ("de 3 pulgadas") se lleva puesto el producto que el cliente estaba preguntando.
     *
     * @group whatsapp
     * @test
     */
    public function el_job_del_ultimo_token_responde_una_sola_vez_con_los_tres_mensajes()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->texto_de_la_ia = 'Sí, tenemos tornillos de 3 pulgadas.';
        $this->fakes_de_red();

        $this->config->ai_reply_delay_seconds = 30;
        $this->config->save();

        $this->entrante('hola');
        $this->entrante('tenés tornillos');
        $chat = $this->entrante('de 3 pulgadas');

        (new GenerateWhatsappAiReplyJob((int) $chat->id, 3))->handle(app(WhatsappAgentScheduler::class));

        $salientes = $this->salientes($chat);
        $this->assertCount(1, $salientes, 'Los tres mensajes seguidos se contestan con UNA sola respuesta.');
        $this->assertEquals('Sí, tenemos tornillos de 3 pulgadas.', $salientes[0]->body);

        // El texto que dispara la búsqueda de catálogo trae los tres cuerpos.
        $this->assertNotNull($this->payload_openai, 'El RAG tiene que haber pedido el embedding de la consulta.');
        $input = (string) $this->payload_openai['input'];
        $this->assertStringContainsString('hola', $input);
        $this->assertStringContainsString('tenés tornillos', $input);
        $this->assertStringContainsString('de 3 pulgadas', $input);

        // Y el modelo ve un único turno de usuario con los tres fundidos.
        $this->assertNotNull($this->payload_anthropic);
        $mensajes = $this->payload_anthropic['messages'];
        $this->assertCount(1, $mensajes, 'Los tres entrantes se funden en un solo turno user.');
        $this->assertEquals('user', $mensajes[0]['role']);
        $contenido = (string) $mensajes[0]['content'];
        $this->assertStringContainsString('hola', $contenido);
        $this->assertStringContainsString('tenés tornillos', $contenido);
        $this->assertStringContainsString('de 3 pulgadas', $contenido);
    }

    /**
     * Esta es la garantía de que el merge es seguro: con las dos demoras en 0, un mensaje
     * que entra por el webhook termina con la respuesta generada Y enviada dentro del
     * mismo ciclo, igual que antes de la misión. La diferencia es que ahora corre después
     * de devolverle el 200 a Kapso (sync + afterResponse), no adentro del request.
     *
     * @group whatsapp
     * @test
     */
    public function con_las_dos_demoras_en_cero_el_webhook_contesta_200_y_la_respuesta_sale()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->texto_de_la_ia = 'Buenas, sí tenemos.';
        $this->fakes_de_red();

        $this->assertEquals(0, (int) $this->config->ai_reply_delay_seconds);
        $this->assertEquals(0, (int) $this->config->ai_confirm_delay_seconds);

        $this->postear_webhook('hola, ¿tenés tornillos?')->assertStatus(200);

        $chat = WhatsappChat::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($chat, 'El webhook tiene que haber persistido el chat.');

        $salientes = $this->salientes($chat);
        $this->assertCount(1, $salientes, 'Con demora 0 la respuesta sale en el mismo ciclo del request.');
        $this->assertEquals('Buenas, sí tenemos.', $salientes[0]->body);
        $this->assertEquals('enviado', $salientes[0]->ai_status, 'Sin espera de confirmación el mensaje sale directo.');
        $this->assertEquals('wamid.DEPRUEBA', $salientes[0]->wa_message_id);
        $this->assertEquals(1, $this->llamadas_a_kapso, 'Kapso se llama exactamente una vez.');
    }

    /**
     * El motivo de toda la fase: con demora configurada, el request del webhook devuelve
     * 200 sin haber tocado a Anthropic. Antes de la misión, Kapso quedaba esperando la
     * llamada al modelo (más el RAG, que en MySQL carga todos los artículos en memoria).
     *
     * @group whatsapp
     * @test
     */
    public function con_demora_configurada_el_webhook_no_llama_a_anthropic_en_el_request()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        $this->config->ai_reply_delay_seconds = 30;
        $this->config->save();

        $this->postear_webhook('hola, ¿tenés tornillos?')->assertStatus(200);

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'api.anthropic.com') !== false;
        });
        $this->assertNull($this->payload_anthropic);

        Queue::assertPushed(GenerateWhatsappAiReplyJob::class, 1);

        $chat = WhatsappChat::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($chat, 'El entrante igual se persistió: lo que se difiere es la respuesta.');
        $this->assertCount(0, $this->salientes($chat));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_job_no_responde_si_apagaron_el_bot_durante_la_espera()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        $this->config->ai_reply_delay_seconds = 30;
        $this->config->save();

        $chat = $this->entrante('hola');

        // Durante la espera, el dueño apagó el bot desde Integraciones.
        $this->config->is_active = false;
        $this->config->save();

        (new GenerateWhatsappAiReplyJob((int) $chat->id, 1))->handle(app(WhatsappAgentScheduler::class));

        Http::assertNothingSent();
        $this->assertCount(0, $this->salientes($chat));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_job_no_responde_si_apagaron_la_ia_de_ese_chat()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        $this->config->ai_reply_delay_seconds = 30;
        $this->config->save();

        $chat = $this->entrante('hola');

        // El operador tomó la conversación y apagó la respuesta automática.
        $chat->ai_enabled = false;
        $chat->save();

        (new GenerateWhatsappAiReplyJob((int) $chat->id, 1))->handle(app(WhatsappAgentScheduler::class));

        Http::assertNothingSent();
        $this->assertCount(0, $this->salientes($chat));
    }

    /**
     * Guard nuevo de la misión: con demoras largas (o la cola atrasada) la ventana de 24 h
     * de Meta se puede cerrar entre el entrante y el job. Mandar texto libre ahí falla en
     * Meta, así que ni se genera la respuesta.
     *
     * @group whatsapp
     * @test
     */
    public function el_job_no_responde_si_la_ventana_de_24_horas_se_cerro()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        $this->config->ai_reply_delay_seconds = 30;
        $this->config->save();

        $chat = $this->entrante('hola');

        $chat->last_inbound_at = now()->subHours(25);
        $chat->save();

        (new GenerateWhatsappAiReplyJob((int) $chat->id, 1))->handle(app(WhatsappAgentScheduler::class));

        Http::assertNothingSent();
        $this->assertCount(0, $this->salientes($chat));
    }

    /**
     * El job es defensivo hasta el final: si el último mensaje del chat ya es una
     * respuesta, no hay nada sin contestar y no se gasta una llamada.
     *
     * @group whatsapp
     * @test
     */
    public function el_job_no_responde_si_el_operador_ya_contesto_a_mano()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        $this->config->ai_reply_delay_seconds = 30;
        $this->config->save();

        $chat = $this->entrante('hola');

        WhatsappChatHelper::store_outbound_manual_message($chat, 'Ya te contesto yo.', 'wamid.MANUAL', $this->comercio->id);

        (new GenerateWhatsappAiReplyJob((int) $chat->id, 1))->handle(app(WhatsappAgentScheduler::class));

        Http::assertNothingSent();
        $this->assertCount(
            1,
            $this->salientes($chat),
            'El único saliente tiene que seguir siendo el que escribió el operador.'
        );
    }
}
