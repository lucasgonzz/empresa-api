<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAgentScheduler;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión whatsapp-sidebar-multimedia — U14/14: la foto del producto que recomienda el agente.
 *
 * El agente cierra su respuesta con `[FOTO:<código de barras>]` en una línea sola y el job
 * resuelve el artículo del negocio, saca el marcador del texto y guarda la respuesta con la
 * `hosting_url` del catálogo. Al confirmarse sale como UNA imagen con el texto de epígrafe, no
 * como dos mensajes.
 *
 * Lo que protege este archivo:
 *
 * 🔴 QUE EL CLIENTE NUNCA VEA UN `[FOTO:...]` EN SU WHATSAPP. El marcador se saca del cuerpo
 * SIEMPRE, pase lo que pase: el modelo inventa un código, el artículo no tiene foto cargada, el
 * texto se pasó del tope, la base se cae. Todos esos son caminos de falla frecuentes, y la
 * degradación correcta es "sale como texto normal", nunca "sale con basura de implementación a
 * la vista". Lo cubren los cuatro casos de degradación, empezando por
 * `un_codigo_que_no_existe_igual_sale_sin_el_marcador()`, que es el caso crítico 14.3 del plan.
 *
 * 🔴 Y QUE NO LO VEA POR NINGÚN CAMINO, NO SOLO POR EL DEL AGENTE AUTOMÁTICO. El marcador se
 * filtraba por el botón "Sugerir respuesta" (`POST whatsapp-chats/{id}/suggest`), que devolvía el
 * texto crudo de la IA: el borrador le caía al operador terminado en `[FOTO:7791234567890]` y, si
 * lo mandaba sin leer hasta abajo, eso viajaba a WhatsApp. Ese agujero existía porque el recorte
 * vivía en UNO de los dos consumidores del service en vez de en el service; ahora vive en el
 * productor y lo protegen `el_borrador_del_operador_nunca_trae_el_marcador()` y
 * `el_service_le_devuelve_el_texto_limpio_a_cualquier_caller()`, que es el que le cierra la puerta
 * a cualquier consumidor que aparezca mañana.
 *
 * - Que la foto viaje por `link` y no por upload: `images.hosting_url` ya es una URL pública
 *   absoluta del catálogo, la misma que ve cualquiera en la tienda.
 * - Que el epígrafe sea el cuerpo del mensaje, así el cliente recibe una sola cosa.
 * - Que una respuesta sin marcador siga saliendo byte por byte como antes de esta misión.
 * - Que un chat en simulación no le mande la foto a un número que nunca escribió.
 *
 * 🔴 `Queue::fake()` en todos los casos, por dos motivos: con `QUEUE_CONNECTION=sync` el
 * `->delay()` del auto-envío se ignora y el mensaje saldría solo en vez de quedarse esperando
 * confirmación; y crear un artículo con la extensión puesta encola su embedding.
 *
 * 🔴 UN SOLO `Http::fake()` POR MÉTODO, con el `'*'` último: en Laravel 8 los stubs se acumulan
 * y una request sin stub sale a la red de verdad.
 */
class Foto_del_producto_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** Teléfono del cliente final. */
    const PHONE = '5493416014444';

    /** Código de barras del artículo que el agente va a recomendar. */
    const BAR_CODE = '7791234567890';

    /** URL pública de la foto del catálogo (la misma que ve cualquiera en la tienda). */
    const HOSTING_URL = 'https://api-empresa.comerciocity.test/public/storage/taladro.jpg';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    /** Texto que devuelve el fake de Anthropic; se lee al momento de la llamada. */
    protected $texto_de_la_ia = 'Respuesta del agente.';

    /** Payloads que viajaron a Kapso. */
    protected $payloads_kapso = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp U14-14',
            'company_name' => 'Ferreteria U14-14',
            'email'        => 'whatsapp-u14-14-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp U14-14',
            'email'    => 'whatsapp-u14-14-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'                  => $this->comercio->id,
            'kapso_api_key'            => 'kapso-u14-14',
            'phone_number_id'          => '5491100000014',
            'webhook_secret'           => 'secreto-u14-14',
            'is_active'                => true,
            'ai_enabled_default'       => true,
            // Con espera de confirmación la respuesta queda `a_confirmar` y se puede mirar antes
            // de que salga, que es lo que hace observable todo este archivo.
            'ai_confirm_delay_seconds' => 120,
        ]);

        $this->dar_extension();
    }

    /**
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'WhatsApp',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Stubs de las tres APIs externas, guardando lo que viaja a Kapso.
     *
     * @return void
     */
    protected function fakes_de_red()
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => function () {
                return Http::response([
                    'model'   => 'claude-de-prueba',
                    'content' => [['type' => 'text', 'text' => $this->texto_de_la_ia]],
                    'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                ], 200);
            },
            'api.kapso.ai/*' => function ($request) {
                $this->payloads_kapso[] = $request->data();

                return Http::response(['messages' => [['id' => 'wamid.CON-FOTO']]], 200);
            },
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * Artículo del catálogo del comercio, con o sin foto cargada.
     *
     * @param bool $con_imagen
     * @return Article
     */
    protected function articulo($con_imagen = true)
    {
        $article = Article::create([
            'user_id'  => $this->comercio->id,
            'name'     => 'Taladro percutor 750W',
            'bar_code' => self::BAR_CODE,
        ]);

        if ($con_imagen) {
            $article->images()->create(['hosting_url' => self::HOSTING_URL]);
        }

        return $article;
    }

    /**
     * Chat con la ventana de 24 h abierta y un mensaje del cliente sin responder.
     *
     * @param bool $simulado true = el último entrante lo inyectó `simulate-inbound`.
     * @return WhatsappChat
     */
    protected function chat_con_entrante($simulado = false)
    {
        $chat = WhatsappChat::create([
            'user_id'                => $this->comercio->id,
            'phone'                  => self::PHONE,
            'ai_enabled'             => true,
            'unread_count'           => 1,
            'last_message_at'        => now(),
            'last_inbound_at'        => now(),
            'last_inbound_simulated' => $simulado ? 1 : 0,
        ]);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => '¿tenés taladros?',
            'is_simulated'     => $simulado ? 1 : 0,
        ]);

        // El scheduler bumpea el token de debounce sin encolar nada: deja el 1 vigente, que es
        // el que después lleva el job que se corre a mano.
        (new WhatsappAgentScheduler())->cancel((int) $chat->id);

        return $chat;
    }

    /**
     * Corre el job que genera la respuesta, igual que lo haría la cola.
     *
     * @param WhatsappChat $chat
     * @return void
     */
    protected function correr_job(WhatsappChat $chat)
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        (new GenerateWhatsappAiReplyJob((int) $chat->id, 1))->handle(app(WhatsappAgentScheduler::class));
    }

    /**
     * La respuesta del agente que quedó esperando aprobación.
     *
     * @param WhatsappChat $chat
     * @return WhatsappChatMessage|null
     */
    protected function pendiente(WhatsappChat $chat)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->orderBy('id')
            ->first();
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
    public function una_respuesta_con_marcador_guarda_la_foto_del_producto_y_el_texto_limpio()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Te recomiendo el taladro percutor de 750W, lo tenemos en stock.\n[FOTO:" . self::BAR_CODE . ']';

        $this->articulo();
        $chat = $this->chat_con_entrante();

        $this->correr_job($chat);

        $salientes = $this->salientes($chat);
        $this->assertCount(1, $salientes, 'La foto viaja CON la respuesta, no como un mensaje aparte.');

        $mensaje = $salientes[0];
        $this->assertEquals(
            'Te recomiendo el taladro percutor de 750W, lo tenemos en stock.',
            $mensaje->body,
            'El marcador se saca del cuerpo: el cliente nunca lo puede ver.'
        );
        $this->assertEquals('image', $mensaje->media_type);
        $this->assertEquals(self::HOSTING_URL, $mensaje->media_url, 'Va por link, no por upload: la URL del catálogo ya es pública.');
        $this->assertEquals('a_confirmar', $mensaje->ai_status);
        $this->assertEquals(0, count($this->payloads_kapso), 'Todavía no se confirmó: no puede haber salido nada.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function al_confirmarla_la_respuesta_sale_como_una_imagen_con_el_texto_de_epigrafe()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Este es el que te sirve.\n[FOTO:" . self::BAR_CODE . ']';

        $this->articulo();
        $chat = $this->chat_con_entrante();
        $this->correr_job($chat);

        $mensaje = $this->pendiente($chat);
        $this->assertNotNull($mensaje);

        $this->actingAs($this->empleado, 'web');
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(200);

        Http::assertSent(function ($request) {
            if (strpos($request->url(), 'api.kapso.ai') === false) {
                return false;
            }

            return $request['type'] === 'image'
                && isset($request['image']['link'])
                && $request['image']['link'] === self::HOSTING_URL
                && $request['image']['caption'] === 'Este es el que te sirve.';
        });

        $mensaje->refresh();
        $this->assertEquals('enviado', $mensaje->ai_status);
        $this->assertEquals('wamid.CON-FOTO', $mensaje->wa_message_id);
    }

    /**
     * 🔴 CASO CRÍTICO (14.3 del plan). El modelo puede inventar un código de barras, o copiar
     * mal uno del catálogo: es el camino de falla más frecuente de todos.
     *
     * Lo que NO puede pasar es que el `[FOTO:...]` le llegue al cliente. Por eso el recorte del
     * marcador es lo PRIMERO que hace el job, fuera del `try` y antes de mirar siquiera el
     * código: la forma obvia de escribirlo —buscar el artículo y recortar recién cuando
     * encontrás la foto— deja el marcador a la vista en todos los caminos de falla, y el modo de
     * falla es silencioso, porque todo lo demás sigue andando.
     *
     * @group whatsapp
     * @test
     */
    public function un_codigo_que_no_existe_igual_sale_sin_el_marcador()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Mirá, tengo este.\n[FOTO:noexiste]";

        // Hay catálogo, pero con otro código: el que marcó el agente no está.
        $this->articulo();
        $chat = $this->chat_con_entrante();

        $this->correr_job($chat);

        $mensaje = $this->pendiente($chat);
        $this->assertNotNull($mensaje, 'La respuesta sale igual, como texto.');
        $this->assertEquals('Mirá, tengo este.', $mensaje->body);
        $this->assertStringNotContainsString(
            '[FOTO:',
            (string) $mensaje->body,
            'EL CLIENTE NUNCA PUEDE VER UN [FOTO:...]: es basura de implementación.'
        );
        $this->assertNull($mensaje->media_type);
        $this->assertNull($mensaje->media_url);
    }

    /**
     * El artículo existe pero el negocio nunca le cargó una foto. Misma degradación: sale como
     * texto y sin el marcador.
     *
     * @group whatsapp
     * @test
     */
    public function un_articulo_sin_foto_cargada_sale_como_texto_y_sin_el_marcador()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Tenemos este modelo.\n[FOTO:" . self::BAR_CODE . ']';

        $this->articulo(false);
        $chat = $this->chat_con_entrante();

        $this->correr_job($chat);

        $mensaje = $this->pendiente($chat);
        $this->assertNotNull($mensaje);
        $this->assertEquals('Tenemos este modelo.', $mensaje->body);
        $this->assertStringNotContainsString('[FOTO:', (string) $mensaje->body);
        $this->assertNull($mensaje->media_type);
        $this->assertNull($mensaje->media_url);
    }

    /**
     * Meta corta el `caption` de una imagen en 1024 caracteres, y no lo trunca: rechaza el
     * mensaje entero. Arriba del tope la respuesta sale como texto plano y sin foto — perder la
     * foto es muchísimo más barato que perder la respuesta.
     *
     * @group whatsapp
     * @test
     */
    public function una_respuesta_demasiado_larga_para_epigrafe_sale_como_texto()
    {
        Queue::fake();
        $this->fakes_de_red();

        $texto_largo = str_repeat('a', 1200);
        $this->texto_de_la_ia = $texto_largo . "\n[FOTO:" . self::BAR_CODE . ']';

        $this->articulo();
        $chat = $this->chat_con_entrante();

        $this->correr_job($chat);

        $mensaje = $this->pendiente($chat);
        $this->assertNotNull($mensaje);
        $this->assertEquals($texto_largo, $mensaje->body, 'El texto llega entero; lo que se pierde es la foto.');
        $this->assertStringNotContainsString('[FOTO:', (string) $mensaje->body);
        $this->assertNull($mensaje->media_type);
        $this->assertNull($mensaje->media_url);
    }

    /**
     * El 100% de las respuestas de antes de esta misión no tienen marcador, y tienen que seguir
     * saliendo byte por byte iguales.
     *
     * @group whatsapp
     * @test
     */
    public function una_respuesta_sin_marcador_no_cambia_en_nada()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = 'Sí, tenemos taladros de varias potencias. ¿Para qué lo vas a usar?';

        $this->articulo();
        $chat = $this->chat_con_entrante();

        $this->correr_job($chat);

        $mensaje = $this->pendiente($chat);
        $this->assertNotNull($mensaje);
        $this->assertEquals('Sí, tenemos taladros de varias potencias. ¿Para qué lo vas a usar?', $mensaje->body);
        $this->assertNull($mensaje->media_type);
        $this->assertNull($mensaje->media_url);
        $this->assertEquals('a_confirmar', $mensaje->ai_status);
    }

    /**
     * La simulación falsea la ventana de 24 h escribiendo `last_inbound_at` desde un número que
     * nunca escribió: mandarle la foto saldría de verdad por Kapso, Meta lo rechazaría de su
     * lado y cada intento le baja la calificación al número de WhatsApp Business del negocio.
     *
     * La fila se marca 'enviado' igual (sin `wa_message_id`) para que se vea en la conversación,
     * que es todo el punto de simular, y `is_simulated` deja claro que el cliente no recibió nada.
     *
     * @group whatsapp
     * @test
     */
    public function en_un_chat_simulado_la_foto_se_marca_enviada_sin_salir_a_kapso()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Este te sirve.\n[FOTO:" . self::BAR_CODE . ']';

        $this->articulo();
        $chat = $this->chat_con_entrante(true);
        $this->correr_job($chat);

        $mensaje = $this->pendiente($chat);
        $this->assertNotNull($mensaje);
        $this->assertEquals('image', $mensaje->media_type, 'La foto se resuelve igual: lo que se frena es el envío.');

        $this->actingAs($this->empleado, 'web');
        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(200);

        $this->assertEquals(0, count($this->payloads_kapso), 'En simulación no puede salir ni una request a Kapso.');
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'api.kapso.ai') !== false;
        });

        $mensaje->refresh();
        $this->assertEquals('enviado', $mensaje->ai_status, 'Se ve en la conversación, que es el punto de simular.');
        $this->assertNull($mensaje->wa_message_id, 'Sin id de Meta: nunca pasó por WhatsApp.');
        $this->assertEquals(1, (int) $mensaje->is_simulated);
    }

    /**
     * 🔴 EL BUG. El botón "Sugerir respuesta" usa exactamente la misma llamada a la IA que el
     * agente automático, así que la respuesta viene con el `[FOTO:...]` puesto. Mientras el
     * recorte vivió adentro de `GenerateWhatsappAiReplyJob`, este camino devolvía el texto crudo:
     * el borrador le caía al operador en el input terminado en `[FOTO:7791234567890]` y, si lo
     * mandaba sin leer hasta abajo, el cliente recibía eso por WhatsApp. Y encima por acá el
     * marcador no servía para nada: `send_message()` manda texto y nunca adjunta la foto.
     *
     * Que el otro consumidor —el agente automático— estuviera perfecto es justamente lo que hacía
     * silencioso al bug.
     *
     * @group whatsapp
     * @test
     */
    public function el_borrador_del_operador_nunca_trae_el_marcador()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Te recomiendo el taladro percutor de 750W, lo tenemos en stock.\n[FOTO:" . self::BAR_CODE . ']';

        // El artículo existe y tiene foto: el camino feliz de la funcionalidad, que es el que
        // más seguido le pone el marcador a la respuesta.
        $this->articulo();
        $chat = $this->chat_con_entrante();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $chat->id . '/suggest');
        $response->assertStatus(200);

        $sugerencia = (string) $response->json('suggestion');

        $this->assertStringNotContainsString(
            '[FOTO:',
            $sugerencia,
            'EL OPERADOR NO PUEDE VER EL MARCADOR: lo manda tal cual y termina en el WhatsApp del cliente.'
        );
        $this->assertEquals('Te recomiendo el taladro percutor de 750W, lo tenemos en stock.', $sugerencia);
    }

    /**
     * La misma garantía, pero un escalón más abajo: pedida directo al service, que es donde vive
     * el recorte. Este es el test que le cierra la puerta al consumidor que aparezca mañana —otro
     * endpoint, otro job, una integración—, porque el marcador ya no existe del lado de afuera de
     * `WhatsappBotAiService`. Sin él, la única red de contención serían los tests de los dos
     * consumidores de hoy, y un tercero nacería con la fuga puesta.
     *
     * @group whatsapp
     * @test
     */
    public function el_service_le_devuelve_el_texto_limpio_a_cualquier_caller()
    {
        Queue::fake();
        $this->fakes_de_red();
        $this->texto_de_la_ia = "Este es el que te sirve.\n[FOTO:" . self::BAR_CODE . ']';

        $this->articulo();
        $chat = $this->chat_con_entrante();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $texto = (new WhatsappBotAiService())->generate_response($chat, $this->config);

        $this->assertEquals('Este es el que te sirve.', $texto);
        $this->assertStringNotContainsString('[FOTO:', $texto, 'El texto sale limpio del productor, no de cada caller.');
    }
}
