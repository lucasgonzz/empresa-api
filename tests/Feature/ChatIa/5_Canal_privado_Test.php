<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Events\SupportMessageRead;
use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión chat-ia-y-modulo-ia — P5: el evento del chat y su canal privado por
 * persona.
 *
 * Lo que protege este archivo es la frontera de tenencia del tiempo real: el
 * canal `chat.user.{auth_user_id}` autoriza SOLO al id de esa persona (sin la
 * rama de owner_id del canal de WhatsApp: el dueño NO escucha los chats de
 * sus empleados ni al revés) y el payload del evento lleva tres claves y ni
 * una letra de la conversación — el texto puede traer saldos de clientes y
 * jamás viaja por Pusher.
 *
 * El POST a broadcasting/auth firma localmente (HMAC con las credenciales de
 * Pusher de config): no sale a la red.
 *
 * Desde la misión pusher-canales-por-sistema (19/10/2026) corre contra el
 * broadcaster de producción (`PusherDePrueba`) y con el nombre FÍSICO del
 * canal (`private-chat.user.{id}@{espacio}`), que es el que pide el SPA. Antes
 * corría con el driver `log` de testing, cuyo `auth()` contesta 200 a todo:
 * los dos casos de 403 de abajo estaban en rojo y no probaban la frontera.
 */
class Canal_privado_Test extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Feature\Broadcast\PusherDePrueba;

    /** @var string Espacio de nombres de este sistema (base de testing + secret de prueba). */
    protected $espacio;

    /** @var User Dueño de la cuenta (la persona Y del plan). */
    protected $comercio;

    /** @var User Empleado del dueño (la persona X del plan). */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

        // El broadcaster de producción, con SDK que no sale a la red y channels.php registrado en él.
        config(['broadcasting.espacio' => '']);
        $this->usar_pusher_de_prueba();
        $this->espacio = \App\Broadcasting\EspacioDeCanales::espacio();

        $this->comercio = User::create([
            'name'         => 'Comercio chat-ia P5',
            'company_name' => 'Ferreteria P5',
            'email'        => 'chat-ia-p5-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado chat-ia P5',
            'email'    => 'chat-ia-p5-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);
    }

    /**
     * POST a broadcasting/auth para un canal, autenticado como quien ya está
     * logueado con actingAs. El socket_id es el formato que Pusher valida.
     *
     * @param string $channel_name
     * @return \Illuminate\Testing\TestResponse
     */
    protected function autorizar_canal($channel_name)
    {
        return $this->postJson('broadcasting/auth', [
            'socket_id'    => '1234.5678',
            // El SPA pide el nombre físico: el lógico más el sufijo del espacio de este sistema.
            'channel_name' => $channel_name . '@' . $this->espacio,
        ]);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_empleado_autoriza_su_canal_y_no_el_del_dueno()
    {
        $this->actingAs($this->empleado, 'web');

        $this->autorizar_canal('private-chat.user.' . $this->empleado->id)
            ->assertStatus(200);

        // Sin la rama de owner: ser empleado del dueño NO abre el canal del dueño.
        $this->autorizar_canal('private-chat.user.' . $this->comercio->id)
            ->assertStatus(403);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_dueno_autoriza_su_canal_y_no_el_del_empleado()
    {
        $this->actingAs($this->comercio, 'web');

        $this->autorizar_canal('private-chat.user.' . $this->comercio->id)
            ->assertStatus(200);

        // La jerarquía tampoco corre al revés: el dueño no espía el chat del empleado.
        $this->autorizar_canal('private-chat.user.' . $this->empleado->id)
            ->assertStatus(403);
    }

    public function test_el_acceso_de_soporte_no_autoriza_el_canal_del_asistente_personal()
    {
        $this->actingAs($this->comercio, 'web')->withSession(['acceso_soporte_token_id' => 1]);

        $this->autorizar_canal('private-chat.user.' . $this->comercio->id)->assertForbidden();
    }

    public function test_el_maestro_autoriza_los_canales_del_dueno_y_no_los_de_otro_usuario()
    {
        $this->actingAs($this->comercio, 'web')->withSession(['master_login_bypass_user_last_activity' => true]);
        foreach (['private-chat.user.', 'private-agentes.support.user.', 'private-support-tour.user.'] as $prefijo) {
            $this->autorizar_canal($prefijo . $this->comercio->id)->assertOk();
            $this->autorizar_canal($prefijo . $this->empleado->id)->assertForbidden();
        }
    }

    public function test_agentes_autoriza_el_canal_de_soporte_solo_a_la_persona()
    {
        $this->actingAs($this->comercio, 'web');
        $canal = 'private-agentes.support.user.' . $this->comercio->id;
        $this->autorizar_canal($canal)->assertOk();

        $this->actingAs($this->empleado, 'web');
        $this->autorizar_canal($canal)->assertForbidden();

        $this->actingAs($this->comercio, 'web')->withSession(['acceso_soporte_token_id' => 1]);
        $this->autorizar_canal($canal)->assertForbidden();
    }

    public function test_los_eventos_de_agentes_usan_el_canal_privado_y_el_legado_conserva_su_canal()
    {
        $usuario = $this->comercio->id;
        foreach ([
            ['whatsapp', false, 'private-agentes.support.user.'],
            ['sistema', true, 'private-agentes.support.user.'],
            ['sistema', false, 'support.user.'],
        ] as [$origen, $desde_agentes, $canal]) {
            $ticket = SupportTicket::create(['user_id' => $usuario, 'origen' => $origen, 'desde_agentes' => $desde_agentes]);
            $mensaje = SupportMessage::create([
                'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'sender_user_id' => $usuario,
                'kind' => 'text', 'body' => 'Consulta de prueba',
            ]);

            $this->assertSame($canal . $usuario, (string) (new SupportMessageReceived($mensaje->id, $usuario))->broadcastOn());
            $this->assertSame($canal . $usuario, (string) (new SupportMessageRead($mensaje->id))->broadcastOn()[0]);
            $this->assertSame($canal . $usuario, (string) (new SupportTicketUpdated($usuario, $ticket->id))->broadcastOn()[0]);
        }
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_payload_del_evento_lleva_exactamente_tres_claves_y_ningun_texto()
    {
        $evento = new ChatIaMensajeActualizado($this->empleado->id, 12, 34, 'listo');

        /*
         * assertEquals contra el array completo: si mañana alguien le suma
         * `contenido` (o cualquier otra clave) al broadcastWith, este assert
         * lo denuncia. El texto de la respuesta se pide por REST, nunca por
         * Pusher.
         */
        $this->assertEquals([
            'ai_conversation_id' => 12,
            'ai_message_id'      => 34,
            'estado'             => 'listo',
        ], $evento->broadcastWith());
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_evento_sale_por_el_canal_privado_de_la_persona_con_su_nombre_corto()
    {
        $evento = new ChatIaMensajeActualizado($this->empleado->id, 12, 34, 'error');

        $canal = $evento->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $canal);
        $this->assertEquals('private-chat.user.' . $this->empleado->id, (string) $canal);

        // El nombre corto es el que la SPA escucha con .listen('.ChatIaMensajeActualizado', ...).
        $this->assertEquals('ChatIaMensajeActualizado', $evento->broadcastAs());
    }
}
