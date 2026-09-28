<?php

namespace Tests\Feature\TiendaMensajes;

use App\Events\RespuestaDelComercio;
use App\Events\TiendaChatActualizado;
use App\Models\Message;
use App\Notifications\RespuestaDelComercioPorMail;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\EmpresaTestCase;

/**
 * `POST tienda-chats/{buyer_id}/mensajes` — la respuesta manual del comercio (misión
 * mensajes-tienda-online, 28/9/2026, contratos C1 y C3). El aviso al comprador (contrato C2' y el
 * mail) está en `RespuestaAlCompradorTest`.
 *
 * Lo que protege:
 * - 201 con la forma `Mensaje` exacta; el mensaje se guarda con `user_id` = dueño (aunque escriba
 *   un empleado), `from_buyer = 0`, `read = 0`, `type = null` y el texto TAL CUAL (solo el trim).
 * - El evento C1 al canal `private-tienda-mensajes.{dueño}`, con `broadcastAs` y los tipos del
 *   contrato. El texto del evento se recorta a 500 caracteres y el payload entra en los 10 KB de
 *   Pusher, también en el peor caso (500 emojis).
 * - 🔴 El evento C1 sale DESPUÉS de mandada la respuesta: con Pusher colgado, el 201 no espera.
 * - 422 con texto vacío, de puros espacios o de más de 5000; 404 con un comprador ajeno.
 *
 * Un solo POST por test (ver el docblock de `ConversacionesDePrueba`).
 *
 * PHP 7.4.
 */
class EnviarMensajeTest extends EmpresaTestCase
{
    use ConversacionesDePrueba;

    /** @var \App\Models\User */
    protected $dueno;

    /** @var \App\Models\Buyer */
    protected $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno('enviar');
        $this->comprador = $this->crear_comprador_de($this->dueno, [
            'name'    => 'Ana',
            'surname' => 'Pérez',
            'email'   => 'ana-enviar-'.uniqid().'@test.local',
            'phone'   => '1155550000',
        ]);

        $this->actuar_como($this->dueno);
    }

    /**
     * @param  int|string|null  $buyer_id
     * @return string
     */
    protected function ruta($buyer_id = null)
    {
        return 'api/tienda-chats/'.(is_null($buyer_id) ? $this->comprador->id : $buyer_id).'/mensajes';
    }

    /**
     * @test
     */
    public function guarda_el_mensaje_tal_cual_y_responde_201_con_la_forma_del_contrato()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $respuesta = $this->postJson($this->ruta(), ['text' => "  hola, ¿TIENEN talle 42?\nsaludos  "]);

        $respuesta->assertStatus(201);

        $json = $respuesta->json();

        $this->assertSame(['message'], array_keys($json));

        $mensaje = $json['message'];

        $this->assertSame(
            ['id', 'buyer_id', 'user_id', 'text', 'type', 'from_buyer', 'read', 'article_id', 'order_id', 'created_at', 'article'],
            array_keys($mensaje)
        );
        $this->assertIsInt($mensaje['id']);
        $this->assertSame($this->comprador->id, $mensaje['buyer_id']);
        $this->assertSame($this->dueno->id, $mensaje['user_id']);
        // Solo el trim: ni `onlyFirstWordUpperCase` ni nada que reescriba lo que escribió el comercio.
        $this->assertSame("hola, ¿TIENEN talle 42?\nsaludos", $mensaje['text']);
        $this->assertNull($mensaje['type']);
        $this->assertSame(false, $mensaje['from_buyer']);
        $this->assertSame(false, $mensaje['read']);
        $this->assertNull($mensaje['article_id']);
        $this->assertNull($mensaje['order_id']);
        $this->assertNull($mensaje['article']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000000Z$/', $mensaje['created_at']);

        $guardado = Message::find($mensaje['id']);

        $this->assertSame($this->dueno->id, (int) $guardado->user_id);
        $this->assertSame($this->comprador->id, (int) $guardado->buyer_id);
        $this->assertSame(0, (int) $guardado->from_buyer);
        $this->assertSame(0, (int) $guardado->read);
        $this->assertNull($guardado->type);
        $this->assertSame("hola, ¿TIENEN talle 42?\nsaludos", $guardado->text);
        $this->assertSame($guardado->created_at->toJSON(), $mensaje['created_at']);
    }

    /**
     * @test
     */
    public function emite_el_evento_c1_al_canal_del_dueno_con_los_tipos_del_contrato()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        // Dos mensajes del comprador sin leer: el evento tiene que llevar unread_count = 2.
        $this->mensaje_del_comprador($this->comprador);
        $this->mensaje_del_comprador($this->comprador);

        $mensaje = $this->postJson($this->ruta(), ['text' => 'Sí, nos queda uno'])->assertStatus(201)->json('message');

        $eventos = $this->eventos_emitidos();

        $this->assertCount(1, $eventos, 'Se esperaba un solo TiendaChatActualizado.');

        $evento = $eventos[0];

        $this->assertSame('private-tienda-mensajes.'.$this->dueno->id, $evento->broadcastOn()->name);
        $this->assertSame('TiendaChatActualizado', $evento->broadcastAs());

        $payload = $evento->broadcastWith();

        $this->assertSame(['buyer_id', 'chat', 'buyer', 'message'], array_keys($payload));
        $this->assertSame($this->comprador->id, $payload['buyer_id']);

        $this->assertSame([
            'buyer_id'        => $this->comprador->id,
            'unread_count'    => 2,
            'last_message_at' => $mensaje['created_at'],
        ], $payload['chat']);

        $this->assertSame([
            'id'      => $this->comprador->id,
            'name'    => 'Ana',
            'surname' => 'Pérez',
            'email'   => $this->comprador->email,
            'phone'   => '1155550000',
        ], $payload['buyer']);

        $this->assertSame([
            'id'            => $mensaje['id'],
            'buyer_id'      => $this->comprador->id,
            'user_id'       => $this->dueno->id,
            'text'          => 'Sí, nos queda uno',
            'text_truncado' => false,
            'type'          => null,
            'from_buyer'    => false,
            'read'          => false,
            'article_id'    => null,
            'order_id'      => null,
            'created_at'    => $mensaje['created_at'],
        ], $payload['message']);
    }

    /**
     * @test
     */
    public function el_texto_del_evento_se_recorta_a_500_caracteres_y_la_api_lo_guarda_entero()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        // 501 con acentos: uno de más, y el recorte tiene que ser por caracteres (mb_substr), no por bytes.
        $texto = str_repeat('á', 501);

        $mensaje = $this->postJson($this->ruta(), ['text' => $texto])->assertStatus(201)->json('message');

        // La API y la base lo guardan entero.
        $this->assertSame($texto, $mensaje['text']);
        $this->assertSame($texto, Message::find($mensaje['id'])->text);

        $payload = $this->eventos_emitidos()[0]->broadcastWith();

        $this->assertSame(true, $payload['message']['text_truncado']);
        $this->assertSame(500, mb_strlen($payload['message']['text']));
        $this->assertSame(str_repeat('á', 500), $payload['message']['text']);
    }

    /**
     * El borde: 500 caracteres justos viajan enteros. Se recorta solo lo que pasa de 500.
     *
     * @test
     */
    public function un_texto_de_500_caracteres_justos_viaja_entero_en_el_evento()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $texto = str_repeat('á', 500);

        $this->postJson($this->ruta(), ['text' => $texto])->assertStatus(201);

        $payload = $this->eventos_emitidos()[0]->broadcastWith();

        $this->assertSame(false, $payload['message']['text_truncado']);
        $this->assertSame($texto, $payload['message']['text']);
    }

    /**
     * Un mensaje largo de verdad (5000 caracteres de castellano normal, con sus acentos y eñes)
     * viaja en un evento que entra en los 10 KB de Pusher.
     *
     * @test
     */
    public function un_mensaje_largo_normal_entra_en_los_10_kb_de_pusher()
    {
        $frase = 'Hola, ¿cómo andás? Te confirmo que el pedido sale mañana a la mañana por el correo. ';

        $this->assertLessThan(10240, $this->bytes_del_evento_para(mb_substr(str_repeat($frase, 100), 0, 5000)));
    }

    /**
     * 🔴 El peor caso con acentos: 5000 caracteres acentuados (el máximo que acepta la API), de
     * los que viajan 500. El SDK escapa cada acento como `\u00e1` (6 bytes). Con el recorte viejo
     * de 2000 este mismo caso medía 12.437 bytes y Pusher lo rechazaba.
     *
     * @test
     */
    public function el_peor_caso_con_acentos_entra_en_los_10_kb_de_pusher()
    {
        $bytes = $this->bytes_del_evento_para(str_repeat('á', 5000), str_repeat('á', 500));

        $this->assertLessThan(10240, $bytes, '500 caracteres acentuados pesan '.$bytes.' bytes en el evento.');
    }

    /**
     * 🔴 El peor caso de todos: 5000 emojis, de los que viajan 500. Cada emoji sale del SDK como un
     * par de escapes `\ud83d\ude00` (12 bytes), que es lo más pesado que puede tener un carácter.
     * Y el recorte no puede partir un emoji por la mitad: `mb_substr` cuenta caracteres, no bytes.
     *
     * @test
     */
    public function el_peor_caso_con_emojis_entra_en_los_10_kb_de_pusher()
    {
        $emoji = "\u{1F600}";

        $bytes = $this->bytes_del_evento_para(str_repeat($emoji, 5000), str_repeat($emoji, 500));

        $this->assertLessThan(10240, $bytes, '500 emojis pesan '.$bytes.' bytes en el evento.');
    }

    /**
     * Manda `$texto` y devuelve cuántos bytes pesa la data del evento C1 codificada EXACTAMENTE como
     * la codifica el SDK de Pusher antes de mandarla (`Pusher::make_event()`, vendor:
     * `json_encode($data, JSON_THROW_ON_ERROR)`, sin `JSON_UNESCAPED_UNICODE`). Ese es el tamaño
     * que Pusher compara contra su límite de 10 KB por evento.
     *
     * De paso afirma que el texto se recortó a 500 y, si se pasa, que quedó exactamente así.
     *
     * @param  string  $texto
     * @param  string|null  $texto_esperado_en_el_evento
     * @return int
     */
    protected function bytes_del_evento_para($texto, $texto_esperado_en_el_evento = null)
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $this->postJson($this->ruta(), ['text' => $texto])->assertStatus(201);

        $payload = $this->eventos_emitidos()[0]->broadcastWith();

        $this->assertSame(true, $payload['message']['text_truncado']);
        $this->assertSame(500, mb_strlen($payload['message']['text']));

        if (!is_null($texto_esperado_en_el_evento)) {
            $this->assertSame($texto_esperado_en_el_evento, $payload['message']['text']);
        }

        return strlen(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @test
     */
    public function un_empleado_responde_a_nombre_del_dueno()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $this->actuar_como($this->crear_empleado($this->dueno));

        $mensaje = $this->postJson($this->ruta(), ['text' => 'Respondo yo'])->assertStatus(201)->json('message');

        $this->assertSame($this->dueno->id, $mensaje['user_id']);
        $this->assertSame($this->dueno->id, (int) Message::find($mensaje['id'])->user_id);
        $this->assertSame('private-tienda-mensajes.'.$this->dueno->id, $this->eventos_emitidos()[0]->broadcastOn()->name);
    }

    /**
     * 🔴 Los avisos salen DESPUÉS de mandada la respuesta. Antes, C1 se emitía en el momento, y con
     * Pusher colgado cada envío esperaba el timeout (hasta 5 s) antes de devolver el 201 de un
     * mensaje ya guardado.
     *
     * Se maneja el kernel a mano para separar las dos fases: `handle()` arma la respuesta que recibe
     * el usuario y `terminate()` es lo que corre después de mandada (`fastcgi_finish_request()`).
     *
     * @test
     */
    public function los_avisos_salen_despues_de_mandada_la_respuesta()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $kernel = $this->app->make(HttpKernel::class);

        $request = Request::create('/'.$this->ruta(), 'POST', [], [], [], [
            'HTTP_ACCEPT'  => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['text' => 'Después de responder']));

        $response = $kernel->handle($request);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNotNull(Message::where('buyer_id', $this->comprador->id)->where('text', 'Después de responder')->first());

        // La respuesta ya está armada y todavía no salió nada.
        $this->assertCount(0, Event::dispatched(TiendaChatActualizado::class), 'C1 salió ANTES de mandada la respuesta.');
        $this->assertCount(0, Event::dispatched(RespuestaDelComercio::class), 'La respuesta a la tienda salió ANTES de mandada la respuesta.');
        Notification::assertNothingSent();

        $kernel->terminate($request, $response);

        $this->assertCount(1, Event::dispatched(TiendaChatActualizado::class));
        $this->assertCount(1, Event::dispatched(RespuestaDelComercio::class));
        Notification::assertSentTo($this->comprador, RespuestaDelComercioPorMail::class);
    }

    /**
     * @test
     */
    public function texto_vacio_de_puros_espacios_o_largo_de_mas_da_422_y_no_guarda_nada()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $casos = [
            'sin text'      => [],
            'vacío'         => ['text' => ''],
            'puros espacios' => ['text' => "   \n  "],
            'más de 5000'   => ['text' => str_repeat('a', 5001)],
        ];

        foreach ($casos as $caso => $cuerpo) {
            $this->postJson($this->ruta(), $cuerpo)
                 ->assertStatus(422)
                 ->assertJsonValidationErrors('text');
        }

        $this->assertSame(0, Message::where('buyer_id', $this->comprador->id)->count());
        $this->assertCount(0, $this->eventos_emitidos());
        Event::assertNotDispatched(RespuestaDelComercio::class);
        Notification::assertNothingSent();
    }

    /**
     * @test
     */
    public function un_articulo_de_otro_comercio_da_422()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $ajeno = \App\Models\Article::create([
            'name'    => 'Artículo ajeno',
            'user_id' => $this->crear_dueno('enviar artículo ajeno')->id,
        ]);

        $this->postJson($this->ruta(), ['text' => 'Mirá este', 'article_id' => $ajeno->id])
             ->assertStatus(422)
             ->assertJsonValidationErrors('article_id');

        $this->assertSame(0, Message::where('buyer_id', $this->comprador->id)->count());
    }

    /**
     * @test
     */
    public function con_un_articulo_propio_lo_devuelve_liviano()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $articulo = \App\Models\Article::create([
            'name'    => 'Zapatilla',
            'slug'    => 'zapatilla',
            'user_id' => $this->dueno->id,
        ]);

        $mensaje = $this->postJson($this->ruta(), ['text' => 'Mirá esta', 'article_id' => $articulo->id])
                        ->assertStatus(201)
                        ->json('message');

        $this->assertSame($articulo->id, $mensaje['article_id']);
        $this->assertSame([
            'id'        => $articulo->id,
            'name'      => 'Zapatilla',
            'slug'      => 'zapatilla',
            'image_url' => null,
        ], $mensaje['article']);
        $this->assertSame($articulo->id, $this->eventos_emitidos()[0]->broadcastWith()['message']['article_id']);
    }

    /**
     * @test
     */
    public function un_comprador_ajeno_da_404_y_no_guarda_nada()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $ajeno = $this->crear_comprador_de($this->crear_dueno('enviar ajeno'));

        // Ni siquiera con el texto vacío: a un comprador ajeno no se le contesta un 422.
        $this->postJson($this->ruta($ajeno->id), ['text' => ''])->assertStatus(404);
        $this->postJson($this->ruta($ajeno->id), ['text' => 'Hola'])->assertStatus(404);

        $this->assertSame(0, Message::where('buyer_id', $ajeno->id)->count());
        $this->assertCount(0, $this->eventos_emitidos());
        Event::assertNotDispatched(RespuestaDelComercio::class);
        Notification::assertNothingSent();
    }
}
