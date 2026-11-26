<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAgentScheduler;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión truvari-envio-coherente v2: con la opción `derivar_cotizacion_envio` prendida, el agente de WhatsApp
 * no le manda al cliente un costo de envío, un peaje, un «envío gratis» ni un total: esas oraciones se sacan
 * del cuerpo (lo hace un filtro sobre el TEXTO, no una marca del modelo), se agrega una frase fija y el chat
 * queda como consulta para el equipo. Los precios de catálogo salen como siempre.
 *
 * Origen: `tr-t2.json`, mensaje 104: el modelo calculó de cabeza un envío con dos totales distintos y una
 * comparación falsa («supera los 80.000»). No hay un dato estructurado confiable para calcularlo, así que el
 * sistema no lo informa: lo confirma una persona.
 *
 * 🔴 `Queue::fake()` en todos los casos, por el mismo motivo que el test 29: con `QUEUE_CONNECTION=sync` el
 * `->delay()` del auto-envío se ignora y la respuesta saldría antes de poder mirarla.
 */
class Derivar_cotizacion_de_envio_Test extends TestCase
{
    use DatabaseTransactions;

    const SLUG = 'agentes_ia';

    const FRASE = 'El costo de envío y el total final los confirma el equipo en breve.';

    /** Mensaje 104 de `tr-t2.json`, literal. */
    const T104 = "Hola, claro. Tenemos Fernet Branca 750 ml a \$17.000, así que las 2 botellas serían \$34.000. El envío a La Calera tiene un costo de \$7.000, más \$6.000 de peaje, lo que da un total de \$47.000. Como supera los \$80.000, el envío sería sin cargo, pero el peaje de \$6.000 se suma igual: total \$40.000.\n\nSi le parece bien, indíqueme su nombre y coordinamos el pedido.";

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    /** Texto crudo que devuelve el fake de la IA. */
    protected $texto_de_la_ia = 'Respuesta de prueba.';

    /** Payloads que viajaron a Anthropic, en orden. */
    protected $payloads_ia = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F38',
            'company_name' => 'Ferreteria F38',
            'email'        => 'whatsapp-f38-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F38',
            'email'    => 'whatsapp-f38-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'                  => $this->comercio->id,
            'kapso_api_key'            => 'kapso-f38',
            'phone_number_id'          => '5491100000038',
            'webhook_secret'           => 'secreto-f38',
            'is_active'                => true,
            'ai_enabled_default'       => true,
            'ai_confirm_delay_seconds' => 120,
            'derivar_cotizacion_envio' => true,
        ]);

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first()
            ?: ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'WhatsApp']);
        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');

        Queue::fake();
        Event::fake();
        $this->fakes_de_red();
    }

    protected function fakes_de_red()
    {
        Http::fake([
            'api.openai.com/*'    => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => function ($request) {
                $this->payloads_ia[] = $request->data();

                return Http::response([
                    'model'   => 'claude-de-prueba',
                    'content' => [['type' => 'text', 'text' => $this->texto_de_la_ia]],
                    'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                ], 200);
            },
            '*' => Http::response([], 200),
        ]);

        config(['services.anthropic.api_key' => 'clave-de-prueba']);
    }

    /**
     * Chat con un mensaje del cliente sin responder.
     */
    protected function chat_con_entrante($texto = 'Hola, quiero 2 Fernet Branca 750 para La Calera')
    {
        $chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => '549341' . random_int(1000000, 9999999),
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        $this->entrante($chat, $texto);

        return $chat;
    }

    protected function entrante(WhatsappChat $chat, $texto)
    {
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => $texto,
        ]);

        // El scheduler bumpea el token de debounce sin encolar nada: el job se corre a mano con el vigente.
        (new WhatsappAgentScheduler())->cancel((int) $chat->id);
    }

    /**
     * Corre el job con el token vigente y devuelve la respuesta guardada (la saliente más nueva).
     */
    protected function correr_job(WhatsappChat $chat)
    {
        $token = (int) $chat->fresh()->ai_schedule_token;
        (new GenerateWhatsappAiReplyJob((int) $chat->id, $token))->handle(app(WhatsappAgentScheduler::class));

        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Da por enviada la respuesta pendiente: recién ahí entra al historial que ve el modelo.
     */
    protected function dar_por_enviada(WhatsappChatMessage $mensaje)
    {
        $mensaje->ai_status = 'enviado';
        $mensaje->save();
    }

    protected function assert_sin_cotizacion($cuerpo)
    {
        foreach (['$7.000', '$6.000', '$47.000', '$40.000', '80.000', 'supera', 'peaje'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpo, 'No tenía que llegar al cliente: ' . $prohibido);
        }
    }

    /* ------------------------------------------------------------------------------------------------
     | CA1 · el caso original
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function ca1_el_mensaje_104_sale_sin_envio_ni_total_y_el_chat_queda_como_consulta()
    {
        $this->texto_de_la_ia = self::T104;
        $chat = $this->chat_con_entrante();

        $mensaje = $this->correr_job($chat);

        $this->assertNotNull($mensaje, 'El job tenía que dejar la respuesta.');
        $this->assertStringContainsString('$17.000', $mensaje->body);
        $this->assertStringContainsString('$34.000', $mensaje->body);
        $this->assertStringContainsString('coordinamos el pedido', $mensaje->body);
        $this->assert_sin_cotizacion($mensaje->body);
        $this->assertStringEndsWith(self::FRASE, $mensaje->body);
        $this->assertSame('consulta', $mensaje->ai_follow_up);
        $this->assertSame('Cotizar envío y total', $mensaje->ai_follow_up_note);
    }

    /* ------------------------------------------------------------------------------------------------
     | CA2 · tres turnos
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function ca2_en_tres_turnos_deriva_donde_corresponde_y_el_historial_no_arrastra_montos()
    {
        // Turno 1: el caso original.
        $this->texto_de_la_ia = self::T104;
        $chat = $this->chat_con_entrante();
        $uno  = $this->correr_job($chat);
        $this->assertStringEndsWith(self::FRASE, $uno->body);
        $this->dar_por_enviada($uno);

        // Turno 2: un subtotal de producto sale intacto y sin marca agregada.
        $this->texto_de_la_ia = '3 botellas serían $51.000.';
        $this->entrante($chat, '¿y si llevo 3?');
        $dos = $this->correr_job($chat);
        $this->assertSame('3 botellas serían $51.000.', $dos->body);
        $this->assertNull($dos->ai_follow_up);
        $this->dar_por_enviada($dos);

        // Turno 3: la pregunta por el envío gratis se deriva.
        $this->texto_de_la_ia = 'Sí, con esa compra el envío es sin cargo.';
        $this->entrante($chat, '¿el envío me sale gratis?');
        $tres = $this->correr_job($chat);
        $this->assertSame(self::FRASE, $tres->body);
        $this->assertSame('consulta', $tres->ai_follow_up);

        $this->assertCount(3, $this->payloads_ia);
        foreach ([1, 2] as $indice) {
            $historial = json_encode($this->payloads_ia[$indice]['messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertStringNotContainsString('$47.000', $historial, 'El turno ' . ($indice + 1) . ' no puede heredar el total malo.');
            $this->assertStringNotContainsString('$40.000', $historial);
            $this->assertStringContainsString('$34.000', $historial, 'El historial sí conserva el precio de catálogo.');
        }
    }

    /* ------------------------------------------------------------------------------------------------
     | CA5 · convivencia con PEDIDO y CONSULTA
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function ca5_con_un_pedido_gana_el_pedido_y_la_nota_suma_la_cotizacion()
    {
        $this->texto_de_la_ia = self::T104 . "\n[PEDIDO:2 Fernet Branca 750, Juan]";
        $chat = $this->chat_con_entrante();

        $mensaje = $this->correr_job($chat);

        $this->assertSame('pedido', $mensaje->ai_follow_up);
        $this->assertSame('2 Fernet Branca 750, Juan · cotizar envío y total', $mensaje->ai_follow_up_note);
        $this->assertStringEndsWith(self::FRASE, $mensaje->body);
        $this->assert_sin_cotizacion($mensaje->body);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function ca5_con_una_consulta_del_modelo_sigue_siendo_consulta_y_la_nota_suma_la_cotizacion()
    {
        $this->texto_de_la_ia = self::T104 . "\n[CONSULTA:si llegan a La Calera]";
        $chat = $this->chat_con_entrante();

        $mensaje = $this->correr_job($chat);

        $this->assertSame('consulta', $mensaje->ai_follow_up);
        $this->assertSame('si llegan a La Calera · cotizar envío y total', $mensaje->ai_follow_up_note);
    }

    /**
     * Si la nota del modelo ya llena el tope de 200 caracteres, el aviso de cotización no se pierde: se recorta
     * la nota del modelo, no el aviso.
     *
     * @group whatsapp
     * @test
     */
    public function ca5_con_una_nota_larguisima_el_aviso_de_cotizacion_sobrevive()
    {
        $this->texto_de_la_ia = self::T104 . "\n[PEDIDO:" . str_repeat('detalle ', 40) . ']';
        $chat = $this->chat_con_entrante();

        $mensaje = $this->correr_job($chat);

        $this->assertSame('pedido', $mensaje->ai_follow_up);
        $this->assertStringEndsWith('· cotizar envío y total', $mensaje->ai_follow_up_note);
        $this->assertLessThanOrEqual(201, mb_strlen($mensaje->ai_follow_up_note, 'UTF-8'));
    }

    /* ------------------------------------------------------------------------------------------------
     | CA6 · opción apagada
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function ca6_con_la_opcion_apagada_el_cuerpo_y_el_prompt_son_los_de_hoy()
    {
        $this->config->derivar_cotizacion_envio = false;
        $this->config->save();

        $this->texto_de_la_ia = self::T104;
        $chat = $this->chat_con_entrante();
        $mensaje = $this->correr_job($chat);

        $this->assertSame(self::T104, $mensaje->body, 'Sin la opción el texto sale idéntico.');
        $this->assertNull($mensaje->ai_follow_up);
        $this->assertStringNotContainsString('No informes costo de envío', (string) $this->payloads_ia[0]['system']);

        // La opción nace apagada en un negocio que no la toca.
        $otro = User::create([
            'name'     => 'Otro F38',
            'email'    => 'whatsapp-f38-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $nueva = WhatsappBotConfig::create([
            'user_id'         => $otro->id,
            'kapso_api_key'   => 'k',
            'phone_number_id' => '5491100000039',
            'webhook_secret'  => 's',
        ]);
        $this->assertFalse($nueva->fresh()->derivar_cotizacion_envio);
    }

    /* ------------------------------------------------------------------------------------------------
     | CA7 · los otros consumidores
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function ca7_sugerir_respuesta_y_el_simulador_devuelven_el_cuerpo_derivado()
    {
        $this->texto_de_la_ia = self::T104;
        $chat = $this->chat_con_entrante();
        $this->actingAs($this->empleado, 'web');

        $suggest = $this->postJson('api/whatsapp-chats/' . $chat->id . '/suggest')->assertStatus(200);
        $this->assertStringEndsWith(self::FRASE, $suggest->json('suggestion'));
        $this->assert_sin_cotizacion($suggest->json('suggestion'));
        $this->assertSame('consulta', $suggest->json('model.ai_follow_up'));

        $simulada = (new WhatsappBotAiService())->simular_respuesta((int) $this->comercio->id, 'Quiero 2 Fernet para La Calera');
        $this->assertStringEndsWith(self::FRASE, $simulada['body']);
        $this->assert_sin_cotizacion($simulada['body']);
        $this->assertStringContainsString('$34.000', $simulada['body']);
        $this->assertSame('consulta', $simulada['follow_up']);
    }

    /* ------------------------------------------------------------------------------------------------
     | CA8 · la capa de prompt
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function ca8_la_capa_de_prompt_va_al_final_y_el_prompt_de_hoy_queda_como_prefijo()
    {
        $this->texto_de_la_ia = 'Hola';

        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);
        $con_opcion = (string) $this->payloads_ia[0]['system'];

        $this->config->derivar_cotizacion_envio = false;
        $this->config->save();
        $chat2 = $this->chat_con_entrante();
        $this->correr_job($chat2);
        $sin_opcion = (string) $this->payloads_ia[1]['system'];

        $this->assertStringStartsWith($sin_opcion, $con_opcion, 'El prompt de hoy tiene que ser prefijo del nuevo.');
        $capa = trim(substr($con_opcion, strlen($sin_opcion)));
        $this->assertStringContainsString('No informes costo de envío, peaje, envío gratis ni totales con envío.', $capa);
        $this->assertStringContainsString('[CONSULTA:cotizar envío y total]', $capa);
        $this->assertStringEndsWith('[CONSULTA:cotizar envío y total].', $con_opcion);
    }

    /* ------------------------------------------------------------------------------------------------
     | CA9 · la API
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function ca9_la_api_guarda_la_opcion_no_la_toca_si_no_viene_y_rechaza_un_valor_no_booleano()
    {
        $this->config->derivar_cotizacion_envio = false;
        $this->config->save();
        $this->actingAs($this->comercio, 'web');

        $this->postJson('api/whatsapp-bot/config', ['derivar_cotizacion_envio' => true])->assertStatus(200);
        $this->assertTrue($this->config->fresh()->derivar_cotizacion_envio);

        // Otra pantalla pega al mismo endpoint sin el campo: no se pisa.
        $this->postJson('api/whatsapp-bot/config', ['agent_personality' => 'Hola'])->assertStatus(200);
        $this->assertTrue($this->config->fresh()->derivar_cotizacion_envio);

        $malo = $this->postJson('api/whatsapp-bot/config', ['derivar_cotizacion_envio' => 'quizás']);
        $malo->assertStatus(422);
        $this->assertNotEmpty($malo->json('errors.derivar_cotizacion_envio'));
        $this->assertTrue($this->config->fresh()->derivar_cotizacion_envio);

        $this->postJson('api/whatsapp-bot/config', ['derivar_cotizacion_envio' => false])->assertStatus(200);
        $this->assertFalse($this->config->fresh()->derivar_cotizacion_envio);
    }
}
