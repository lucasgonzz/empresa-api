<?php

namespace Tests\Feature\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión patch-chat-nuevo-en-vivo (16/10/2026): un chat que todavía no está en la bandeja de la SPA (un cliente
 * que escribe por PRIMERA VEZ) tiene que aparecer en vivo, con su fila, el rojo/amarillo y el seguimiento del agente.
 *
 * El broadcast `WhatsappChatUpdated` es liviano a propósito (no trae `phone`, `display_name` ni `client`), así que
 * la SPA no puede armar la fila con lo que le llega: le pide el chat a `GET whatsapp-chats/{id}`. Lo que protege
 * este archivo es ese endpoint:
 *
 * - 🔴 Devuelve la MISMA fila que el índice (`GET whatsapp-chats`): la relación `client` (un teléfono autovinculado
 *   a un cliente tiene que mostrar su nombre, no el número), `estado_pendiente` (rojo/amarillo) y el seguimiento
 *   (`follow_up*`). Si alguna vez difieren, la fila que aparece en vivo y la que aparece al recargar no serían la misma.
 * - Dice lo mismo que el `chat` liviano del broadcast del mismo momento: la SPA mezcla uno sobre el otro.
 * - 404 si el chat es de otro negocio o no existe; el empleado accede al chat de su dueño; exige sesión y la extensión.
 * - La ruta nueva no se come a `whatsapp-chats/resumen`, y un id que no es un número no entra a ella.
 */
class Chat_nuevo_en_vivo_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** Teléfono del cliente final que escribe por primera vez (con prefijo de país). */
    const PHONE = '5493416007777';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    protected function setUp(): void
    {
        parent::setUp();

        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F30',
            'company_name' => 'Ferreteria F30',
            'email'        => 'whatsapp-f30-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F30',
            'email'    => 'whatsapp-f30-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-f30',
            'phone_number_id'    => '5491100000030',
            'webhook_secret'     => 'secreto-f30',
            'is_active'          => true,
            'ai_enabled_default' => true,
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
     * El primer mensaje de un cliente nuevo, por el mismo camino que el webhook (crea el chat, lo
     * autovincula si el teléfono coincide con un único cliente y emite `WhatsappChatUpdated`).
     *
     * @param  string  $phone
     * @param  string  $body
     * @return WhatsappChat
     */
    protected function primer_mensaje($phone = self::PHONE, $body = 'Hola, quiero 2 taladros')
    {
        // Nombre del perfil de WhatsApp tal como lo busca `extract_contact_name()` en el webhook de Kapso.
        $payload = ['contact' => ['name' => 'Marcos Gómez']];

        return WhatsappChatHelper::store_inbound_message($this->comercio->id, $phone, $body, $payload, $this->config);
    }

    /**
     * El `chat` liviano del último `WhatsappChatUpdated` emitido, tal como lo recibiría la SPA (por JSON).
     *
     * @param  int  $chat_id
     * @return array
     */
    protected function chat_del_broadcast($chat_id)
    {
        $recibido = null;

        Event::assertDispatched(WhatsappChatUpdated::class, function ($evento) use ($chat_id, &$recibido) {
            if ((int) $evento->chat->id === (int) $chat_id) {
                $recibido = json_decode(json_encode($evento->broadcastWith()['chat']), true);
            }

            return true;
        });

        $this->assertNotNull($recibido, 'El entrante tenía que emitir WhatsappChatUpdated.');

        return $recibido;
    }

    /**
     * La fila del chat en el índice (`GET whatsapp-chats`), o null si no está.
     *
     * @param  int  $chat_id
     * @return array|null
     */
    protected function fila_del_indice($chat_id)
    {
        foreach ($this->getJson('api/whatsapp-chats')->assertStatus(200)->json('models') as $fila) {
            if ((int) $fila['id'] === (int) $chat_id) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * @group whatsapp
     * @test
     */
    public function un_cliente_nuevo_se_pide_con_la_misma_forma_que_una_fila_del_indice()
    {
        Event::fake([WhatsappChatUpdated::class]);

        // El teléfono coincide con un único cliente del negocio: el chat nace autovinculado.
        $cliente = Client::create([
            'name'    => 'Marcos Gómez SRL',
            'user_id' => $this->comercio->id,
            'phone'   => '3416007777',
        ]);

        $chat = $this->primer_mensaje();
        // El agente armó el pedido en su primera respuesta: el chat queda con seguimiento.
        $chat->mark_follow_up('pedido', '2 taladros percutores - Marcos Gómez');
        $chat->save();

        $this->actingAs($this->empleado, 'web');

        $response = $this->getJson('api/whatsapp-chats/' . $chat->id);

        $response->assertStatus(200);
        $this->assertEquals($chat->id, $response->json('model.id'));
        $this->assertEquals(self::PHONE, $response->json('model.phone'));
        $this->assertEquals('Marcos Gómez', $response->json('model.display_name'));
        $this->assertEquals($cliente->id, $response->json('model.client_id'));
        $this->assertEquals('Marcos Gómez SRL', $response->json('model.client.name'), 'La relación client tiene que viajar: sin ella la fila muestra el número.');
        // Rojo (el cliente escribió y nadie contestó) y, aparte, el pedido que dejó el agente.
        $this->assertEquals('sin_responder', $response->json('model.estado_pendiente'));
        $this->assertEquals('pedido', $response->json('model.follow_up'));
        $this->assertEquals('2 taladros percutores - Marcos Gómez', $response->json('model.follow_up_note'));
        $this->assertNotNull($response->json('model.follow_up_at'));
        $this->assertEquals(1, $response->json('model.unread_count'));
        $this->assertTrue((bool) $response->json('model.ai_enabled'));

        // 🔴 Es la misma fila, campo por campo, que la del índice: lo que aparece en vivo no puede diferir de lo que aparece al recargar.
        $this->assertEquals($this->fila_del_indice($chat->id), $response->json('model'));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_chat_del_endpoint_dice_lo_mismo_que_el_chat_liviano_del_broadcast()
    {
        Event::fake([WhatsappChatUpdated::class]);

        $chat = $this->primer_mensaje();
        $chat->mark_follow_up('consulta', 'Si hacen envíos a San Benito');
        $chat->save();

        $this->actingAs($this->empleado, 'web');
        $modelo = $this->getJson('api/whatsapp-chats/' . $chat->id)->assertStatus(200)->json('model');

        // La SPA mezcla el chat del endpoint con los avisos que le siguen: tienen que coincidir en todo lo que comparten.
        // (El broadcast se emitió ANTES de marcar el seguimiento: se emite otro, como hace el envío del agente.)
        WhatsappChatHelper::broadcast_update((int) $this->comercio->id, $chat->fresh());
        $liviano = $this->chat_del_broadcast($chat->id);

        foreach (['id', 'unread_count', 'last_message_at', 'estado_pendiente', 'follow_up', 'follow_up_note', 'follow_up_at'] as $campo) {
            $this->assertArrayHasKey($campo, $liviano);
            $this->assertEquals($liviano[$campo], $modelo[$campo], 'El campo «' . $campo . '» del endpoint y del broadcast tienen que coincidir.');
        }
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_respuesta_esperando_aprobacion_viaja_en_amarillo_y_gana_al_rojo()
    {
        $chat = $this->primer_mensaje();
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => 'ia',
            'body'             => 'Te armo el pedido.',
            'ai_status'        => 'a_confirmar',
        ]);

        $this->actingAs($this->empleado, 'web');
        $response = $this->getJson('api/whatsapp-chats/' . $chat->id);

        $response->assertStatus(200);
        $this->assertEquals('esperando_aprobacion', $response->json('model.estado_pendiente'));
        $this->assertEquals($this->fila_del_indice($chat->id), $response->json('model'));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function un_chat_ya_contestado_viaja_sin_estado_ni_seguimiento()
    {
        $chat = $this->primer_mensaje();
        WhatsappChatHelper::store_outbound_manual_message($chat, 'Sí, tenemos.', 'wamid.F30', $this->empleado->id);

        $this->actingAs($this->empleado, 'web');
        $response = $this->getJson('api/whatsapp-chats/' . $chat->id);

        $response->assertStatus(200);
        $this->assertNull($response->json('model.estado_pendiente'));
        $this->assertNull($response->json('model.follow_up'));
        $this->assertArrayHasKey('estado_pendiente', $response->json('model'), 'El campo tiene que viajar siempre, también en null.');
        $this->assertEquals($this->fila_del_indice($chat->id), $response->json('model'));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_dueno_tambien_puede_pedir_su_chat()
    {
        $chat = $this->primer_mensaje();

        $this->actingAs($this->comercio, 'web');

        $this->getJson('api/whatsapp-chats/' . $chat->id)
            ->assertStatus(200)
            ->assertJsonPath('model.id', $chat->id);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_chat_de_otro_negocio_y_el_que_no_existe_dan_404()
    {
        $otro_comercio = User::create([
            'name'     => 'Otro comercio F30',
            'email'    => 'whatsapp-f30-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $this->dar_extension($otro_comercio);
        $chat_ajeno = WhatsappChat::create([
            'user_id'      => $otro_comercio->id,
            'phone'        => '5493416008888',
            'ai_enabled'   => true,
            'unread_count' => 0,
        ]);

        $this->actingAs($this->empleado, 'web');

        $this->getJson('api/whatsapp-chats/' . $chat_ajeno->id)->assertStatus(404);
        $this->getJson('api/whatsapp-chats/987654321')->assertStatus(404);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_ruta_nueva_no_se_come_el_resumen_ni_acepta_ids_que_no_son_numeros()
    {
        $this->primer_mensaje();

        $this->actingAs($this->empleado, 'web');

        // `resumen` sigue resolviendo a su endpoint (devuelve los contadores, no un chat).
        $resumen = $this->getJson('api/whatsapp-chats/resumen');
        $resumen->assertStatus(200);
        $this->assertArrayHasKey('mensajes_por_aprobar', $resumen->json());
        $this->assertArrayNotHasKey('model', $resumen->json());

        // Un segmento que no es un número no llega al controlador.
        $this->getJson('api/whatsapp-chats/abc')->assertStatus(404);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function sin_sesion_da_401_y_sin_la_extension_da_403()
    {
        $chat = $this->primer_mensaje();

        $this->getJson('api/whatsapp-chats/' . $chat->id)->assertStatus(401);

        $sin_extension = User::create([
            'name'     => 'Comercio sin WhatsApp F30',
            'email'    => 'whatsapp-f30-sin-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $this->actingAs($sin_extension, 'web');

        $this->getJson('api/whatsapp-chats/' . $chat->id)->assertStatus(403);
    }
}
