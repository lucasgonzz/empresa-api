<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ExpenseConcept;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Misión asistente-tarjeta-sin-accion (10/10/2026) — la tarjeta que el texto promete tiene que existir.
 *
 * 🔴 EL CASO REAL. En `demo` (4.3.8, DeepSeek "Pensando ágil", modo "Resuelto") el asistente contestó
 * "Dejé la tarjeta para confirmar el borrado de la categoría Pruebas…" y, como PRIMER mensaje de una
 * conversación nueva, "Encontré la subcategoría Alquiler. Dejo la tarjeta para agendar el pago del
 * 15/10 por 400.000…", y en los dos el mensaje quedó con `acciones: []`.
 *
 * Lo que protege este archivo, con un doble del modelo que devuelve ese texto SIN tool call:
 *
 * - que el turno reintente UNA vez, con la nota del sistema y en el modelo Profundo, y que si el
 *   reintento arma la tarjeta la persona lea el texto del modelo con su tarjeta colgada;
 * - que si el reintento tampoco la arma, la persona lea el texto honesto y no la mentira;
 * - que un `tool_use` con `stop_reason: end_turn` se ejecute (antes se tiraba), y uno cortado por
 *   `max_tokens` no;
 * - que una llamada escrita como texto (que el saneo borra) también se reintente;
 * - que un reintento que falla —HTTP o red— no le llegue a la persona como un error;
 * - que con una tarjeta pendiente de antes, el texto se deje;
 * - que NO dispare cuando no corresponde: tarjeta creada en el turno, pregunta u ofrecimiento,
 *   WhatsApp, sin acciones;
 * - y el detector, oración por oración.
 *
 * 🔴 Ningún test sale a la red: Http::fake en todos, con claves e ids de modelo inventados.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Tarjeta_prometida_sin_accion_Test extends TestCase
{
    use DatabaseTransactions;

    /** El texto del caso 2 de `demo`, tal cual. */
    const TEXTO_DEL_ALQUILER = 'Encontré la subcategoría Alquiler. Dejo la tarjeta para agendar el pago del 15/10 por 400.000, con el gasto asociado.';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-p63',
            'services.deepseek.api_key'         => 'clave-deepseek-de-prueba-p63',
            'services.deepseek.model'           => 'deepseek-general-p63',
            'services.deepseek.model_agil'      => 'deepseek-flash-p63',
            'services.deepseek.model_profundo'  => 'deepseek-pro-p63',
            'services.deepseek.model_vision'    => 'deepseek-vision-p63',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio tarjeta prometida P63',
            'company_name' => 'Ferreteria P63',
            'email'        => 'tarjeta-p63-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /* La configuración del caso real: DeepSeek ágil y el dueño en "resuelto" (el gasto deja tarjeta). */
        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = 'resuelto';
        $this->comercio->save();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Conversación con el pedido de la persona y el assistant pendiente.
     *
     * @param  string  $pedido
     * @param  bool  $con_acciones
     * @param  string|null  $canal  null = el panel del sistema.
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido = 'El alquiler de 400000 se paga el 15', $con_acciones = true, $canal = null)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $user = [
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
        ];

        $assistant = [
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => $con_acciones,
        ];

        if (! is_null($canal)) {
            $user['canal']      = $canal;
            $assistant['canal'] = $canal;
        }

        AiMessage::create($user);

        return [$conversation, AiMessage::create($assistant)];
    }

    /**
     * Subcategoría de gasto del comercio.
     *
     * @param  string  $nombre
     * @return ExpenseConcept
     */
    protected function subcategoria($nombre = 'Alquiler P63')
    {
        return ExpenseConcept::create([
            'num'     => (int) ExpenseConcept::where('user_id', $this->comercio->id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * El método de pago "Efectivo" del catálogo global (lo siembra CurrentAcountPaymentMethodSeeder).
     *
     * @return CurrentAcountPaymentMethod
     */
    protected function efectivo()
    {
        $metodo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->whereNull('c_a_payment_method_type_id')->where('id', '!=', 1)->first();

        if (is_null($metodo)) {
            $metodo = CurrentAcountPaymentMethod::create(['name' => 'Efectivo']);
        }

        return $metodo;
    }

    /**
     * Bloque tool_use de proponer_gasto, con datos completos (deja tarjeta).
     *
     * @param  ExpenseConcept  $subcategoria
     * @param  string  $id
     * @return array
     */
    protected function bloque_proponer_gasto($subcategoria, $id = 'toolu_p63_gasto')
    {
        return [
            'type'  => 'tool_use',
            'id'    => $id,
            'name'  => 'proponer_gasto',
            'input' => [
                'subcategoria_id' => $subcategoria->id,
                'monto'           => 400000,
                'pagos'           => [
                    ['metodo_de_pago_id' => $this->efectivo()->id],
                ],
            ],
        ];
    }

    /**
     * Una respuesta del proveedor con los bloques y el stop_reason que se le pidan.
     *
     * @param  string  $stop_reason
     * @param  array  $bloques
     * @return array
     */
    protected function respuesta($stop_reason, array $bloques)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => $stop_reason,
            'content'     => $bloques,
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto)
    {
        return $this->respuesta('end_turn', [['type' => 'text', 'text' => $texto]]);
    }

    /**
     * Una secuencia de respuestas del proveedor que, al agotarse, contesta un 500 en vez de tirar.
     *
     * 🔴 POR QUÉ NO Http::sequence() A SECAS (chequeo adversarial del 10/10/2026). Agotada, tira una
     * excepción ANTES de que Laravel grabe el pedido, y el reintento la ataja como una falla de red:
     * una llamada de más no quedaba en Http::recorded() y "un solo reintento" o "sin reintento" daban
     * verde aunque no fuera cierto. Con el 500, la llamada de más queda grabada y el conteo la ve.
     *
     * @return \Illuminate\Http\Client\ResponseSequence
     */
    protected function secuencia()
    {
        return Http::sequence()->whenEmpty(Http::response(['error' => ['type' => 'api_error', 'message' => 'la secuencia se agotó (P63)']], 500));
    }

    /**
     * Los bodies de todos los requests que salieron, en orden.
     *
     * @return array<int, array>
     */
    protected function bodies_enviados()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            $bodies[] = json_decode($par[0]->body(), true);
        }

        return $bodies;
    }

    /**
     * El texto del último turno de un body (string o bloques `text`).
     *
     * @param  array  $body
     * @return string
     */
    protected function ultimo_turno_como_texto(array $body)
    {
        $ultimo = $body['messages'][count($body['messages']) - 1];

        if (is_string($ultimo['content'])) {
            return $ultimo['content'];
        }

        $textos = [];

        foreach ($ultimo['content'] as $bloque) {
            if (($bloque['type'] ?? '') === 'text') {
                $textos[] = $bloque['text'];
            }
        }

        return implode("\n", $textos);
    }

    /**
     * @param  AiMessage  $assistant
     * @return int
     */
    protected function tarjetas_de($assistant)
    {
        return AiMessageAction::where('ai_message_id', $assistant->id)->count();
    }

    // ---------------------------------------------------------------------
    // El reintento
    // ---------------------------------------------------------------------

    /**
     * 🔴 El caso 2 de `demo`: el modelo dice "Dejo la tarjeta…" sin llamar a la herramienta. El turno
     * reintenta UNA vez, con la nota del sistema y en el Profundo; el reintento llama a proponer_gasto y
     * la persona lee el texto del modelo CON su tarjeta.
     *
     * @group chat-ia
     * @test
     */
    public function el_texto_que_afirma_una_tarjeta_sin_herramienta_reintenta_y_la_tarjeta_queda()
    {
        $alquiler = $this->subcategoria();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::TEXTO_DEL_ALQUILER), 200)
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($alquiler)]), 200)
                ->push($this->end_turn('Listo, te dejé la tarjeta del alquiler para confirmar.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Listo, te dejé la tarjeta del alquiler para confirmar.', $texto);
        $this->assertSame(1, $this->tarjetas_de($assistant), 'El reintento tendría que haber dejado la tarjeta del gasto.');

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies, 'Texto sin tarjeta, reintento con la herramienta y texto final: tres llamadas.');

        /* La primera vuelta es la del Ágil, como siempre. */
        $this->assertSame(config('services.deepseek.model_agil'), $bodies[0]['model']);

        /* El reintento: el texto del modelo como turno del asistente, y la nota del sistema después. */
        $mensajes = $bodies[1]['messages'];
        $this->assertSame('assistant', $mensajes[count($mensajes) - 2]['role']);
        $this->assertSame(self::TEXTO_DEL_ALQUILER, $mensajes[count($mensajes) - 2]['content'], 'El turno del asistente va como texto, no con sus bloques.');
        $this->assertSame('user', $mensajes[count($mensajes) - 1]['role']);
        $this->assertStringContainsString('[El sistema revisó tu respuesta', $this->ultimo_turno_como_texto($bodies[1]));
        $this->assertStringContainsString('NO se creó ninguna', $this->ultimo_turno_como_texto($bodies[1]));

        /* Y escalado: la decisión de cargar la toma el Profundo. */
        $this->assertSame(config('services.deepseek.model_profundo'), $bodies[1]['model'], 'El reintento va al modelo Profundo.');

        /*
         * Pensando: en el turno no hubo ningún tool_use sin `thinking`, así que DeepSeek no rechaza el
         * historial (el 400 de 1e9711bd era prenderlo con uno así de por medio).
         */
        $this->assertSame('enabled', $bodies[1]['thinking']['type']);
    }

    /**
     * Si el reintento TAMBIÉN afirma una tarjeta sin armarla, la persona no lee la mentira: lee el texto
     * honesto. Y queda el rastro en el log para saber qué camino fue.
     *
     * @group chat-ia
     * @test
     */
    public function si_el_reintento_tampoco_la_arma_la_persona_lee_el_texto_honesto()
    {
        Log::spy();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::TEXTO_DEL_ALQUILER), 200)
                ->push($this->end_turn('Dejé la tarjeta del alquiler para que la confirmes.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO, $texto);
        $this->assertSame(0, $this->tarjetas_de($assistant));
        $this->assertCount(2, $this->bodies_enviados(), 'Un solo reintento: nunca dos.');

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) use ($assistant) {
            return strpos($mensaje, 'se reemplaza por el texto honesto') !== false
                && $contexto['ai_message_id'] === $assistant->id
                && $contexto['hubo_reintento'] === true
                && $contexto['herramientas_pedidas'] === []
                && $contexto['stop_reason'] === 'end_turn'
                && array_key_exists('el_saneo_saco_algo', $contexto);
        })->once();
    }

    /**
     * (D) La herramienta se pidió pero contestó "faltan" (sin datos): no hay tarjeta. La nota del
     * reintento le nombra lo que pidió para que mire lo que le contestó.
     *
     * @group chat-ia
     * @test
     */
    public function si_la_herramienta_contesto_faltan_la_nota_nombra_lo_que_pidio()
    {
        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_p63_faltan',
                    'name'  => 'proponer_gasto',
                    'input' => [],
                ]]), 200)
                ->push($this->end_turn('Dejé la tarjeta del alquiler para confirmar.'), 200)
                ->push($this->end_turn('¿De qué subcategoría es el gasto?'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('¿De qué subcategoría es el gasto?', $texto, 'El texto honesto del modelo, después del reintento, queda tal cual.');
        $this->assertSame(0, $this->tarjetas_de($assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);
        $this->assertStringContainsString('Pediste proponer_gasto', $this->ultimo_turno_como_texto($bodies[2]));

        /*
         * 🔴 El turno tuvo un tool_use del Ágil SIN bloque `thinking`: el reintento va al Profundo pero
         * con el thinking APAGADO, o DeepSeek rechaza el historial con un 400 (el de 1e9711bd).
         */
        $this->assertSame(config('services.deepseek.model_profundo'), $bodies[2]['model']);
        $this->assertSame('disabled', $bodies[2]['thinking']['type']);
    }

    /**
     * El reintento se come las vueltas que quedan pidiendo herramientas y no llega a escribir: vuelve el
     * texto de antes y la guarda lo cambia por el honesto. Nunca un "se me cortó la conexión".
     *
     * @group chat-ia
     * @test
     */
    public function si_el_reintento_se_come_las_vueltas_la_persona_lee_el_texto_honesto()
    {
        $secuencia = $this->secuencia()->push($this->end_turn(self::TEXTO_DEL_ALQUILER), 200);

        for ($vuelta = 2; $vuelta <= AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES; $vuelta++) {
            $secuencia->push($this->respuesta('tool_use', [[
                'type'  => 'tool_use',
                'id'    => 'toolu_p63_vuelta_' . $vuelta,
                'name'  => 'consultar_opciones_de_carga',
                'input' => [],
            ]]), 200);
        }

        Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO, $texto);
        $this->assertCount(AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES, $this->bodies_enviados(), 'Ni una vuelta más que el techo.');
    }

    // ---------------------------------------------------------------------
    // (B) El tool_use que el bucle tiraba
    // ---------------------------------------------------------------------

    /**
     * 🔴 (B) El proveedor manda "Dejo la tarjeta…" + el `tool_use` con `stop_reason: end_turn`. Antes el
     * bucle tomaba el texto como final y tiraba la llamada; ahora la ejecuta y la tarjeta queda.
     *
     * @group chat-ia
     * @test
     */
    public function un_tool_use_con_stop_reason_end_turn_se_ejecuta_igual()
    {
        $alquiler = $this->subcategoria();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('end_turn', [
                    ['type' => 'text', 'text' => 'Dejo la tarjeta para agendar el pago del alquiler.'],
                    $this->bloque_proponer_gasto($alquiler),
                ]), 200)
                ->push($this->end_turn('Te dejé la tarjeta del alquiler para confirmar.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Te dejé la tarjeta del alquiler para confirmar.', $texto);
        $this->assertSame(1, $this->tarjetas_de($assistant), 'El tool_use con end_turn tendría que haberse ejecutado.');

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies, 'Ningún reintento: la tarjeta existe.');
        $this->assertStringContainsString('"tool_use_id":"toolu_p63_gasto"', json_encode($bodies[1]));
    }

    /**
     * Con `max_tokens` el `tool_use` puede venir cortado: NO se ejecuta (sería cargar con datos a
     * medias). Ahí entra la guarda, y el turno del asistente del reintento va como texto, sin el
     * `tool_use` que exigiría su `tool_result`.
     *
     * @group chat-ia
     * @test
     */
    public function un_tool_use_cortado_por_max_tokens_no_se_ejecuta()
    {
        $alquiler = $this->subcategoria();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('max_tokens', [
                    ['type' => 'text', 'text' => 'Dejo la tarjeta para agendar el pago del alquiler.'],
                    $this->bloque_proponer_gasto($alquiler),
                ]), 200)
                ->push($this->end_turn('¿Con qué método lo pagás?'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('¿Con qué método lo pagás?', $texto);
        $this->assertSame(0, $this->tarjetas_de($assistant), 'Un tool_use cortado por max_tokens no se ejecuta.');

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);
        $this->assertStringNotContainsString('tool_use', json_encode($bodies[1]['messages']), 'El reintento no puede llevar el tool_use cortado.');
    }

    // ---------------------------------------------------------------------
    // (C) La llamada escrita como texto
    // ---------------------------------------------------------------------

    /**
     * (C) El modelo escribe la llamada como texto: el saneo borra ese renglón (nombra una herramienta) y
     * queda sólo "Dejo la tarjeta…". También se reintenta, y el log lo marca.
     *
     * @group chat-ia
     * @test
     */
    public function una_llamada_escrita_como_texto_tambien_se_reintenta()
    {
        Log::spy();

        $alquiler = $this->subcategoria();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn("Dejo la tarjeta para agendar el pago del alquiler.\nproponer_gasto({\"monto\": 400000})"), 200)
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($alquiler)]), 200)
                ->push($this->end_turn('Listo, te dejé la tarjeta.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Listo, te dejé la tarjeta.', $texto);
        $this->assertSame(1, $this->tarjetas_de($assistant));
        $this->assertCount(3, $this->bodies_enviados());

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'se reintenta una vez') !== false && $contexto['el_saneo_saco_algo'] === true;
        })->once();
    }

    // ---------------------------------------------------------------------
    // El reintento que falla
    // ---------------------------------------------------------------------

    /**
     * Un reintento que vuelve con error HTTP no le llega a la persona como "se me cortó la conexión":
     * vuelve el texto de antes y la guarda lo cambia por el honesto.
     *
     * @group chat-ia
     * @test
     */
    public function si_el_reintento_vuelve_con_error_la_persona_lee_el_texto_honesto_y_no_un_error()
    {
        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::TEXTO_DEL_ALQUILER), 200)
                ->push(['error' => ['type' => 'api_error', 'message' => 'caído']], 500),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO, $texto);
        $this->assertCount(2, $this->bodies_enviados());
    }

    /**
     * Lo mismo con una falla de red (la llamada tira excepción en vez de devolver una respuesta).
     *
     * @group chat-ia
     * @test
     */
    public function si_el_reintento_se_corta_por_la_red_la_persona_lee_el_texto_honesto()
    {
        $llamadas = 0;
        $primera  = $this->end_turn(self::TEXTO_DEL_ALQUILER);

        Http::fake([
            'api.deepseek.com/*' => function () use (&$llamadas, $primera) {
                $llamadas++;

                if ($llamadas === 1) {
                    return Http::response($primera, 200);
                }

                throw new ConnectionException('se cortó la red (P63)');
            },
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO, $texto);
        $this->assertSame(2, $llamadas);
    }

    // ---------------------------------------------------------------------
    // Cuándo se deja el texto
    // ---------------------------------------------------------------------

    /**
     * Una conversación con pedidos previos: cada uno es un par (pedido de la persona, respuesta del
     * asistente) y la respuesta puede llevar una tarjeta `propuesta`. Al final, el pedido nuevo y el
     * assistant pendiente.
     *
     * @param  array<int, array{0: string, 1: string, 2: bool}>  $pares  [pedido, respuesta, con tarjeta pendiente]
     * @param  string  $pedido_nuevo
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_con_historia(array $pares, $pedido_nuevo)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        foreach ($pares as $indice => $par) {

            AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $par[0], 'estado' => 'listo']);

            $respuesta = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => $par[1], 'estado' => 'listo', 'acciones_habilitadas' => true]);

            if ($par[2]) {
                AiMessageAction::create([
                    'ai_conversation_id' => $conversation->id,
                    'ai_message_id'      => $respuesta->id,
                    'user_id'            => $this->comercio->id,
                    'auth_user_id'       => $this->comercio->id,
                    'tipo'               => 'gasto',
                    'clave'              => 'gasto:p63-' . $indice,
                    'estado'             => 'propuesta',
                    'datos'              => [],
                    'presentacion'       => ['titulo' => 'Gasto', 'renglones' => [], 'aviso' => null],
                ]);
            }
        }

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido_nuevo, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Con una tarjeta `propuesta` en la respuesta ANTERIOR, el texto que habla de una tarjeta puede
     * estar hablando de ésa (*"¿y la tarjeta?"*): después del reintento, se deja.
     *
     * @group chat-ia
     * @test
     */
    public function con_una_tarjeta_pendiente_en_la_respuesta_anterior_el_texto_se_deja()
    {
        list($conversation, $assistant) = $this->conversacion_con_historia([
            ['Cargame el alquiler de 400000', 'Te dejé la tarjeta.', true],
        ], '¿Y la tarjeta?');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Te dejé la tarjeta, confirmala cuando quieras.'), 200)
                ->push($this->end_turn('Te dejé la tarjeta: tocá Confirmar cuando quieras.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Te dejé la tarjeta: tocá Confirmar cuando quieras.', $texto);
        $this->assertCount(2, $this->bodies_enviados());
    }

    /**
     * 🔴 El caso 1 de `demo` fue el TERCER pedido de la conversación. Una tarjeta vieja sin confirmar
     * de dos pedidos atrás no salva la mentira del tercero: sólo cuenta la respuesta inmediatamente
     * anterior.
     *
     * @group chat-ia
     * @test
     */
    public function una_tarjeta_pendiente_de_dos_pedidos_atras_no_salva_la_mentira()
    {
        list($conversation, $assistant) = $this->conversacion_con_historia([
            ['Dame de alta el proveedor Distribuidora Norte', 'Te dejé la tarjeta del alta.', true],
            ['Cambiale el teléfono a 3415551234', 'Listo, quedó cambiado.', false],
        ], 'Borrá la categoría Pruebas');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Dejé la tarjeta para confirmar el borrado de la categoría Pruebas. Ojo: al borrarla se van también sus subcategorías.'), 200)
                ->push($this->end_turn('Dejé la tarjeta para confirmar el borrado de la categoría Pruebas.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO, $texto);
        $this->assertCount(2, $this->bodies_enviados());
    }

    /**
     * 🔴 Después de una confirmación determinista (el "dale" a la ÚNICA tarjeta pendiente de la
     * respuesta anterior se confirma en código, antes del modelo) la guarda no corre: la carga es de
     * una tarjeta de otro mensaje, éste no tiene ninguna, y el texto que la cuenta no puede terminar
     * cambiado por "No pude armar la tarjeta" — la persona la volvería a pedir.
     *
     * @group chat-ia
     * @test
     */
    public function despues_de_una_confirmacion_determinista_la_guarda_no_corre()
    {
        $alquiler = $this->subcategoria();

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Cargame el alquiler de 400000', 'estado' => 'listo']);

        $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Te dejé la tarjeta del alquiler para confirmar.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

        /* La tarjeta, por el despacho real: datos válidos, así la confirmación determinista sale bien. */
        $propuesta = HerramientasDeCarga::ejecutar('proponer_gasto', $this->bloque_proponer_gasto($alquiler)['input'], $conversation, $anterior);

        $this->assertFalse($propuesta['is_error'], 'La propuesta del fixture tendría que haber dejado la tarjeta.');

        $tarjeta = AiMessageAction::where('ai_message_id', $anterior->id)->firstOrFail();

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'dale', 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Te dejé la tarjeta del alquiler para confirmar.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Te dejé la tarjeta del alquiler para confirmar.', $texto, 'Con la confirmación determinista, el texto del modelo queda tal cual.');
        $this->assertCount(1, $this->bodies_enviados(), 'Sin reintento.');
        $this->assertStringContainsString('[El sistema ya confirmó la tarjeta #' . $tarjeta->id, $this->ultimo_turno_como_texto($this->bodies_enviados()[0]), 'La confirmación determinista tuvo que correr y salir bien (su nota viaja pegada al "dale").');
        $this->assertSame('confirmada', $tarjeta->fresh()->estado);
    }

    /**
     * (C) con UNA sola oración que nombra la herramienta: el saneo la deja vacía y a la persona le llega
     * el texto crudo, así que la guarda mira el crudo y reintenta igual.
     *
     * @group chat-ia
     * @test
     */
    public function una_sola_oracion_que_nombra_la_herramienta_tambien_se_reintenta()
    {
        $alquiler = $this->subcategoria();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Dejo la tarjeta con proponer_gasto para el alquiler.'), 200)
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($alquiler)]), 200)
                ->push($this->end_turn('Listo, te dejé la tarjeta.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Listo, te dejé la tarjeta.', $texto);
        $this->assertSame(1, $this->tarjetas_de($assistant));
        $this->assertCount(3, $this->bodies_enviados());
    }

    /**
     * Si el turno sí dejó la tarjeta, el texto que la nombra no miente: ninguna vuelta de más.
     *
     * @group chat-ia
     * @test
     */
    public function si_el_turno_dejo_la_tarjeta_no_hay_reintento()
    {
        $alquiler = $this->subcategoria();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($alquiler)]), 200)
                ->push($this->end_turn('Te dejé la tarjeta del alquiler para confirmar.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Te dejé la tarjeta del alquiler para confirmar.', $texto);
        $this->assertSame(1, $this->tarjetas_de($assistant));
        $this->assertCount(2, $this->bodies_enviados());
    }

    /**
     * Una pregunta o un ofrecimiento no afirman nada: sin reintento, el texto tal cual.
     *
     * @group chat-ia
     * @test
     */
    public function una_pregunta_un_pedido_de_datos_o_una_consulta_no_disparan_el_reintento()
    {
        $textos = [
            '¿Te dejo la tarjeta para agendar el pago del 15?',
            'Si querés, te dejo la tarjeta para el alquiler. ¿De qué subcategoría es?',
            /* 🔴 Pedir el dato que falta: si disparara, la persona nunca vería la pregunta. */
            '¿Cuánto fue el flete? Con eso te dejo la tarjeta.',
            'Si me pasás el monto, te dejo la tarjeta.',
            /* 🔴 Una consulta sobre ventas con tarjeta de crédito: no es una tarjeta del asistente. */
            'Te dejo el detalle de las ventas con tarjeta de ayer: 3 por $ 45.000.',
        ];

        /* UNA secuencia para todos los pedidos: un segundo Http::fake no pisa al primero, se le suma. */
        $secuencia = $this->secuencia();

        foreach ($textos as $texto_del_modelo) {
            $secuencia->push($this->end_turn($texto_del_modelo), 200);
        }

        Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        foreach ($textos as $texto_del_modelo) {

            list($conversation, $assistant) = $this->conversacion();

            $texto = (new AsistenteIaService())->responder($conversation, $assistant);

            $this->assertSame($texto_del_modelo, $texto);
        }

        $this->assertCount(count($textos), $this->bodies_enviados(), 'Una llamada por pedido, ninguna de más.');
    }

    /**
     * Por WhatsApp no hay tarjeta (se confirma por texto) y sin acciones el asistente es de solo
     * lectura: en los dos la guarda no corre.
     *
     * @group chat-ia
     * @test
     */
    public function por_whatsapp_y_sin_acciones_la_guarda_no_corre()
    {
        /* UNA secuencia para los dos pedidos: un segundo Http::fake no pisa al primero, se le suma. */
        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Dejé la tarjeta para confirmar.'), 200)
                ->push($this->end_turn('Dejé la tarjeta para confirmar.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        foreach ([[true, AiMessage::CANAL_WHATSAPP], [false, null]] as $caso) {

            list($conversation, $assistant) = $this->conversacion('Cargame el alquiler', $caso[0], $caso[1]);

            $texto = (new AsistenteIaService())->responder($conversation, $assistant);

            $this->assertSame('Dejé la tarjeta para confirmar.', $texto);
        }

        $this->assertCount(2, $this->bodies_enviados(), 'Una llamada por pedido, ninguna de más.');
    }

    // ---------------------------------------------------------------------
    // El detector
    // ---------------------------------------------------------------------

    /**
     * Oración por oración: lo que afirma una tarjeta y lo que no.
     *
     * @group chat-ia
     * @test
     */
    public function el_detector_reconoce_las_afirmaciones_y_deja_pasar_el_resto()
    {
        $afirman = [
            'Dejé la tarjeta para confirmar el borrado de la categoría Pruebas. Ojo: al borrarla se van también sus subcategorías…',
            self::TEXTO_DEL_ALQUILER,
            'Te dejé la tarjeta del flete para confirmar.',
            'Listo, te armé la tarjeta.',
            'Ahí tenés la tarjeta para confirmar el alta.',
            'La tarjeta queda para que la confirmes.',
            'Tocá Confirmar en la tarjeta y queda cargado.',
            'Así que te dejé la tarjeta con el monto nuevo.',
            'deje la tarjeta lista',
            'Te voy a dejar la tarjeta para el pago.',
            "Encontré el proveedor.\nTe preparé la tarjeta con el teléfono nuevo.",
            /* Del chequeo adversarial del 10/10: la afirmación con una pregunta u ofrecimiento DESPUÉS. */
            'Te dejé la tarjeta para confirmar el borrado; si querés cambiar algo, avisame.',
            'Te dejé la tarjeta para borrar la categoría Pruebas, ¿la confirmás?',
            'Te muestro la tarjeta: si te parece bien, tocá Confirmar.',
            /* Sin la palabra "tarjeta", o con otro orden. */
            'Dejé para confirmar el borrado de la categoría Pruebas.',
            'Te dejé el gasto listo para que lo confirmes.',
            'Listo, quedó armada la tarjeta del alquiler.',
            'La tarjeta del alquiler por $ 400.000 queda para que la confirmes.',
            'La tarjeta está lista para que la revises.',
            'Ya tenés la tarjeta abajo.',
            'Te creé la tarjeta del gasto de flete.',
            'La tarjeta tiene todo. Dale a Confirmar.',
            'Tocá en la tarjeta el botón Confirmar.',
            'La categoría Pruebas no tiene artículos así que te dejé la tarjeta para borrarla.',
            'Sí, te dejo la tarjeta para agendar el pago.',
        ];

        foreach ($afirman as $texto) {
            $this->assertTrue(TarjetaPrometidaIaHelper::afirma_una_tarjeta($texto), 'Tendría que afirmar: ' . $texto);
        }

        $no_afirman = [
            '¿Te dejo la tarjeta para agendar el pago del 15?',
            'Si querés, te dejo la tarjeta para el alquiler.',
            'Cuando me pases el monto te dejo la tarjeta.',
            'No te dejé la tarjeta porque falta el monto.',
            'Todavía no hay tarjeta para confirmar: me falta el proveedor.',
            'Avisame el monto para que te deje la tarjeta.',
            'La tarjeta del gasto quedó confirmada: Gasto N° 88.',
            'Tenés 12 tornillos.',
            'Quedó registrado el gasto.',
            'La tarjeta de crédito Visa tiene un recargo del 10 %.',
            TarjetaPrometidaIaHelper::TEXTO_HONESTO,
            '',
            /* Del chequeo adversarial del 10/10: pedir un dato, consultas con "tarjeta", tarjetas viejas. */
            '¿Cuánto fue el flete? Con eso te dejo la tarjeta.',
            'Si me pasás el monto, te dejo la tarjeta.',
            'Decime a qué caja va y te armo la tarjeta.',
            'Te dejo el detalle de las ventas con tarjeta de ayer:',
            'Acá tenés las ventas con tarjeta de esta semana:',
            'Te armé el resumen: 3 ventas con tarjeta y 2 en efectivo.',
            'Ahí está el recargo de la tarjeta Naranja: 15 %.',
            'Te propongo subir el recargo de la tarjeta al 12 %.',
            'Sí, ayer te dejé la tarjeta del alquiler y la confirmaste: quedó agendado para el 15/10.',
            'Listo, la tarjeta que te dejé quedó confirmada: Gasto N° 88.',
            'Para confirmarlo, entrá a Presupuestos, abrilo y tocá Confirmar.',
            'Mañana te toca confirmar el pedido con Coca.',
            'Hay 3 ventas con tarjeta que quedaron pendientes de cobro.',
            '- Tarjeta Visa: 4 cupones, quedan pendientes 2',
            'Te dejo el stock de las tarjetas SUBE:',
            'Te conviene la tarjeta que te deje menos comisión.',
            /* Del tercer chequeo: el botón sin tarjeta, los verbos genéricos y otras "tarjetas". */
            'Para guardar, tocá Confirmar.',
            'Tocá el botón Confirmar de la pantalla de Compras.',
            'Pasé la tarjeta al cliente Juan.',
            'Te paso la tarjeta del cliente: Juan Pérez.',
            'El cargo por usar la tarjeta lo absorbe el negocio.',
            'Te armé la tarjeta de descuentos del cliente.',
            'Pagó en tarjeta: $ 12.000.',
        ];

        foreach ($no_afirman as $texto) {
            $this->assertFalse(TarjetaPrometidaIaHelper::afirma_una_tarjeta($texto), 'No tendría que afirmar: ' . $texto);
        }
    }
}
