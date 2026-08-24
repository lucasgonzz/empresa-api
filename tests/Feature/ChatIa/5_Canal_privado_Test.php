<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
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
 */
class Canal_privado_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User Dueño de la cuenta (la persona Y del plan). */
    protected $comercio;

    /** @var User Empleado del dueño (la persona X del plan). */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

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
            'channel_name' => $channel_name,
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
