<?php

namespace Tests\Feature\TiendaMensajes;

use App\Events\TiendaChatActualizado;
use App\Models\Message;
use App\Notifications\MessageSend;
use Carbon\Carbon;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\EmpresaTestCase;

/**
 * `POST tienda-chats/{buyer_id}/mensajes` — la respuesta manual del comercio (misión
 * mensajes-tienda-online, 28/9/2026, contratos C1, C2 y C3).
 *
 * Lo que protege:
 * - 201 con la forma `Mensaje` exacta; el mensaje se guarda con `user_id` = dueño (aunque escriba
 *   un empleado), `from_buyer = 0`, `read = 0`, `type = null` y el texto TAL CUAL (solo el trim).
 * - El evento C1 al canal `private-tienda-mensajes.{dueño}`, con `broadcastAs` y los tipos del
 *   contrato. El texto del evento se recorta a 500 caracteres y el payload entra en los 10 KB de
 *   Pusher, también en el peor caso (500 emojis).
 * - `MessageSend` al comprador por `message.from_commerce.{buyer_id}`, con el mensaje releído de la
 *   base, y el mail según la regla de 30 minutos (sí la primera vez, no a los 10, sí a los 31).
 * - 422 con texto vacío, de puros espacios o de más de 5000; 404 con un comprador ajeno.
 * - 🔴 Pusher caído no rompe el 201: el mensaje queda guardado igual.
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
     * Las `MessageSend` que recibió el comprador, cada una con los canales que devolvió `via()`.
     *
     * @return array  `[['notification' => MessageSend, 'channels' => string[]], ...]`
     */
    protected function avisos_al_comprador()
    {
        $avisos = [];

        if (!Notification::hasSent($this->comprador, MessageSend::class)) {
            return $avisos;
        }

        Notification::assertSentTo($this->comprador, MessageSend::class, function ($notification, $channels) use (&$avisos) {
            $avisos[] = ['notification' => $notification, 'channels' => $channels];
            return true;
        });

        return $avisos;
    }

    /**
     * @test
     */
    public function guarda_el_mensaje_tal_cual_y_responde_201_con_la_forma_del_contrato()
    {
        Event::fake([TiendaChatActualizado::class]);
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
        Event::fake([TiendaChatActualizado::class]);
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
        Event::fake([TiendaChatActualizado::class]);
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
        Event::fake([TiendaChatActualizado::class]);
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
        Event::fake([TiendaChatActualizado::class]);
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
        Event::fake([TiendaChatActualizado::class]);
        Notification::fake();

        $this->actuar_como($this->crear_empleado($this->dueno));

        $mensaje = $this->postJson($this->ruta(), ['text' => 'Respondo yo'])->assertStatus(201)->json('message');

        $this->assertSame($this->dueno->id, $mensaje['user_id']);
        $this->assertSame($this->dueno->id, (int) Message::find($mensaje['id'])->user_id);
        $this->assertSame('private-tienda-mensajes.'.$this->dueno->id, $this->eventos_emitidos()[0]->broadcastOn()->name);
    }

    /**
     * El aviso al comprador (C2): `MessageSend` por el canal que la tienda ya escucha, con el
     * mensaje releído de la base, y mail la primera vez.
     *
     * @test
     */
    public function avisa_al_comprador_por_su_canal_y_por_mail_la_primera_vez()
    {
        Event::fake([TiendaChatActualizado::class]);
        Notification::fake();

        // Un mensaje del comprador no cuenta para la regla de 30 minutos: lo que cuenta es el comercio.
        $this->mensaje_del_comprador($this->comprador, ['created_at' => Carbon::now()->subMinutes(2)]);

        $mensaje = $this->postJson($this->ruta(), ['text' => 'Hola Ana, te respondo'])->assertStatus(201)->json('message');

        $avisos = $this->avisos_al_comprador();

        $this->assertCount(1, $avisos);
        $this->assertSame(['broadcast', 'mail'], $avisos[0]['channels']);

        $notificacion = $avisos[0]['notification'];

        $this->assertSame('message.from_commerce.'.$this->comprador->id, $notificacion->broadcastOn());

        // El mensaje del broadcast se relee de la base: trae `from_buyer`, `read` y `type`, que el
        // `create()` no le pasa al modelo en memoria cuando MySQL pone el default.
        $del_broadcast = $notificacion->toBroadcast($this->comprador)->data['message'];

        $this->assertSame($mensaje['id'], (int) $del_broadcast->id);
        foreach (['from_buyer', 'read', 'type'] as $atributo) {
            $this->assertArrayHasKey($atributo, $del_broadcast->getAttributes(), 'El mensaje del broadcast no se releyó de la base: le falta "'.$atributo.'".');
        }

        // El mail: firmado por el comercio correcto, con el asunto del contrato, y se puede armar.
        $mail = $notificacion->toMail($this->comprador);

        $this->assertSame('Comercio enviar respondió tu mensaje', $mail->subject);
        $this->assertStringContainsString('Hola Ana, te respondo', (string) $mail->render());
    }

    /**
     * @test
     */
    public function no_manda_mail_si_el_comercio_le_escribio_hace_10_minutos()
    {
        Event::fake([TiendaChatActualizado::class]);
        Notification::fake();

        $this->mensaje_del_comercio($this->comprador, ['created_at' => Carbon::now()->subMinutes(10)]);

        $this->postJson($this->ruta(), ['text' => 'Otra cosa más'])->assertStatus(201);

        $avisos = $this->avisos_al_comprador();

        $this->assertCount(1, $avisos);
        $this->assertSame(['broadcast'], $avisos[0]['channels'], 'A los 10 minutos del mensaje anterior del comercio no va mail, solo el broadcast.');
    }

    /**
     * Cualquier mensaje del comercio cuenta, también uno automático (un pedido confirmado).
     *
     * @test
     */
    public function un_mensaje_automatico_reciente_del_comercio_tambien_frena_el_mail()
    {
        Event::fake([TiendaChatActualizado::class]);
        Notification::fake();

        $this->mensaje_del_comercio($this->comprador, [
            'type'       => 'order_confirmed',
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->postJson($this->ruta(), ['text' => 'Ya lo preparamos'])->assertStatus(201);

        $this->assertSame(['broadcast'], $this->avisos_al_comprador()[0]['channels']);
    }

    /**
     * @test
     */
    public function vuelve_a_mandar_mail_si_pasaron_31_minutos()
    {
        Event::fake([TiendaChatActualizado::class]);
        Notification::fake();

        $this->mensaje_del_comercio($this->comprador, ['created_at' => Carbon::now()->subMinutes(31)]);
        // Uno de OTRO comprador hace 1 minuto no cuenta: la regla es por conversación.
        $otro = $this->crear_comprador_de($this->dueno);
        $this->mensaje_del_comercio($otro, ['created_at' => Carbon::now()->subMinute()]);

        $this->postJson($this->ruta(), ['text' => 'Retomo la charla'])->assertStatus(201);

        $this->assertSame(['broadcast', 'mail'], $this->avisos_al_comprador()[0]['channels']);
    }

    /**
     * @test
     */
    public function texto_vacio_de_puros_espacios_o_largo_de_mas_da_422_y_no_guarda_nada()
    {
        Event::fake([TiendaChatActualizado::class]);
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
        Notification::assertNothingSent();
    }

    /**
     * @test
     */
    public function un_articulo_de_otro_comercio_da_422()
    {
        Event::fake([TiendaChatActualizado::class]);
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
        Event::fake([TiendaChatActualizado::class]);
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
        Event::fake([TiendaChatActualizado::class]);
        Notification::fake();

        $ajeno = $this->crear_comprador_de($this->crear_dueno('enviar ajeno'));

        // Ni siquiera con el texto vacío: a un comprador ajeno no se le contesta un 422.
        $this->postJson($this->ruta($ajeno->id), ['text' => ''])->assertStatus(404);
        $this->postJson($this->ruta($ajeno->id), ['text' => 'Hola'])->assertStatus(404);

        $this->assertSame(0, Message::where('buyer_id', $ajeno->id)->count());
        $this->assertCount(0, $this->eventos_emitidos());
        Notification::assertNothingSent();
    }

    /**
     * 🔴 Pusher caído no rompe el envío.
     *
     * Se bindea un broadcaster que tira como tiraría Pusher con un 502, y NO se falsea nada: el
     * evento C1 pasa por el camino real (`event()` → `BroadcastManager::queue()` → `BroadcastEvent`
     * → `broadcast()`), y el aviso al comprador también (`InstantBroadcastChannel` y el mail con
     * el mailer `array`, después de la respuesta).
     *
     * @test
     */
    public function pusher_caido_no_rompe_el_201_y_el_mensaje_queda_guardado()
    {
        $roto = new class extends Broadcaster {
            /** @var array Los eventos que se intentaron emitir, con sus canales. */
            public $intentos = [];

            public function auth($request)
            {
            }

            public function validAuthenticationResponse($request, $result)
            {
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->intentos[] = ['evento' => $event, 'canales' => $this->formatChannels($channels)];

                throw new BroadcastException('Pusher caído (simulado): 502 Bad Gateway');
            }
        };

        Broadcast::extend('roto', function () use ($roto) {
            return $roto;
        });

        config([
            'broadcasting.connections.roto' => ['driver' => 'roto'],
            'broadcasting.default'          => 'roto',
        ]);

        $respuesta = $this->postJson($this->ruta(), ['text' => 'Esto sale igual']);

        $respuesta->assertStatus(201);

        $id = $respuesta->json('message.id');

        $this->assertNotNull(Message::find($id), 'El mensaje no quedó guardado.');
        $this->assertSame('Esto sale igual', Message::find($id)->text);

        // Que el broadcaster roto se haya usado de verdad (si no, el test no prueba nada).
        $intentos_c1 = array_filter($roto->intentos, function ($intento) {
            return $intento['evento'] === 'TiendaChatActualizado';
        });

        $this->assertCount(1, $intentos_c1, 'El evento C1 no pasó por el broadcaster roto.');
        $this->assertSame(['private-tienda-mensajes.'.$this->dueno->id], array_values($intentos_c1)[0]['canales']);

        // El aviso al comprador corrió después de la respuesta: su broadcast también chocó con el
        // Pusher caído, y el mail (que no depende de Pusher) salió igual, de punta a punta.
        $intentos_c2 = array_filter($roto->intentos, function ($intento) {
            return $intento['canales'] === ['message.from_commerce.'.$this->comprador->id];
        });

        $this->assertCount(1, $intentos_c2, 'El aviso al comprador no llegó a intentar su broadcast.');

        $mails = app('mailer')->getSwiftMailer()->getTransport()->messages();

        $this->assertCount(1, $mails, 'El mail al comprador no salió.');
        $this->assertSame('Comercio enviar respondió tu mensaje', $mails->first()->getSubject());
        $this->assertSame([$this->comprador->email], array_keys($mails->first()->getTo()));
        $this->assertStringContainsString('Esto sale igual', $mails->first()->getBody());
    }
}
