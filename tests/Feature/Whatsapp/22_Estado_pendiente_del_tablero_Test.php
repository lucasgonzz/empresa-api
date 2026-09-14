<?php

namespace Tests\Feature\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión whatsapp-tablero-clientes — F8/22: el estado `estado_pendiente` que alimenta las
 * tres tarjetas del tablero y el resaltado de la fila en la bandeja.
 *
 * Lo que protege este archivo:
 *
 * - El criterio de "sin responder" (`WhatsappChat::is_sin_responder()`): gana cuando el
 *   último mensaje real del chat es entrante.
 * - La PRECEDENCIA: un chat con un mensaje `a_confirmar` pendiente da 'esperando_aprobacion'
 *   aunque el criterio crudo de "sin responder" también daría true (el último mensaje real,
 *   excluyendo el pendiente, sigue siendo el entrante) — si esto se rompe, un mismo chat
 *   aparece en las dos tarjetas del tablero a la vez.
 * - Que el cálculo EN LOTE de `WhatsappChatHelper::attach_estados_pendientes()` (el que usa
 *   el índice de la bandeja) da el mismo resultado que el criterio de a-un-chat-por-vez, y
 *   no cruza el estado de un chat con el de otro.
 * - Que `GET /api/whatsapp-chats` expone el campo por chat.
 * - Que el payload del broadcast (`WhatsappChatUpdated`) lo incluye, en los tres valores
 *   posibles ('sin_responder', 'esperando_aprobacion' y null), que es lo que mantiene la
 *   bandeja al día en vivo sin recargar.
 * - Que descartar un pendiente vuelve el chat a 'sin_responder' y no a null: el entrante que
 *   generó la sugerencia sigue sin contestar.
 */
class Estado_pendiente_del_tablero_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-22',
            'company_name' => 'Ferreteria F8-22',
            'email'        => 'whatsapp-f8-22-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F8-22',
            'email'    => 'whatsapp-f8-22-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-f8-22',
            'phone_number_id'    => '5491100000022',
            'webhook_secret'     => 'secreto-f8-22',
            'is_active'          => true,
            'ai_enabled_default' => true,
        ]);
    }

    /**
     * Asigna la extensión al comercio (creando la fila del catálogo si la base del slot
     * todavía no la tiene sembrada). Mismo patrón que el resto de los tests del módulo.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'WhatsApp',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * @param string $phone
     * @return WhatsappChat
     */
    protected function chat_vacio($phone)
    {
        return WhatsappChat::create([
            'user_id'      => $this->comercio->id,
            'phone'        => $phone,
            'ai_enabled'   => true,
            'unread_count' => 0,
        ]);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_solo_un_entrante_el_chat_queda_sin_responder()
    {
        $chat = $this->chat_vacio('5493416002201');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola, ¿tenés tornillos?',
        ]);

        $this->assertEquals('sin_responder', $chat->estado_pendiente());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function un_saliente_ya_dicho_despues_del_entrante_deja_el_chat_al_dia()
    {
        $chat = $this->chat_vacio('5493416002202');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);
        WhatsappChatHelper::store_outbound_manual_message($chat, 'Sí, tenemos.', 'wamid.OK', $this->empleado->id);

        $this->assertNull($chat->fresh()->estado_pendiente());
    }

    /**
     * La precedencia: un `a_confirmar` pendiente gana sobre "sin responder" aunque el
     * criterio crudo de este último también daría true (excluye a propósito los
     * `a_confirmar` al buscar el último mensaje real).
     *
     * @group whatsapp
     * @test
     */
    public function un_mensaje_esperando_aprobacion_gana_sobre_sin_responder()
    {
        $chat = $this->chat_vacio('5493416002203');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);
        WhatsappChatHelper::store_pending_ai_message($chat, 'Respuesta que la IA generó.');

        $chat = $chat->fresh();
        $this->assertTrue($chat->is_sin_responder(), 'El criterio crudo sigue dando true: el pendiente no cuenta como dicho.');
        $this->assertEquals(
            'esperando_aprobacion',
            $chat->estado_pendiente(),
            'Pero el estado efectivo tiene que ser el de aprobación, no los dos a la vez.'
        );
    }

    /**
     * El cálculo en lote (el que usa el índice de la bandeja) tiene que dar el mismo
     * resultado que el criterio de a-un-chat-por-vez, para varios chats simultáneos y sin
     * cruzarlos entre sí.
     *
     * @group whatsapp
     * @test
     */
    public function el_calculo_en_lote_no_cruza_el_estado_de_chats_distintos()
    {
        $sin_responder = $this->chat_vacio('5493416002204');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $sin_responder->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);

        $esperando_aprobacion = $this->chat_vacio('5493416002205');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $esperando_aprobacion->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);
        WhatsappChatHelper::store_pending_ai_message($esperando_aprobacion, 'Respuesta generada.');

        $al_dia = $this->chat_vacio('5493416002206');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $al_dia->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);
        WhatsappChatHelper::store_outbound_manual_message($al_dia, 'Ya te contesté.', 'wamid.OK2', $this->empleado->id);

        $chats = WhatsappChat::whereIn('id', [$sin_responder->id, $esperando_aprobacion->id, $al_dia->id])->get();
        WhatsappChatHelper::attach_estados_pendientes($chats);

        $por_id = $chats->keyBy('id');
        $this->assertEquals('sin_responder', $por_id[$sin_responder->id]->estado_pendiente);
        $this->assertEquals('esperando_aprobacion', $por_id[$esperando_aprobacion->id]->estado_pendiente);
        $this->assertNull($por_id[$al_dia->id]->estado_pendiente);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_indice_de_la_bandeja_expone_el_estado_por_chat()
    {
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');

        $chat = $this->chat_vacio('5493416002207');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);

        $response = $this->getJson('api/whatsapp-chats');

        $response->assertStatus(200);
        $modelo = collect($response->json('models'))->firstWhere('id', $chat->id);
        $this->assertNotNull($modelo, 'El chat recién creado tiene que estar en el índice.');
        $this->assertEquals('sin_responder', $modelo['estado_pendiente']);
    }

    /**
     * El broadcast es lo que mantiene la bandeja al día en vivo: sin este campo acá, la fila
     * se queda con el color viejo hasta que alguien recargue el módulo entero.
     *
     * @group whatsapp
     * @test
     */
    public function el_payload_del_broadcast_incluye_el_estado_pendiente()
    {
        $chat = $this->chat_vacio('5493416002208');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);

        $payload = (new WhatsappChatUpdated((int) $chat->user_id, $chat))->broadcastWith();

        $this->assertEquals('sin_responder', $payload['chat']['estado_pendiente']);
    }

    /**
     * El broadcast también tiene que dar bien los otros dos valores, no solo 'sin_responder'
     * (el único caso que probaba el test de arriba).
     *
     * @group whatsapp
     * @test
     */
    public function el_broadcast_tambien_expone_esperando_aprobacion_y_null()
    {
        $esperando_aprobacion = $this->chat_vacio('5493416002209');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $esperando_aprobacion->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);
        WhatsappChatHelper::store_pending_ai_message($esperando_aprobacion, 'Respuesta generada.');

        $payload_pendiente = (new WhatsappChatUpdated((int) $esperando_aprobacion->user_id, $esperando_aprobacion->fresh()))->broadcastWith();
        $this->assertEquals('esperando_aprobacion', $payload_pendiente['chat']['estado_pendiente']);

        $al_dia = $this->chat_vacio('5493416002210');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $al_dia->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);
        WhatsappChatHelper::store_outbound_manual_message($al_dia, 'Ya te contesté.', 'wamid.OK3', $this->empleado->id);

        $payload_al_dia = (new WhatsappChatUpdated((int) $al_dia->user_id, $al_dia->fresh()))->broadcastWith();
        $this->assertNull($payload_al_dia['chat']['estado_pendiente']);
    }

    /**
     * Descartar una respuesta pendiente no deja el chat "al día": el mensaje del cliente que
     * la generó sigue sin contestar, así que el chat tiene que volver a 'sin_responder' (no a
     * null). Es la transición que cubre el operador apretando "no la mandes" en el composer.
     *
     * @group whatsapp
     * @test
     */
    public function descartar_un_pendiente_vuelve_el_chat_a_sin_responder()
    {
        $chat = $this->chat_vacio('5493416002211');
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'hola',
        ]);
        $pendiente = WhatsappChatHelper::store_pending_ai_message($chat, 'Respuesta que el operador va a descartar.');

        $this->assertEquals('esperando_aprobacion', $chat->fresh()->estado_pendiente());

        $descartado = WhatsappChatHelper::discard_pending_ai_message($pendiente);

        $this->assertTrue($descartado);
        $this->assertEquals(
            'sin_responder',
            $chat->fresh()->estado_pendiente(),
            'El entrante que generó la sugerencia sigue sin contestar: no puede quedar al día.'
        );
    }
}
