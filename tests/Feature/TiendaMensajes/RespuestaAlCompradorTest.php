<?php

namespace Tests\Feature\TiendaMensajes;

use App\Events\RespuestaDelComercio;
use App\Events\TiendaChatActualizado;
use App\Models\Message;
use App\Notifications\MessageSend;
use App\Notifications\RespuestaDelComercioPorMail;
use Carbon\Carbon;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Mail\Transport\Transport;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\EmpresaTestCase;

/**
 * El aviso al comprador cuando el comercio le responde a mano (misión mensajes-tienda-online,
 * 28/9/2026): en vivo por `RespuestaDelComercio` (contrato C2') y, como mucho uno cada 30 minutos,
 * por mail.
 *
 * Lo que protege:
 * - 🔴 La respuesta sale por el canal público `tienda-respuestas.{owner}.{buyer}` y NUNCA por
 *   `message.from_commerce.{buyer_id}` (el C2 original): ese canal se cruza entre comercios de la
 *   flota, porque la app de Pusher es una sola y `buyers.id` se repite entre bases.
 * - El payload `{ message }` con la forma exacta del `message` de C1 (recorte a 500 incluido).
 * - El mail sale SOLO por mail, con la regla de 30 minutos (sí la primera vez, no a los 10, sí a
 *   los 31, y cualquier mensaje del comercio cuenta), firmado por el comercio correcto.
 * - Sin email no se manda ni se rompe nada; con el SMTP caído o Pusher caído, el 201 sale igual y
 *   el mensaje queda guardado.
 *
 * Un solo POST por test (ver el docblock de `ConversacionesDePrueba`).
 *
 * PHP 7.4.
 */
class RespuestaAlCompradorTest extends EmpresaTestCase
{
    use ConversacionesDePrueba;

    /** @var \App\Models\User */
    protected $dueno;

    /** @var \App\Models\Buyer */
    protected $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno('respuesta');
        $this->comprador = $this->crear_comprador_de($this->dueno, [
            'name'    => 'Ana',
            'surname' => 'Pérez',
            'email'   => 'ana-respuesta-'.uniqid().'@test.local',
            'phone'   => '1155550000',
        ]);

        $this->actuar_como($this->dueno);
    }

    /**
     * @return string
     */
    protected function ruta()
    {
        return 'api/tienda-chats/'.$this->comprador->id.'/mensajes';
    }

    /**
     * El canal que escucha la tienda de este comprador.
     *
     * @return string
     */
    protected function canal_de_la_tienda()
    {
        return 'tienda-respuestas.'.$this->dueno->id.'.'.$this->comprador->id;
    }

    /**
     * Los `RespuestaDelComercio` emitidos (requiere `Event::fake` con esa clase).
     *
     * @return \App\Events\RespuestaDelComercio[]
     */
    protected function respuestas_emitidas()
    {
        return Event::dispatched(RespuestaDelComercio::class)
                    ->map(function ($argumentos) {
                        return $argumentos[0];
                    })
                    ->values()
                    ->all();
    }

    /**
     * Reemplaza el broadcaster por uno que anota cada evento con sus canales y, si `$tira`, tira
     * como tiraría Pusher con un 502. Nada se falsea: los eventos y las notificaciones pasan por el
     * camino real hasta `broadcast()`.
     *
     * @param  bool  $tira
     * @return object  Con `$intentos`: `[['evento' => string, 'canales' => string[]], ...]`.
     */
    protected function usar_broadcaster_que_anota($tira)
    {
        $broadcaster = new class extends Broadcaster {
            /** @var array */
            public $intentos = [];

            /** @var bool */
            public $tira = false;

            public function auth($request)
            {
            }

            public function validAuthenticationResponse($request, $result)
            {
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->intentos[] = ['evento' => $event, 'canales' => $this->formatChannels($channels)];

                if ($this->tira) {
                    throw new BroadcastException('Pusher caído (simulado): 502 Bad Gateway');
                }
            }
        };

        $broadcaster->tira = $tira;

        Broadcast::extend('que-anota', function () use ($broadcaster) {
            return $broadcaster;
        });

        config([
            'broadcasting.connections.que-anota' => ['driver' => 'que-anota'],
            'broadcasting.default'               => 'que-anota',
        ]);

        return $broadcaster;
    }

    /**
     * Los mails que llegaron al mailer `array` (el de testing).
     *
     * @return \Illuminate\Support\Collection
     */
    protected function mails_enviados()
    {
        return app('mail.manager')->mailer('array')->getSwiftMailer()->getTransport()->messages();
    }

    // -------------------------------------------------------------------------------------------
    //  En vivo: C2'
    // -------------------------------------------------------------------------------------------

    /**
     * @test
     */
    public function la_respuesta_sale_en_vivo_por_tienda_respuestas_con_la_forma_del_contrato()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $mensaje = $this->postJson($this->ruta(), ['text' => 'Sí, nos queda una en 42'])
                        ->assertStatus(201)
                        ->json('message');

        $respuestas = $this->respuestas_emitidas();

        $this->assertCount(1, $respuestas, 'Se esperaba un solo RespuestaDelComercio.');

        $evento = $respuestas[0];

        // Canal PÚBLICO (sin el prefijo `private-`), con el dueño y el comprador.
        $this->assertInstanceOf(Channel::class, $evento->broadcastOn());
        $this->assertNotInstanceOf(PrivateChannel::class, $evento->broadcastOn());
        $this->assertSame($this->canal_de_la_tienda(), $evento->broadcastOn()->name);
        $this->assertSame('RespuestaDelComercio', $evento->broadcastAs());

        $this->assertSame([
            'message' => [
                'id'            => $mensaje['id'],
                'buyer_id'      => $this->comprador->id,
                'user_id'       => $this->dueno->id,
                'text'          => 'Sí, nos queda una en 42',
                'text_truncado' => false,
                'type'          => null,
                'from_buyer'    => false,
                'read'          => false,
                'article_id'    => null,
                'order_id'      => null,
                'created_at'    => $mensaje['created_at'],
            ],
        ], $evento->broadcastWith());

        // El `message` es exactamente el mismo que viaja en C1.
        $c1 = $this->eventos_emitidos()[0];
        $this->assertSame($c1->broadcastWith()['message'], $evento->broadcastWith()['message']);
    }

    /**
     * @test
     */
    public function el_texto_de_la_respuesta_en_vivo_se_recorta_a_500_como_en_c1()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $this->postJson($this->ruta(), ['text' => str_repeat('ñ', 501)])->assertStatus(201);

        $payload = $this->respuestas_emitidas()[0]->broadcastWith();

        $this->assertSame(true, $payload['message']['text_truncado']);
        $this->assertSame(str_repeat('ñ', 500), $payload['message']['text']);
    }

    /**
     * @test
     */
    public function si_responde_un_empleado_la_tienda_lo_recibe_por_el_canal_del_dueno()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $this->actuar_como($this->crear_empleado($this->dueno));

        $this->postJson($this->ruta(), ['text' => 'Respondo yo'])->assertStatus(201);

        $this->assertSame($this->canal_de_la_tienda(), $this->respuestas_emitidas()[0]->broadcastOn()->name);
    }

    /**
     * 🔴 Con el camino real (nada falseado), los únicos broadcasts de una respuesta manual son C1 al
     * ERP y C2' a la tienda. Nada por `message.from_commerce.*`: ni `MessageSend` ni ningún otro.
     *
     * @test
     */
    public function la_respuesta_manual_no_emite_nada_por_el_canal_viejo_message_from_commerce()
    {
        $broadcaster = $this->usar_broadcaster_que_anota(false);

        $this->postJson($this->ruta(), ['text' => 'Hola Ana'])->assertStatus(201);

        $canales = [];
        foreach ($broadcaster->intentos as $intento) {
            foreach ($intento['canales'] as $canal) {
                $canales[] = $intento['evento'].' @ '.$canal;
            }
        }

        $this->assertSame([
            'TiendaChatActualizado @ private-tienda-mensajes.'.$this->dueno->id,
            'RespuestaDelComercio @ '.$this->canal_de_la_tienda(),
        ], $canales);

        foreach ($canales as $canal) {
            $this->assertStringNotContainsString('message.from_commerce.', $canal);
        }
    }

    // -------------------------------------------------------------------------------------------
    //  Por mail
    // -------------------------------------------------------------------------------------------

    /**
     * @test
     */
    public function la_primera_vez_manda_el_mail_solo_por_mail_firmado_por_el_comercio()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        // Un mensaje del comprador no cuenta para la regla de 30 minutos: lo que cuenta es el comercio.
        $this->mensaje_del_comprador($this->comprador, ['created_at' => Carbon::now()->subMinutes(2)]);

        $this->postJson($this->ruta(), ['text' => 'Hola Ana, te respondo'])->assertStatus(201);

        $mails = [];
        Notification::assertSentTo($this->comprador, RespuestaDelComercioPorMail::class, function ($notificacion, $canales) use (&$mails) {
            $mails[] = ['notificacion' => $notificacion, 'canales' => $canales];
            return true;
        });

        $this->assertCount(1, $mails);
        $this->assertSame(['mail'], $mails[0]['canales'], 'El mail tiene que salir SOLO por mail.');

        // Y nada de `MessageSend`, que suma el broadcast al canal viejo.
        Notification::assertNotSentTo($this->comprador, MessageSend::class);

        $mail = $mails[0]['notificacion']->toMail($this->comprador);

        $this->assertSame('Comercio respuesta respondió tu mensaje', $mail->subject);
        $this->assertStringContainsString('Hola Ana, te respondo', (string) $mail->render());
    }

    /**
     * @test
     */
    public function no_manda_mail_si_el_comercio_le_escribio_hace_10_minutos()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $this->mensaje_del_comercio($this->comprador, ['created_at' => Carbon::now()->subMinutes(10)]);

        $this->postJson($this->ruta(), ['text' => 'Otra cosa más'])->assertStatus(201);

        Notification::assertNotSentTo($this->comprador, RespuestaDelComercioPorMail::class);
        Notification::assertNotSentTo($this->comprador, MessageSend::class);

        // El aviso en vivo sale igual: la regla de 30 minutos es solo del mail.
        $this->assertCount(1, $this->respuestas_emitidas());
    }

    /**
     * Cualquier mensaje del comercio cuenta, también uno automático (un pedido confirmado).
     *
     * @test
     */
    public function un_mensaje_automatico_reciente_del_comercio_tambien_frena_el_mail()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $this->mensaje_del_comercio($this->comprador, [
            'type'       => 'order_confirmed',
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->postJson($this->ruta(), ['text' => 'Ya lo preparamos'])->assertStatus(201);

        Notification::assertNotSentTo($this->comprador, RespuestaDelComercioPorMail::class);
    }

    /**
     * @test
     */
    public function vuelve_a_mandar_mail_si_pasaron_31_minutos()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);
        Notification::fake();

        $this->mensaje_del_comercio($this->comprador, ['created_at' => Carbon::now()->subMinutes(31)]);
        // Uno de OTRO comprador hace 1 minuto no cuenta: la regla es por conversación.
        $otro = $this->crear_comprador_de($this->dueno);
        $this->mensaje_del_comercio($otro, ['created_at' => Carbon::now()->subMinute()]);

        $this->postJson($this->ruta(), ['text' => 'Retomo la charla'])->assertStatus(201);

        Notification::assertSentTo($this->comprador, RespuestaDelComercioPorMail::class, function ($notificacion, $canales) {
            return $canales === ['mail'];
        });
    }

    /**
     * Sin email no se manda nada y no se rompe nada. Con el mailer real (`array`), sin falsear las
     * notificaciones: no llega ningún mail al transporte.
     *
     * @test
     */
    public function un_comprador_sin_email_no_recibe_mail_y_no_rompe_nada()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);

        $this->comprador->email = null;
        $this->comprador->save();

        $antes = $this->mails_enviados()->count();

        $id = $this->postJson($this->ruta(), ['text' => 'Hola sin mail'])->assertStatus(201)->json('message.id');

        $this->assertNotNull(Message::find($id));
        $this->assertSame($antes, $this->mails_enviados()->count(), 'Salió un mail a un comprador sin email.');

        // El aviso en vivo sale igual.
        $this->assertCount(1, $this->respuestas_emitidas());
    }

    /**
     * 🔴 El SMTP caído no rompe el envío: 201, mensaje guardado y el aviso en vivo sale igual.
     *
     * @test
     */
    public function un_mailer_que_tira_no_rompe_el_201_ni_el_aviso_en_vivo()
    {
        Event::fake([TiendaChatActualizado::class, RespuestaDelComercio::class]);

        $transporte = new class extends Transport {
            /** @var int */
            public $intentos = 0;

            public function send(\Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
            {
                $this->intentos++;

                throw new \Swift_TransportException('SMTP caído (simulado): Connection refused');
            }
        };

        app('mail.manager')->extend('que-tira', function () use ($transporte) {
            return $transporte;
        });

        config([
            'mail.mailers.que-tira' => ['transport' => 'que-tira'],
            'mail.default'          => 'que-tira',
        ]);

        $id = $this->postJson($this->ruta(), ['text' => 'Esto sale igual'])->assertStatus(201)->json('message.id');

        $this->assertSame(1, $transporte->intentos, 'El mail no llegó a intentar salir: el test no prueba nada.');
        $this->assertSame('Esto sale igual', Message::find($id)->text);
        $this->assertCount(1, $this->respuestas_emitidas());
    }

    /**
     * 🔴 Pusher caído no rompe el envío, y el mail (que no depende de Pusher) sale igual.
     *
     * @test
     */
    public function pusher_caido_no_rompe_el_201_y_el_mail_sale_igual()
    {
        $broadcaster = $this->usar_broadcaster_que_anota(true);

        $antes = $this->mails_enviados()->count();

        $respuesta = $this->postJson($this->ruta(), ['text' => 'Esto sale igual']);

        $respuesta->assertStatus(201);

        $id = $respuesta->json('message.id');

        $this->assertSame('Esto sale igual', Message::find($id)->text);

        // Los dos avisos en vivo intentaron salir y chocaron con el Pusher caído.
        $eventos = array_map(function ($intento) {
            return $intento['evento'];
        }, $broadcaster->intentos);

        $this->assertSame(['TiendaChatActualizado', 'RespuestaDelComercio'], $eventos);

        $mails = $this->mails_enviados();

        $this->assertSame($antes + 1, $mails->count(), 'El mail al comprador no salió.');
        $this->assertSame('Comercio respuesta respondió tu mensaje', $mails->last()->getSubject());
        $this->assertSame([$this->comprador->email], array_keys($mails->last()->getTo()));
        $this->assertStringContainsString('Esto sale igual', $mails->last()->getBody());
    }
}
