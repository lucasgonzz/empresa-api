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
 * Misión asistente-diario-20261128, P8b y P12 (CA16, CA17 feature y CA18) — TESTS DE ACEPTACIÓN: el agente de
 * WhatsApp reconoce lo que ya le contestó una PERSONA del negocio y no lo contradice ni lo deriva; y el resumen del
 * pedido por WhatsApp va renglón por renglón.
 *
 * EL CASO REAL (Truvari, 27/11/2026, chat 66): el comercio contestó a mano «para su zona no tiene costo de envío»
 * (también editando un borrador de «Sugerir respuesta», que queda con `source = ia` pero con `sent_by_user_id`); el
 * cliente repreguntó y el agente, que veía esa respuesta como un turno más suyo sin rótulo, contestó «lo confirma el
 * equipo»; y el filtro de cotizaciones sacaba «no tiene costo de envío» aunque lo hubiera dicho el comercio.
 *
 * Qué fija este archivo:
 *   CA16 el turno del manual (o del borrador de la IA editado por una persona) va con el rótulo
 *        «[Respuesta escrita por una persona del negocio] » y el `system` suma la consigna HUMAN_REPLIES_RULE; sin
 *        humanos el prompt es el de hoy;
 *   CA17 con lo que dijo el comercio en el historial, el filtro no deriva esa misma afirmación (el chat no queda
 *        marcado como consulta); sin el manual, el mismo texto se deriva como hoy;
 *   CA18 el objetivo «pedido por WhatsApp» pide un renglón por producto con su cantidad.
 *
 * 🔴 Estado esperado en el commit de tests: ROJOS `el_manual_va_rotulado_y_suma_la_consigna`,
 * `la_ia_editada_por_una_persona_tambien`, `lo_que_ya_dijo_el_comercio_no_se_deriva` (primera mitad) y
 * `el_resumen_del_pedido_va_por_renglon`. GUARDAS que ya pasan hoy: `sin_humanos_el_prompt_es_el_de_hoy` y la segunda
 * mitad de `lo_que_ya_dijo_el_comercio_no_se_deriva` (sin manual, el texto se deriva).
 *
 * 🔴 `Queue::fake()` en todos los casos (igual que el test 38): con `QUEUE_CONNECTION=sync` el `->delay()` del
 * auto-envío se ignora y la respuesta saldría antes de poder mirarla. Sin red.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Respuestas_del_comercio_en_el_historial_Test extends TestCase
{
    use DatabaseTransactions;

    const SLUG = 'agentes_ia';

    /** Frase fija del filtro de cotizaciones. */
    const FRASE = 'El costo de envío y el total final los confirma el equipo en breve.';

    /** El rótulo con el que tiene que entrar al historial lo que escribió una persona (con el espacio final). */
    const ROTULO = '[Respuesta escrita por una persona del negocio] ';

    /** Lo que contestó el comercio a mano. */
    const DICHO_POR_EL_COMERCIO = 'Para su zona no tiene costo de envío';

    /** @var User */
    protected $comercio;

    /** @var User Una persona del negocio (la que edita borradores y contesta a mano). */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    /** Texto crudo que devuelve el fake de la IA. */
    protected $texto_de_la_ia = 'Respuesta de prueba.';

    /** Payloads que viajaron al modelo, en orden. */
    protected $payloads_ia = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F44',
            'company_name' => 'Bebidas F44',
            'email'        => 'whatsapp-f44-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F44',
            'email'    => 'whatsapp-f44-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'                  => $this->comercio->id,
            'kapso_api_key'            => 'kapso-f44',
            'phone_number_id'          => '5491100000044',
            'webhook_secret'           => 'secreto-f44',
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

    /**
     * Dobla la red: los embeddings y el modelo (que devuelve `$texto_de_la_ia` y guarda lo que recibió).
     *
     * @return void
     */
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
     * Un chat vacío del comercio.
     *
     * @return \App\Models\WhatsappChat
     */
    protected function chat()
    {
        return WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => '549351' . random_int(1000000, 9999999),
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
    }

    /**
     * Un mensaje entrante del cliente (y el token de debounce al día para correr el job).
     *
     * @param  \App\Models\WhatsappChat  $chat
     * @param  string  $texto
     * @return void
     */
    protected function entrante(WhatsappChat $chat, $texto)
    {
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => $texto,
        ]);

        (new WhatsappAgentScheduler())->cancel((int) $chat->id);
    }

    /**
     * Un mensaje saliente ya enviado, con su origen.
     *
     * @param  \App\Models\WhatsappChat  $chat
     * @param  string  $texto
     * @param  string  $source  manual | ia ...
     * @param  int|null  $sent_by_user_id  La persona que lo escribió o editó (null = la IA sola).
     * @return \App\Models\WhatsappChatMessage
     */
    protected function saliente(WhatsappChat $chat, $texto, $source, $sent_by_user_id = null)
    {
        return WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => $source,
            'body'             => $texto,
            'ai_status'        => $source === 'ia' ? 'enviado' : null,
            'sent_by_user_id'  => $sent_by_user_id,
        ]);
    }

    /**
     * El chat de la escena del caso real: el cliente pregunta por el envío, una respuesta saliente y la repregunta.
     *
     * @param  string  $source  Origen de la respuesta saliente.
     * @param  int|null  $sent_by_user_id
     * @param  string  $texto_saliente
     * @return \App\Models\WhatsappChat
     */
    protected function chat_con_respuesta($source, $sent_by_user_id = null, $texto_saliente = self::DICHO_POR_EL_COMERCIO)
    {
        $chat = $this->chat();

        $this->entrante($chat, '¿cuánto sale el envío?');
        $this->saliente($chat, $texto_saliente, $source, $sent_by_user_id);
        $this->entrante($chat, '¿entonces no pago envío?');

        return $chat;
    }

    /**
     * Corre el job con el token vigente y devuelve la respuesta guardada (la saliente más nueva).
     *
     * @param  \App\Models\WhatsappChat  $chat
     * @return \App\Models\WhatsappChatMessage|null
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
     * El último payload que viajó al modelo.
     *
     * @return array
     */
    protected function ultimo_payload()
    {
        $this->assertNotEmpty($this->payloads_ia, 'El turno tenía que hablarle al modelo.');

        return $this->payloads_ia[count($this->payloads_ia) - 1];
    }

    /**
     * El `system` del último payload, como texto.
     *
     * @return string
     */
    protected function system_enviado()
    {
        $system = $this->ultimo_payload()['system'];

        return is_array($system) ? json_encode($system, JSON_UNESCAPED_UNICODE) : (string) $system;
    }

    /**
     * Los textos de los turnos `assistant` del último payload (un turno de texto es un string).
     *
     * @return array<int,string>
     */
    protected function turnos_del_asistente()
    {
        $textos = [];

        foreach ($this->ultimo_payload()['messages'] as $turno) {

            if ($turno['role'] !== 'assistant') {
                continue;
            }

            $textos[] = is_array($turno['content']) ? json_encode($turno['content'], JSON_UNESCAPED_UNICODE) : (string) $turno['content'];
        }

        return $textos;
    }

    /**
     * La consigna que se suma al prompt cuando hay respuestas de una persona; false si la constante no existe todavía.
     * La constante es privada: se lee por reflexión para no atar el test a su visibilidad.
     *
     * @return string|false
     */
    protected function consigna_de_respuestas_humanas()
    {
        return (new \ReflectionClass(WhatsappBotAiService::class))->getConstant('HUMAN_REPLIES_RULE');
    }

    /* ─────────────────────────────── CA16 ─────────────────────────────── */

    /**
     * 🔴 CA16 — Una respuesta `manual` entra al historial con el rótulo de «persona del negocio» y el `system` suma
     * la consigna que dice que vale y no se contradice.
     *
     * @group whatsapp
     * @test
     */
    public function el_manual_va_rotulado_y_suma_la_consigna()
    {
        $chat = $this->chat_con_respuesta('manual', $this->empleado->id);

        $this->texto_de_la_ia = 'Perfecto, queda registrado.';
        $this->correr_job($chat);

        $turnos = $this->turnos_del_asistente();

        $this->assertCount(1, $turnos, 'La respuesta saliente es un turno del asistente.');
        $this->assertSame(self::ROTULO . self::DICHO_POR_EL_COMERCIO, $turnos[0], 'Lleva el rótulo adelante y el texto tal cual.');

        $consigna = $this->consigna_de_respuestas_humanas();
        $this->assertNotFalse($consigna, 'Falta la constante HUMAN_REPLIES_RULE en WhatsappBotAiService.');
        $this->assertStringContainsString($consigna, $this->system_enviado());
        $this->assertStringContainsString('[Respuesta escrita por una persona del negocio]', $this->system_enviado());
    }

    /**
     * 🔴 CA16 — Un borrador de la IA que una persona editó (`source = ia` con `sent_by_user_id`) cuenta como del
     * comercio. Una respuesta `ia` sin `sent_by_user_id` NO lleva rótulo.
     *
     * @group whatsapp
     * @test
     */
    public function la_ia_editada_por_una_persona_tambien()
    {
        // Editada por una persona: rótulo y consigna.
        $chat = $this->chat_con_respuesta('ia', $this->empleado->id);

        $this->texto_de_la_ia = 'Perfecto, queda registrado.';
        $this->correr_job($chat);

        $this->assertSame([self::ROTULO . self::DICHO_POR_EL_COMERCIO], $this->turnos_del_asistente());
        $this->assertNotFalse($this->consigna_de_respuestas_humanas(), 'Falta la constante HUMAN_REPLIES_RULE en WhatsappBotAiService.');
        $this->assertStringContainsString($this->consigna_de_respuestas_humanas(), $this->system_enviado());

        // Dicha por la IA sola: sin rótulo y sin consigna (en esta mitad ya pasa hoy; lo que fija es que el rótulo
        // depende de `sent_by_user_id` y no de que el origen sea `ia`).
        $this->payloads_ia = [];

        $sola = $this->chat_con_respuesta('ia', null, 'Para su zona consultamos con el equipo.');
        $this->correr_job($sola);

        $this->assertSame(['Para su zona consultamos con el equipo.'], $this->turnos_del_asistente());
        $this->assertStringNotContainsString('[Respuesta escrita por una persona del negocio]', $this->system_enviado());
    }

    /**
     * Guarda (verde hoy) — Sin ningún mensaje de una persona, el historial y el prompt son los de siempre: ni rótulo
     * ni consigna. Protege los tests 38 y 4 que comparan el `system` y el historial.
     *
     * @group whatsapp
     * @test
     */
    public function sin_humanos_el_prompt_es_el_de_hoy()
    {
        $chat = $this->chat_con_respuesta('ia', null, 'Hola, ¿en qué lo ayudo?');

        $this->texto_de_la_ia = 'Hola, claro.';
        $this->correr_job($chat);

        $this->assertSame(['Hola, ¿en qué lo ayudo?'], $this->turnos_del_asistente());
        $this->assertStringNotContainsString('[Respuesta escrita por una persona del negocio]', $this->system_enviado());
        $this->assertStringNotContainsString('los escribió el negocio en este chat', $this->system_enviado(), 'Sin humanos no hay consigna.');
    }

    /* ─────────────────────────────── CA17 ─────────────────────────────── */

    /**
     * 🔴 CA17 — Con la opción `derivar_cotizacion_envio` prendida y el modelo repitiendo lo que ya dijo el comercio
     * («no tiene costo de envío»), el cuerpo guardado es ese texto sin la frase fija y el chat NO queda marcado como
     * consulta. Sin el mensaje del comercio, el mismo texto se deriva (comportamiento de hoy, guarda).
     *
     * @group whatsapp
     * @test
     */
    public function lo_que_ya_dijo_el_comercio_no_se_deriva()
    {
        $texto = 'En Córdoba capital no tiene costo de envío.';

        // Con la respuesta del comercio en el historial: no se deriva.
        $this->texto_de_la_ia = $texto;
        $con_manual = $this->correr_job($this->chat_con_respuesta('manual', $this->empleado->id));

        $this->assertNotNull($con_manual, 'El job tenía que dejar la respuesta.');
        $this->assertSame($texto, $con_manual->body, 'Lo que ya dijo el comercio sale tal cual, sin la frase fija.');
        $this->assertNull($con_manual->ai_follow_up, 'El chat no queda marcado como consulta por cotización.');

        // Guarda (verde hoy): sin el manual, el mismo texto es una afirmación del modelo y se deriva.
        $sin_manual = $this->chat();
        $this->entrante($sin_manual, '¿entonces no pago envío?');

        $derivada = $this->correr_job($sin_manual);

        $this->assertSame(self::FRASE, $derivada->body);
        $this->assertSame('consulta', $derivada->ai_follow_up);
    }

    /* ─────────────────────────────── CA18 ─────────────────────────────── */

    /**
     * 🔴 CA18 — El objetivo «pedido por WhatsApp» le pide al agente repasar el pedido con un renglón por producto y
     * su cantidad.
     *
     * @group whatsapp
     * @test
     */
    public function el_resumen_del_pedido_va_por_renglon()
    {
        $this->config->agent_objective = 'order_by_whatsapp';
        $this->config->save();

        $chat = $this->chat();
        $this->entrante($chat, 'Quiero 2 cajas de Malbec');

        $this->texto_de_la_ia = 'Perfecto.';
        $this->correr_job($chat);

        // El prompt parte las frases a mano: se compara con los espacios normalizados.
        $system = preg_replace('/\s+/u', ' ', $this->system_enviado());

        $this->assertStringContainsString('que el cliente te deje el pedido por este chat', $system, 'La capa del objetivo está (precondición).');
        $this->assertStringContainsString('un renglón por producto con su cantidad', $system);
    }
}
