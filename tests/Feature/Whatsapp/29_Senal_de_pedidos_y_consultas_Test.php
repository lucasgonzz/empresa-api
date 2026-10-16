<?php

namespace Tests\Feature\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Http\Controllers\Helpers\WhatsappChatHelper;
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
use ReflectionMethod;
use Tests\TestCase;

/**
 * Misión senal-pedidos-y-consultas-del-agente (16/10/2026): cuando el agente de WhatsApp arma un pedido
 * («Que haga el pedido por WhatsApp») o promete «lo consulto y te confirmo», el chat queda marcado hasta
 * que una persona lo atienda. Antes la promesa vivía solo en el texto: el chat dejaba de estar en rojo y no
 * quedaba ningún aviso.
 *
 * Lo que protege este archivo:
 *
 * - 🔴 El marcador `[PEDIDO:…]` / `[CONSULTA:…]` NUNCA llega al texto que sale: ni por el job (respuesta
 *   automática) ni por «Sugerir respuesta», ni en el medio de la oración, ni en minúscula, ni sin cerrar, ni
 *   junto con `[FOTO:…]` en cualquiera de los dos órdenes.
 * - La marca viaja con el borrador (`ai_follow_up`) y el CHAT recién se marca cuando el mensaje SALIÓ (envío
 *   automático, «mandalo ahora» del operador, sugerencia confirmada). Un borrador descartado no promete nada.
 * - Se apaga cuando UNA PERSONA escribe en el chat (texto, foto, plantilla) o con «Ya lo atendí»; no la apagan
 *   el agente, el comprobante automático, el recordatorio de cobro ni que el cliente vuelva a escribir. Editar
 *   el texto del borrador limpia la marca del mensaje; solo pausarlo no.
 * - Es un eje APARTE del rojo/amarillo (`estado_pendiente`): un chat puede tener las dos cosas a la vez.
 * - `GET whatsapp-chats/resumen` cuenta pedidos y consultas acotado al dueño; el índice y el broadcast
 *   exponen los campos nuevos.
 * - El prompt trae la consigna ANTES de las capas opcionales (nombre, horario, objetivo): esas se suman siempre al final y el prompt de antes queda como prefijo (lo fijan los tests 21 y 28).
 *
 * 🔴 `Queue::fake()` en casi todos los casos: con `QUEUE_CONNECTION=sync` el `->delay()` del auto-envío se
 * ignora y la respuesta saldría antes de poder mirarla. El caso del envío inmediato (demora 0) es la excepción
 * a propósito: ahí el envío es en `sync` y es justo lo que se quiere ver.
 */
class Senal_de_pedidos_y_consultas_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** Teléfono del cliente final. */
    const PHONE = '5493416029999';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    /** Texto crudo que devuelve el fake de la IA; se lee al momento de la llamada. */
    protected $texto_de_la_ia = 'Respuesta de prueba.';

    /** Payloads que viajaron a Kapso. */
    protected $payloads_kapso = [];

    /** true = Kapso contesta con un error 500 (el envío no sale). */
    protected $kapso_falla = false;

    /** Callback que corre en el medio de la llamada a la IA (simula que una persona actúa mientras el modelo piensa). */
    protected $durante_la_ia = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F29',
            'company_name' => 'Ferreteria F29',
            'email'        => 'whatsapp-f29-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F29',
            'email'    => 'whatsapp-f29-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'                  => $this->comercio->id,
            'kapso_api_key'            => 'kapso-f29',
            'phone_number_id'          => '5491100000029',
            'webhook_secret'           => 'secreto-f29',
            'is_active'                => true,
            'ai_enabled_default'       => true,
            // Con espera de confirmación la respuesta queda `a_confirmar` y se puede mirar antes de que salga.
            'ai_confirm_delay_seconds' => 120,
        ]);

        $this->dar_extension($this->comercio);
    }

    /**
     * Asigna la extensión al comercio (creando la fila del catálogo si la base del slot todavía no la
     * tiene sembrada). Mismo patrón que el resto de los tests del módulo.
     *
     * @param  User  $comercio
     * @return void
     */
    protected function dar_extension(User $comercio)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'WhatsApp',
            ]);
        }

        $comercio->extencions()->attach($extencion->id);
        $comercio->load('extencions');
    }

    /**
     * Stubs de las APIs externas, guardando lo que viaja a Kapso. El `'*'` final evita que una request sin
     * stub salga a la red de verdad.
     *
     * @return void
     */
    protected function fakes_de_red()
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => function () {
                if (! is_null($this->durante_la_ia)) {
                    call_user_func($this->durante_la_ia);
                }

                return Http::response([
                    'model'   => 'claude-de-prueba',
                    'content' => [['type' => 'text', 'text' => $this->texto_de_la_ia]],
                    'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                ], 200);
            },
            'api.kapso.ai/*' => function ($request) {
                $this->payloads_kapso[] = $request->data();

                if ($this->kapso_falla) {
                    return Http::response(['error' => 'kapso caido'], 500);
                }

                return Http::response(['messages' => [['id' => 'wamid.F29']]], 200);
            },
            '*' => Http::response([], 200),
        ]);

        config(['services.anthropic.api_key' => 'clave-de-prueba']);
    }

    /**
     * Chat con la ventana de 24 h abierta y un mensaje del cliente sin responder.
     *
     * @param  User|null  $owner
     * @param  string  $phone
     * @return WhatsappChat
     */
    protected function chat_con_entrante(User $owner = null, $phone = self::PHONE)
    {
        $owner = $owner ?: $this->comercio;

        $chat = WhatsappChat::create([
            'user_id'         => $owner->id,
            'phone'           => $phone,
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'Hola, quiero 2 taladros',
        ]);

        // El scheduler bumpea el token de debounce sin encolar nada: deja el 1 vigente, que es el que
        // después lleva el job que se corre a mano.
        (new WhatsappAgentScheduler())->cancel((int) $chat->id);

        return $chat;
    }

    /**
     * Corre el job que genera la respuesta, igual que lo haría la cola.
     *
     * @param  WhatsappChat  $chat
     * @return void
     */
    protected function correr_job(WhatsappChat $chat)
    {
        (new GenerateWhatsappAiReplyJob((int) $chat->id, 1))->handle(app(WhatsappAgentScheduler::class));
    }

    /**
     * La respuesta del agente que quedó guardada (la única saliente del chat).
     *
     * @param  WhatsappChat  $chat
     * @return WhatsappChatMessage|null
     */
    protected function saliente(WhatsappChat $chat)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Deja al chat con un pedido ya marcado, como si el agente lo hubiera dejado y la respuesta hubiera salido.
     *
     * @param  WhatsappChat  $chat
     * @param  string  $tipo
     * @return WhatsappChat
     */
    protected function marcar($chat, $tipo = 'pedido')
    {
        $chat->mark_follow_up($tipo, 'Detalle de prueba');
        $chat->save();

        return $chat->fresh();
    }

    /**
     * Le pide al service la respuesta con el texto crudo dado (con marcadores o sin ellos).
     *
     * @param  string  $texto
     * @return array
     */
    protected function generar($texto)
    {
        $this->texto_de_la_ia = $texto;
        // Un teléfono distinto por llamada: hay un índice único (negocio, teléfono) y el test llama varias veces.
        $chat = $this->chat_con_entrante($this->comercio, '549341' . random_int(1000000, 9999999));

        return (new WhatsappBotAiService())->generate_response_with_photo($chat, $this->config);
    }

    /* ------------------------------------------------------------------------------------------------
     | El marcador: se recorta siempre y la señal sobrevive
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function el_service_saca_las_marcas_y_devuelve_el_tipo_y_el_detalle()
    {
        Queue::fake();
        $this->fakes_de_red();

        $casos = [
            'pedido al final'             => ["Listo, te lo anoto.\n[PEDIDO:2 taladros Bosch, a nombre de Marcos]", 'Listo, te lo anoto.', 'pedido', '2 taladros Bosch, a nombre de Marcos', ''],
            'consulta al final'           => ["Lo consulto y te confirmo.\n[CONSULTA:si hacen envíos a San Benito]", 'Lo consulto y te confirmo.', 'consulta', 'si hacen envíos a San Benito', ''],
            'sin detalle'                 => ["Lo consulto y te confirmo.\n[CONSULTA]", 'Lo consulto y te confirmo.', 'consulta', '', ''],
            'foto antes de la marca'      => ["Este te sirve, te lo dejo anotado.\n[FOTO:7791234567890]\n[PEDIDO:1 taladro]", 'Este te sirve, te lo dejo anotado.', 'pedido', '1 taladro', '7791234567890'],
            'foto despues de la marca'    => ["Este te sirve, te lo dejo anotado.\n[PEDIDO:1 taladro]\n[FOTO:7791234567890]", 'Este te sirve, te lo dejo anotado.', 'pedido', '1 taladro', '7791234567890'],
            'en minuscula y sin cerrar'   => ["Lo consulto.\n[consulta: envío a domicilio", 'Lo consulto.', 'consulta', 'envío a domicilio', ''],
            'en el medio de la oracion'   => ['Te lo anoto [PEDIDO:2 tornillos] y te confirmamos.', 'Te lo anoto y te confirmamos.', 'pedido', '2 tornillos', ''],
            'las dos: gana el pedido'     => ["Anotado.\n[CONSULTA:el envío]\n[PEDIDO:3 mechas]", 'Anotado.', 'pedido', '3 mechas', ''],
            'las dos al reves'            => ["Anotado.\n[PEDIDO:3 mechas]\n[CONSULTA:el envío]", 'Anotado.', 'pedido', '3 mechas', ''],
            'marca inventada: se saca sin señal' => ["Sí, aceptamos tarjeta en hasta 3 cuotas.\n[CUOTA:3 cuotas sin interés]", 'Sí, aceptamos tarjeta en hasta 3 cuotas.', '', '', ''],
            'marca inventada + promesa: la red lo marca' => ["Lo consulto y te confirmo si aceptamos cuotas.\n[CUOTA:confirmar si aceptan tarjeta]", 'Lo consulto y te confirmo si aceptamos cuotas.', 'consulta', '', ''],
            'marca inventada tras la real' => ["Anotado.\n[PEDIDO:2 taladros]\n[ENVIO]", 'Anotado.', 'pedido', '2 taladros', ''],
            'marca inventada despues de la foto: no se lleva la foto' => ["Este te sirve.\n[FOTO:7791234567890]\n[CUOTA:x]", 'Este te sirve.', '', '', '7791234567890'],
            'red de seguridad: lo prometió y no marcó' => ["Eso lo consulto y te confirmo.", 'Eso lo consulto y te confirmo.', 'consulta', '', ''],
            'red de seguridad no pisa la marca del modelo' => ["Lo averiguo y te aviso.\n[CONSULTA:el flete]", 'Lo averiguo y te aviso.', 'consulta', 'el flete', ''],
            'red de seguridad: variante con el encargado' => ["Lo consulto con el encargado y te confirmo.", 'Lo consulto con el encargado y te confirmo.', 'consulta', '', ''],
            'red de seguridad: voy a averiguar' => ["Voy a averiguar y te aviso.", 'Voy a averiguar y te aviso.', 'consulta', '', ''],
            'un ofrecimiento condicional no es una promesa' => ["Si querés te armo el pedido y te lo confirmamos con el total.", 'Si querés te armo el pedido y te lo confirmamos con el total.', '', '', ''],
            'ofrecer consultar tampoco es prometerlo' => ["Si querés, lo consulto y te confirmo.", 'Si querés, lo consulto y te confirmo.', '', '', ''],
            'una respuesta de pura cortesía no deja marca' => ["¡De nada! Cualquier cosa, avisame.\n[CONSULTA:si hacen envíos]", '¡De nada! Cualquier cosa, avisame.', '', '', ''],
            'cortesía con un sustantivo suelto («consulta») tampoco' => ["¡De nada! Cualquier consulta, avisame.\n[CONSULTA]", '¡De nada! Cualquier consulta, avisame.', '', '', ''],
            'cortesía con «pedido» como sustantivo tampoco' => ["¡Gracias a vos por tu pedido!\n[PEDIDO:2 taladros]", '¡Gracias a vos por tu pedido!', '', '', ''],
            'cortesía que además promete: la marca se queda' => ["¡De nada! Quedate tranquilo que en cuanto sepa algo te aviso.\n[CONSULTA:si hacen envíos]", '¡De nada! Quedate tranquilo que en cuanto sepa algo te aviso.', 'consulta', 'si hacen envíos', ''],
            'el compromiso con otras palabras no pierde la marca: reservar' => ["Perfecto, te reservo los 2 taladros a nombre de Marcos.\n[PEDIDO:2 taladros, Marcos]", 'Perfecto, te reservo los 2 taladros a nombre de Marcos.', 'pedido', '2 taladros, Marcos', ''],
            'el compromiso con otras palabras no pierde la marca: dejé' => ["Dejé tu compra lista.\n[PEDIDO:1 taladro]", 'Dejé tu compra lista.', 'pedido', '1 taladro', ''],
            'el compromiso con otras palabras no pierde la marca: el jefe' => ["Lo hablo con mi jefe y te digo.\n[CONSULTA:descuento por cantidad]", 'Lo hablo con mi jefe y te digo.', 'consulta', 'descuento por cantidad', ''],
            'el compromiso con otras palabras no pierde la marca: una persona' => ["Te paso con una persona del equipo.\n[CONSULTA:reclamo por una compra]", 'Te paso con una persona del equipo.', 'consulta', 'reclamo por una compra', ''],
            'la palabra con las letras separadas' => ["Dejame averiguarlo y te confirmo.\n\n[C O N S U L T A:si hacen envíos a San Benito]", 'Dejame averiguarlo y te confirmo.', 'consulta', 'si hacen envíos a San Benito', ''],
            'marca inventada con las letras separadas' => ["Sí, aceptamos tarjeta en hasta 3 cuotas.\n[C U O T A:3 cuotas]", 'Sí, aceptamos tarjeta en hasta 3 cuotas.', '', '', ''],
            'foto con el código en blanco: sale del texto' => ["Tenemos el taladro.\n[FOTO:   ]", 'Tenemos el taladro.', '', '', ''],
            'la marca iba primera: el cuerpo sale recortado' => ["[CONSULTA:x] Lo consulto y te confirmo.", 'Lo consulto y te confirmo.', 'consulta', 'x', ''],
            'un corchete de cierre de más' => ["Lo consulto y te confirmo.\n[CONSULTA:x]]", 'Lo consulto y te confirmo.', 'consulta', 'x', ''],
            'marca cortada: corchete suelto' => ["Hoy cerramos a las 20:00.\n\n[C", 'Hoy cerramos a las 20:00.', '', '', ''],
            'marca cortada: a medio escribir' => ["Lo consulto y te confirmo.\n[CONSU", 'Lo consulto y te confirmo.', 'consulta', '', ''],
            'foto cortada' => ["Este te sirve.\n[FOTO:77", 'Este te sirve.', '', '', ''],
            'marca con el detalle cortado' => ["Lo consulto y te confirmo.\n[CONSULTA:el fl", 'Lo consulto y te confirmo.', 'consulta', 'el fl', ''],
            'marca envuelta en markdown'  => ["Anotado.\n*[PEDIDO:2 taladros]*", 'Anotado.', 'pedido', '2 taladros', ''],
            'corchetes anidados'          => ["Dale, lo consulto y te confirmo.\n[CONSULTA:precio de [la mecha] de 8 mm]", 'Dale, lo consulto y te confirmo.', 'consulta', 'precio de la mecha de 8 mm', ''],
            'corchetes legítimos no se tocan' => ['Tu [Pedido #123] está en camino. [Pedido mínimo: $5000]. Mirá [Pedido online](https://x.com/p).', 'Tu [Pedido #123] está en camino. [Pedido mínimo: $5000]. Mirá [Pedido online](https://x.com/p).', '', '', ''],
        ];

        foreach ($casos as $nombre => $caso) {
            $resultado = $this->generar($caso[0]);

            $this->assertEquals($caso[1], $resultado['body'], "[$nombre] el cuerpo sale limpio.");
            $this->assertEquals($caso[2], $resultado['follow_up'], "[$nombre] el tipo.");
            $this->assertEquals($caso[3], $resultado['follow_up_note'], "[$nombre] el detalle.");
            $this->assertEquals($caso[4], $resultado['bar_code'], "[$nombre] la foto sigue andando.");
            if ($nombre !== 'corchetes legítimos no se tocan') {
                $this->assertDoesNotMatchRegularExpression('/\[\s*(PEDIDO|CONSULTA|FOTO)[\s:\]]/iu', $resultado['body'], "[$nombre] ni rastro del marcador.");
            }
        }
    }

    /**
     * @group whatsapp
     * @test
     */
    public function sin_marcas_el_texto_sale_byte_por_byte_como_antes()
    {
        Queue::fake();
        $this->fakes_de_red();

        $texto = "Sí, tenemos taladros.\n\nEl Bosch [GSB-13] está $89.500 y hay 4. Mirá el [Link] de la tienda. ¿Te armo el pedido? Quedan [PEDIDOS] sin tocar.";
        $resultado = $this->generar($texto);

        $this->assertEquals($texto, $resultado['body'], 'Sin marcas no se toca ni un espacio ([PEDIDOS], [Link] y [GSB-13] no son marcas).');
        $this->assertEquals('', $resultado['follow_up']);
        $this->assertEquals('', $resultado['follow_up_note']);
        $this->assertEquals('', $resultado['bar_code']);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_detalle_se_acota_a_200_caracteres()
    {
        Queue::fake();
        $this->fakes_de_red();

        $resultado = $this->generar("Anotado.\n[PEDIDO:" . str_repeat('tornillo ', 60) . ']');

        $this->assertEquals('pedido', $resultado['follow_up']);
        $this->assertLessThanOrEqual(201, mb_strlen($resultado['follow_up_note'], 'UTF-8'), '200 caracteres más los puntos suspensivos.');
        $this->assertStringEndsWith('…', $resultado['follow_up_note']);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_prompt_trae_la_consigna_de_las_marcas_antes_de_las_capas_opcionales()
    {
        $service = new WhatsappBotAiService();
        $metodo = new ReflectionMethod($service, 'build_system_prompt');
        $metodo->setAccessible(true);

        $sin_objetivo = $metodo->invoke($service, $this->config);

        $this->assertStringContainsString('[PEDIDO:', $sin_objetivo);
        $this->assertStringContainsString('[CONSULTA:', $sin_objetivo);
        $this->assertStringContainsString('[FOTO:', $sin_objetivo, 'La consigna de la foto sigue estando.');

        // 🔴 Es una parte FIJA: va después de la consigna de la foto y ANTES de las capas opcionales (nombre del
        // negocio, horario, objetivo), que se suman siempre al final para que el prompt de antes quede de prefijo.
        $pos_foto = strpos($sin_objetivo, 'Esa línea es una marca interna del sistema');
        $pos_marcas = strpos($sin_objetivo, 'Marcas internas para que el sistema');
        $pos_nombre = strpos($sin_objetivo, 'Nombre del negocio:');
        $this->assertNotFalse($pos_foto);
        $this->assertNotFalse($pos_marcas);
        $this->assertNotFalse($pos_nombre);
        $this->assertGreaterThan($pos_foto, $pos_marcas, 'Después de la consigna de la foto.');
        $this->assertLessThan($pos_nombre, $pos_marcas, 'Antes de las capas opcionales.');

        // Con objetivo, la capa del objetivo se suma al final y el prompt de antes sigue siendo su prefijo.
        $this->config->agent_objective = 'order_by_whatsapp';
        $con_objetivo = $metodo->invoke($service, $this->config);
        $this->assertGreaterThan(strlen($sin_objetivo), strlen($con_objetivo));
        $this->assertSame(0, strpos($con_objetivo, $sin_objetivo), 'El prompt de antes queda intacto como prefijo.');

        // Lo que el prompt le dice al modelo no nombra "al negocio" en tercera persona (regla fija de hablar como el negocio).
        $consigna = substr($sin_objetivo, $pos_marcas, $pos_nombre - $pos_marcas);
        $this->assertStringNotContainsString('alguien del negocio', $consigna);
        $this->assertStringNotContainsString('una persona del negocio', $consigna);
    }

    /* ------------------------------------------------------------------------------------------------
     | Qué se guarda y cuándo se marca el chat
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function el_job_guarda_la_marca_en_el_borrador_y_el_chat_no_se_marca_hasta_que_sale()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Perfecto, te dejo anotado el pedido y lo confirmamos.\n[PEDIDO:2 taladros, a nombre de Marcos]";

        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);

        $mensaje = $this->saliente($chat);
        $this->assertNotNull($mensaje);
        $this->assertEquals('a_confirmar', $mensaje->ai_status);
        $this->assertEquals('Perfecto, te dejo anotado el pedido y lo confirmamos.', $mensaje->body, 'El marcador no está en el texto guardado.');
        $this->assertEquals('pedido', $mensaje->ai_follow_up);
        $this->assertEquals('2 taladros, a nombre de Marcos', $mensaje->ai_follow_up_note);

        $chat->refresh();
        $this->assertNull($chat->follow_up, 'Todavía no salió: el cliente no recibió ninguna promesa.');
        $this->assertEquals('esperando_aprobacion', $chat->estado_pendiente(), 'El amarillo de siempre sigue funcionando.');
        $this->assertEquals(0, count($this->payloads_kapso));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function confirmar_el_borrador_marca_el_chat_y_lo_que_sale_a_kapso_va_sin_el_marcador()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Lo consulto y te confirmo.\n[CONSULTA:si hacen envíos a San Benito]";

        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);
        $mensaje = $this->saliente($chat);

        $this->actingAs($this->empleado, 'web');
        $response = $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm');
        $response->assertStatus(200);
        $this->assertEquals('consulta', $response->json('model.ai_follow_up'));

        $chat->refresh();
        $this->assertEquals('consulta', $chat->follow_up);
        $this->assertEquals('si hacen envíos a San Benito', $chat->follow_up_note);
        $this->assertNotNull($chat->follow_up_at);

        $this->assertCount(1, $this->payloads_kapso);
        $this->assertStringNotContainsString('[CONSULTA', json_encode($this->payloads_kapso[0]), 'Lo que viaja a Kapso no trae el marcador.');
        $this->assertEquals('Lo consulto y te confirmo.', $this->saliente($chat)->body);

        // El índice y el broadcast exponen el estado del chat.
        $indice = $this->getJson('api/whatsapp-chats');
        $indice->assertStatus(200);
        $fila = collect($indice->json('models'))->firstWhere('id', $chat->id);
        $this->assertEquals('consulta', $fila['follow_up']);
        $this->assertEquals('si hacen envíos a San Benito', $fila['follow_up_note']);

        $payload = (new WhatsappChatUpdated((int) $this->comercio->id, $chat))->broadcastWith();
        $this->assertEquals('consulta', $payload['chat']['follow_up']);
        $this->assertEquals('si hacen envíos a San Benito', $payload['chat']['follow_up_note']);
    }

    /**
     * El envío automático con demora 0 es el caso del dueño que no revisa nada: sale en el momento y es
     * justo donde antes no quedaba ninguna señal. SIN `Queue::fake()`: el despacho es en `sync`.
     *
     * @group whatsapp
     * @test
     */
    public function el_envio_automatico_inmediato_marca_el_chat()
    {
        $this->fakes_de_red();
        $this->config->ai_confirm_delay_seconds = 0;
        $this->config->save();
        $this->texto_de_la_ia = "Anotado tu pedido, te lo confirmamos.\n[PEDIDO:2 taladros]";

        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);

        $mensaje = $this->saliente($chat);
        $this->assertEquals('enviado', $mensaje->ai_status, 'Salió solo.');
        $this->assertCount(1, $this->payloads_kapso);

        $chat->refresh();
        $this->assertEquals('pedido', $chat->follow_up, 'El chat quedó marcado aunque el negocio no tocó nada.');
        $this->assertEquals('2 taladros', $chat->follow_up_note);
        $this->assertNull($chat->estado_pendiente(), 'El rojo/amarillo ya no está: la marca es lo único que avisa.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function descartar_el_borrador_no_marca_nada()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Anotado.\n[PEDIDO:2 taladros]";

        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);
        $mensaje = $this->saliente($chat);

        $this->actingAs($this->empleado, 'web');
        $this->deleteJson('api/whatsapp-chats/messages/' . $mensaje->id)->assertStatus(200);

        $this->assertNull($chat->fresh()->follow_up, 'Un borrador descartado no le prometió nada a nadie.');
        $this->assertEquals('sin_responder', $chat->fresh()->estado_pendiente());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_respuesta_sin_marca_no_deja_ninguna_senal()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = 'El Bosch sale $89.500 y tenemos 4.';

        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);
        $mensaje = $this->saliente($chat);

        $this->assertNull($mensaje->ai_follow_up);
        $this->assertNull($mensaje->ai_follow_up_note);

        $this->actingAs($this->empleado, 'web');
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(200);

        $chat->refresh();
        $this->assertNull($chat->follow_up);
        $this->assertNull($chat->follow_up_note);
        $this->assertNull($chat->follow_up_at);
    }

    /**
     * 🔴 «Sugerir respuesta» usa la misma llamada a la IA: la marca viaja con el borrador y el marcador
     * nunca está en lo que ve el operador (ni en lo que sale al cliente cuando lo confirma).
     *
     * @group whatsapp
     * @test
     */
    public function sugerir_respuesta_nunca_trae_el_marcador_y_la_marca_viaja_con_el_borrador()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "No tengo ese dato, lo consulto y te confirmo.\n[FOTO:7791234567890]\n[CONSULTA:medidas del taladro]";

        $chat = $this->chat_con_entrante();
        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $chat->id . '/suggest');
        $response->assertStatus(200);

        $this->assertEquals('No tengo ese dato, lo consulto y te confirmo.', $response->json('suggestion'));
        $this->assertDoesNotMatchRegularExpression('/\[\s*(PEDIDO|CONSULTA|FOTO)/iu', (string) $response->json('suggestion'));
        $this->assertDoesNotMatchRegularExpression('/\[\s*(PEDIDO|CONSULTA|FOTO)/iu', (string) $response->json('model.body'));
        $this->assertEquals('consulta', $response->json('model.ai_follow_up'));

        $mensaje = $this->saliente($chat);
        $this->assertEquals('consulta', $mensaje->ai_follow_up);
        $this->assertNull($chat->fresh()->follow_up, 'Es un borrador: todavía no salió.');

        // El operador lo manda tal cual: la promesa la hizo el negocio y sigue en pie.
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(200);
        $this->assertEquals('consulta', $chat->fresh()->follow_up);
        $this->assertDoesNotMatchRegularExpression('/\[\s*(PEDIDO|CONSULTA|FOTO)/iu', json_encode($this->payloads_kapso[0], JSON_UNESCAPED_UNICODE), 'A Kapso no viaja ningún marcador.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_estado_de_color_y_el_seguimiento_son_ejes_independientes()
    {
        $chat = $this->chat_con_entrante();
        // Un mensaje del agente ya enviado deja al chat al día en cuanto a color...
        WhatsappChatHelper::store_outbound_ai_message($chat, 'Te lo anoto.', 'wamid.X');
        $chat = $this->marcar($chat, 'pedido');
        $this->assertNull($chat->estado_pendiente(), 'Sin entrante nuevo ni borrador: ni rojo ni amarillo.');
        $this->assertEquals('pedido', $chat->follow_up);

        // ...y si el cliente vuelve a escribir, se pone en rojo SIN perder el pedido.
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'y mandame también una mecha',
        ]);
        $chat->refresh();
        $this->assertEquals('sin_responder', $chat->estado_pendiente());
        $this->assertEquals('pedido', $chat->follow_up, 'Que el cliente escriba no atiende el pedido.');
    }

    /* ------------------------------------------------------------------------------------------------
     | Cuándo se apaga
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function cuando_una_persona_escribe_en_el_chat_el_seguimiento_se_apaga()
    {
        Queue::fake();
        $this->fakes_de_red();

        $chat = $this->marcar($this->chat_con_entrante(), 'pedido');
        $this->actingAs($this->empleado, 'web');

        $this->postJson('api/whatsapp-chats/' . $chat->id . '/messages', ['body' => 'Hola Marcos, ya te confirmo el pedido.'])
            ->assertStatus(201);

        $chat->refresh();
        $this->assertNull($chat->follow_up);
        $this->assertNull($chat->follow_up_note);
        $this->assertNull($chat->follow_up_at);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_plantilla_o_una_foto_de_una_persona_tambien_lo_apagan()
    {
        $chat = $this->marcar($this->chat_con_entrante(), 'consulta');
        WhatsappChatHelper::store_outbound_template_message($chat, 'Hola, te confirmamos el envío.', 'wamid.T', $this->empleado->id, 'cc_cli_prueba');
        $this->assertNull($chat->fresh()->follow_up, 'Una plantilla mandada por una persona atiende la consulta.');

        $chat = $this->marcar($chat->fresh(), 'pedido');
        WhatsappChatHelper::store_outbound_media_message($chat, 'foto', 'wamid.M', $this->empleado->id, ['media_type' => 'image']);
        $this->assertNull($chat->fresh()->follow_up, 'Una foto mandada por una persona también.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_agente_el_comprobante_automatico_el_recordatorio_y_el_cliente_no_lo_apagan()
    {
        $chat = $this->marcar($this->chat_con_entrante(), 'pedido');

        // Otro mensaje del agente.
        WhatsappChatHelper::store_outbound_ai_message($chat, 'Te sigo ayudando.', 'wamid.A');
        $this->assertEquals('pedido', $chat->fresh()->follow_up, 'El agente no atiende su propia promesa.');

        // Comprobante automático (sin empleado) y recordatorio de cobro (aunque lo dispare un empleado).
        WhatsappChatHelper::store_outbound_document_message($chat->fresh(), 'Comprobante', 'wamid.D', null, 'sistema', 'https://ejemplo.test/c.pdf');
        $this->assertEquals('pedido', $chat->fresh()->follow_up, 'El comprobante automático no es una persona.');

        WhatsappChatHelper::store_outbound_recordatorio_message($chat->fresh(), 'Recordatorio de cobro', 'wamid.R', $this->empleado->id);
        $this->assertEquals('pedido', $chat->fresh()->follow_up, 'Un recordatorio de cobro no le contesta el pedido al cliente.');

        // El cliente vuelve a escribir.
        $payload = [];
        $chat_fresco = WhatsappChatHelper::store_inbound_message($this->comercio->id, self::PHONE, 'hola?', $payload, $this->config);
        $this->assertEquals('pedido', $chat_fresco->fresh()->follow_up, 'Que el cliente escriba no atiende el pedido.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function editar_el_texto_del_borrador_limpia_la_marca_y_solo_pausar_no()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Anotado.\n[PEDIDO:2 taladros]";

        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);
        $mensaje = $this->saliente($chat);
        $this->actingAs($this->empleado, 'web');

        // Solo pausar el auto-envío (entrar en modo edición): la marca sigue.
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id)->assertStatus(200);
        $mensaje->refresh();
        $this->assertEquals('pedido', $mensaje->ai_follow_up, 'Pausar no cambia el contenido.');

        // Reescribir el texto: una persona se hizo cargo.
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id, ['body' => 'Hola, ya está tu pedido, pasalo a buscar.'])->assertStatus(200);
        $mensaje->refresh();
        $this->assertNull($mensaje->ai_follow_up);
        $this->assertNull($mensaje->ai_follow_up_note);

        // Y al confirmarlo, el chat NO queda marcado.
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(200);
        $this->assertNull($chat->fresh()->follow_up);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_pedido_gana_a_la_consulta_y_follow_up_at_guarda_desde_cuando_espera()
    {
        $chat = $this->chat_con_entrante();

        $this->assertTrue($chat->mark_follow_up('consulta', 'precio del flete'));
        $chat->save();
        $desde = $chat->fresh()->follow_up_at;
        $this->assertNotNull($desde);

        // Una consulta nueva del mismo tipo actualiza la nota pero NO mueve "desde cuándo espera".
        $chat = $chat->fresh();
        $this->travel(5)->minutes();
        $this->assertTrue($chat->mark_follow_up('consulta', 'medidas'));
        $chat->save();
        $chat->refresh();
        $this->assertEquals('medidas', $chat->follow_up_note);
        $this->assertEquals($desde->timestamp, $chat->follow_up_at->timestamp);

        // Un pedido sube la consulta.
        $this->assertTrue($chat->mark_follow_up('pedido', '2 taladros'));
        $chat->save();
        $chat->refresh();
        $this->assertEquals('pedido', $chat->follow_up);
        $this->assertGreaterThan($desde->timestamp, $chat->follow_up_at->timestamp);

        // Una consulta nueva NO baja un pedido pendiente.
        $this->assertFalse($chat->mark_follow_up('consulta', 'otra cosa'));
        $this->assertEquals('pedido', $chat->follow_up);
        $this->assertEquals('2 taladros', $chat->follow_up_note);

        // Un tipo inválido no marca nada.
        $this->assertFalse($this->chat_con_entrante($this->comercio, '5493416020000')->mark_follow_up('cualquier-cosa'));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function ya_lo_atendi_apaga_es_idempotente_y_respeta_la_pertenencia()
    {
        $chat = $this->marcar($this->chat_con_entrante(), 'pedido');

        $otro_comercio = User::create([
            'name'     => 'Otro comercio F29',
            'email'    => 'whatsapp-f29-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $this->dar_extension($otro_comercio);
        $chat_ajeno = $this->marcar($this->chat_con_entrante($otro_comercio, '5493416028888'), 'pedido');

        $this->actingAs($this->empleado, 'web');

        $response = $this->putJson('api/whatsapp-chats/' . $chat->id . '/resolve-follow-up');
        $response->assertStatus(200);
        $this->assertNull($response->json('model.follow_up'));
        $this->assertNull($chat->fresh()->follow_up);

        // Idempotente: sin nada que apagar vuelve a contestar 200.
        $this->putJson('api/whatsapp-chats/' . $chat->id . '/resolve-follow-up')->assertStatus(200);

        // El chat de otro negocio ni se toca ni se ve.
        $this->putJson('api/whatsapp-chats/' . $chat_ajeno->id . '/resolve-follow-up')->assertStatus(404);
        $this->assertEquals('pedido', $chat_ajeno->fresh()->follow_up);
    }

    /* ------------------------------------------------------------------------------------------------
     | El número del menú y el tablero
     | ------------------------------------------------------------------------------------------------ */

    /**
     * @group whatsapp
     * @test
     */
    public function el_resumen_cuenta_pedidos_y_consultas_solo_del_dueno()
    {
        $this->marcar($this->chat_con_entrante($this->comercio, '5493416021111'), 'pedido');
        $this->marcar($this->chat_con_entrante($this->comercio, '5493416022222'), 'pedido');
        $this->marcar($this->chat_con_entrante($this->comercio, '5493416023333'), 'consulta');
        $this->chat_con_entrante($this->comercio, '5493416024444'); // sin marca

        $otro_comercio = User::create([
            'name'     => 'Otro comercio F29b',
            'email'    => 'whatsapp-f29-otrob-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $this->marcar($this->chat_con_entrante($otro_comercio, '5493416025555'), 'pedido');

        $this->actingAs($this->empleado, 'web');
        $response = $this->getJson('api/whatsapp-chats/resumen');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('pedidos_para_confirmar'));
        $this->assertEquals(1, $response->json('consultas_pendientes'));
        $this->assertEquals(3, $response->json('chats_con_seguimiento'), 'Chats y no mensajes; el del otro negocio no entra.');
        // Los números de siempre siguen estando.
        $this->assertEquals(0, $response->json('mensajes_por_aprobar'));
        $this->assertEquals(0, $response->json('chats_esperando_aprobacion'));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_resumen_sin_chats_devuelve_todo_en_cero()
    {
        $this->actingAs($this->empleado, 'web');
        $response = $this->getJson('api/whatsapp-chats/resumen');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('chats_con_seguimiento'));
        $this->assertEquals(0, $response->json('pedidos_para_confirmar'));
        $this->assertEquals(0, $response->json('consultas_pendientes'));
        $this->assertEquals(0, $response->json('mensajes_por_aprobar'));
    }

    /* ------------------------------------------------------------------------------------------------
     | Casos que marcaron los chequeos independientes
     | ------------------------------------------------------------------------------------------------ */

    /**
     * 🔴 Un envío manual que FALLÓ no es una respuesta: el cliente no recibió nada y el pedido no se puede perder.
     *
     * @group whatsapp
     * @test
     */
    public function un_envio_manual_que_fallo_no_apaga_el_seguimiento()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->kapso_falla = true;

        $chat = $this->marcar($this->chat_con_entrante(), 'pedido');
        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $chat->id . '/messages', ['body' => 'Hola, ya te confirmo el pedido.']);
        $response->assertStatus(201);
        $this->assertFalse($response->json('enviado'), 'Premisa: el envío no salió.');

        $this->assertEquals('pedido', $chat->fresh()->follow_up, 'El cliente no recibió nada: el pedido sigue marcado.');

        // Con un empleado y sin id de Kapso, ni el helper directo apaga nada.
        WhatsappChatHelper::store_outbound_manual_message($chat->fresh(), 'otro intento', null, $this->empleado->id);
        $this->assertEquals('pedido', $chat->fresh()->follow_up);

        // Y cuando sí sale, se apaga.
        $this->kapso_falla = false;
        $this->postJson('api/whatsapp-chats/' . $chat->id . '/messages', ['body' => 'Ahora sí: tu pedido está confirmado.'])->assertStatus(201);
        $this->assertNull($chat->fresh()->follow_up);
    }

    /**
     * Si una persona REESCRIBE el borrador del agente y lo manda, le escribió al cliente: el seguimiento que el
     * chat ya tuviera se da por atendido. Mandarlo tal cual no lo apaga.
     *
     * @group whatsapp
     * @test
     */
    public function editar_y_mandar_un_borrador_del_agente_apaga_el_seguimiento_y_mandarlo_tal_cual_no()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = 'Hola, sí, el taladro sale $89.500.';

        // Chat A: la persona reescribe el borrador y lo manda.
        $chat_a = $this->marcar($this->chat_con_entrante(), 'pedido');
        $this->correr_job($chat_a);
        $borrador_a = $this->saliente($chat_a);
        $this->assertNull($borrador_a->ai_follow_up, 'Premisa: este borrador no trae marca.');

        $this->actingAs($this->empleado, 'web');
        $this->putJson('api/whatsapp-chats/messages/' . $borrador_a->id, ['body' => 'Hola Marcos, ya está tu pedido, pasá a buscarlo.'])->assertStatus(200);
        $this->putJson('api/whatsapp-chats/messages/' . $borrador_a->id . '/confirm')->assertStatus(200);

        $this->assertNull($chat_a->fresh()->follow_up, 'Una persona le escribió al cliente: el pedido se da por atendido.');
        $enviado = $borrador_a->fresh();
        $this->assertEquals('ia', $enviado->source, 'El remitente que se muestra sigue siendo la IA.');
        $this->assertEquals($this->empleado->id, (int) $enviado->sent_by_user_id);

        // Chat B: el borrador sale tal cual (nadie lo tocó): el pedido sigue pendiente.
        $chat_b = $this->marcar($this->chat_con_entrante($this->comercio, '5493416027777'), 'pedido');
        $this->correr_job($chat_b);
        $borrador_b = $this->saliente($chat_b);
        $this->putJson('api/whatsapp-chats/messages/' . $borrador_b->id . '/confirm')->assertStatus(200);

        $this->assertEquals('pedido', $chat_b->fresh()->follow_up, 'Confirmar sin tocar no es atender el pedido.');
    }

    /**
     * Un envío del agente que falla vuelve el borrador a `a_confirmar` CON su marca y no marca el chat (todavía no
     * salió ninguna promesa).
     *
     * @group whatsapp
     * @test
     */
    public function un_envio_del_agente_que_falla_deja_el_borrador_marcado_y_el_chat_sin_marcar()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Lo consulto y te confirmo.\n[CONSULTA:si hacen envíos]";

        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);
        $mensaje = $this->saliente($chat);

        $this->kapso_falla = true;
        $this->actingAs($this->empleado, 'web');
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(422);

        $mensaje->refresh();
        $this->assertEquals('a_confirmar', $mensaje->ai_status);
        $this->assertEquals('consulta', $mensaje->ai_follow_up, 'La marca sigue en el borrador para el reintento.');
        $this->assertNull($chat->fresh()->follow_up, 'No salió: el cliente no recibió ninguna promesa.');

        // El reintento que sí sale marca el chat.
        $this->kapso_falla = false;
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(200);
        $this->assertEquals('consulta', $chat->fresh()->follow_up);
    }

    /**
     * En un chat en simulación el envío se frena (el cliente no recibe nada) pero la respuesta queda en la
     * conversación y el seguimiento se marca igual: es lo que le deja ver al dueño cómo funciona.
     *
     * @group whatsapp
     * @test
     */
    public function un_chat_en_simulacion_tambien_queda_marcado()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Anotado tu pedido, te lo confirmamos.\n[PEDIDO:2 taladros]";

        $chat = $this->chat_con_entrante();
        $chat->last_inbound_simulated = 1;
        $chat->save();

        $this->correr_job($chat);
        $mensaje = $this->saliente($chat);

        $this->actingAs($this->empleado, 'web');
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(200);

        $this->assertCount(0, $this->payloads_kapso, 'El envío está frenado: no salió nada a Kapso.');
        $this->assertEquals('pedido', $chat->fresh()->follow_up);
    }

    /**
     * La respuesta que es SOLO la marca (sin texto para el cliente) no se persiste en el job y en «Sugerir
     * respuesta» dice la verdad: no había texto (antes decía «solo generó una foto»).
     *
     * @group whatsapp
     * @test
     */
    public function una_respuesta_que_es_solo_la_marca_no_se_manda_y_suggest_explica_por_que()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = '[PEDIDO:2 taladros]';

        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);
        $this->assertNull($this->saliente($chat), 'Sin texto no hay nada que persistir ni que mandarle al cliente.');

        $this->actingAs($this->empleado, 'web');
        $response = $this->postJson('api/whatsapp-chats/' . $chat->id . '/suggest');
        $response->assertStatus(422);
        $this->assertStringContainsString('no generó ningún texto', (string) $response->json('message'));
        $this->assertStringNotContainsString('foto', (string) $response->json('message'));
    }

    /**
     * El broadcast que sale al guardar el borrador lleva el chat tal cual está AHORA: si una persona apagó el
     * seguimiento mientras la IA pensaba, no se lo vuelve a mostrar.
     *
     * @group whatsapp
     * @test
     */
    public function el_broadcast_del_job_no_reenvia_un_seguimiento_que_una_persona_apago_mientras_pensaba_la_ia()
    {
        Queue::fake();
        Event::fake([WhatsappChatUpdated::class]);
        $this->fakes_de_red();
        $this->texto_de_la_ia = 'El Bosch sale $89.500.';

        $chat = $this->marcar($this->chat_con_entrante(), 'pedido');
        $this->durante_la_ia = function () use ($chat) {
            // «Ya lo atendí», en el medio de la llamada a la IA.
            $chat->clear_follow_up();
        };

        $this->correr_job($chat);

        Event::assertDispatched(WhatsappChatUpdated::class, function ($evento) {
            return ! is_null($evento->message) && $evento->message->ai_status === 'a_confirmar' && is_null($evento->chat->follow_up);
        });
    }

    /**
     * `clear_follow_up()` va por query a propósito: con un modelo VIEJO en memoria (que todavía cree que no hay
     * marca) tiene que apagarla igual.
     *
     * @group whatsapp
     * @test
     */
    public function apagar_el_seguimiento_funciona_con_un_modelo_viejo_en_memoria()
    {
        $chat = $this->chat_con_entrante();
        $viejo = WhatsappChat::find($chat->id);

        $otro = WhatsappChat::find($chat->id);
        $otro->mark_follow_up('pedido', '2 taladros');
        $otro->save();

        $this->assertNull($viejo->follow_up, 'Premisa: el modelo viejo no se enteró.');
        $this->assertTrue($viejo->clear_follow_up());
        $this->assertNull(WhatsappChat::find($chat->id)->follow_up);
    }

    /**
     * Un mismo tipo sin detalle (lo que marca la red de seguridad) no borra el detalle que ya había.
     *
     * @group whatsapp
     * @test
     */
    public function una_marca_del_mismo_tipo_sin_detalle_no_borra_el_detalle_anterior()
    {
        $chat = $this->chat_con_entrante();

        $chat->mark_follow_up('consulta', 'precio del flete');
        $chat->save();

        $chat = $chat->fresh();
        $chat->mark_follow_up('consulta', '');
        $chat->save();
        $this->assertEquals('precio del flete', $chat->fresh()->follow_up_note);

        $chat = $chat->fresh();
        $chat->mark_follow_up('consulta', null);
        $chat->save();
        $this->assertEquals('precio del flete', $chat->fresh()->follow_up_note);

        $chat = $chat->fresh();
        $chat->mark_follow_up('consulta', 'medidas');
        $chat->save();
        $this->assertEquals('medidas', $chat->fresh()->follow_up_note, 'Con detalle nuevo, el detalle nuevo.');
    }

    /**
     * «Ya lo atendí» avisa al resto de los operadores (broadcast) solo si había algo que apagar.
     *
     * @group whatsapp
     * @test
     */
    public function ya_lo_atendi_emite_el_broadcast_solo_si_habia_algo_que_apagar()
    {
        Event::fake([WhatsappChatUpdated::class]);

        $chat = $this->marcar($this->chat_con_entrante(), 'consulta');
        $this->actingAs($this->empleado, 'web');

        $this->putJson('api/whatsapp-chats/' . $chat->id . '/resolve-follow-up')->assertStatus(200);
        Event::assertDispatchedTimes(WhatsappChatUpdated::class, 1);

        $this->putJson('api/whatsapp-chats/' . $chat->id . '/resolve-follow-up')->assertStatus(200);
        Event::assertDispatchedTimes(WhatsappChatUpdated::class, 1);
    }

    /**
     * El comprobante que un empleado manda a mano es contestarle al cliente: apaga el seguimiento (el automático no).
     *
     * @group whatsapp
     * @test
     */
    public function un_comprobante_mandado_a_mano_por_un_empleado_apaga_el_seguimiento()
    {
        $chat = $this->marcar($this->chat_con_entrante(), 'pedido');

        WhatsappChatHelper::store_outbound_document_message($chat->fresh(), 'Comprobante', 'wamid.D1', null, 'sistema', 'https://ejemplo.test/c.pdf');
        $this->assertEquals('pedido', $chat->fresh()->follow_up, 'El automático no es una persona.');

        WhatsappChatHelper::store_outbound_document_message($chat->fresh(), 'Comprobante', 'wamid.D2', $this->empleado->id, 'manual', 'https://ejemplo.test/c.pdf');
        $this->assertNull($chat->fresh()->follow_up, 'El del empleado, sí.');
    }
}
