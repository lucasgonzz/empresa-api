<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAiAutoSendScheduler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión sugerencia-ia-como-borrador (29/9/2026): "Sugerir respuesta" deja de cargar el texto
 * en el input y pasa a guardarlo como una burbuja `a_confirmar`, igual que la respuesta del
 * agente automático. Cubre los tres contratos nuevos con empresa-spa:
 *
 *   1. `POST whatsapp-chats/{id}/suggest` — ahora persiste (D1, D2) y devuelve
 *      `{ suggestion, model, chat }`.
 *   2. `PUT whatsapp-chats/messages/{message_id}` — edita el cuerpo y/o pausa el auto-envío
 *      (D5) de una respuesta pendiente.
 *   3. `GET whatsapp-chats/resumen` — los números de la campanita del menú y de la tarjeta
 *      del tablero, acotados al dueño.
 *
 * Lo que protege este archivo:
 *
 * - D1: la sugerencia nace `a_confirmar` SIN `ai_auto_send_at` (nadie la programó, la pidió
 *   una persona a mano).
 * - D2: pedir una sugerencia nueva reemplaza (borra) la que ya hubiera esperando aprobación.
 * - Sin sugerencia (la IA no contestó nada) sigue sin persistir nada, igual que antes de esta
 *   misión.
 * - El UPDATE condicional del contrato 2: gana quien encuentra la fila en 'a_confirmar', pierde
 *   quien la encuentra ya 'enviada'; pertenencia por owner (404 si es de otro negocio).
 * - D5: entrar en modo edición (PUT sin `body`) cancela el auto-envío sin tocar el texto.
 * - Que confirmar DESPUÉS de editar manda el texto editado, no el original.
 * - `GET resumen` cuenta solo los mensajes y chats del dueño autenticado, nunca los de otro.
 *
 * 🔴 `Queue::fake()` en todos los casos: con `QUEUE_CONNECTION=sync` el `->delay()` del
 * auto-envío se ignora, y acá D1 exige que la sugerencia NUNCA programe uno.
 */
class Sugerencia_como_borrador_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** Teléfono del cliente final. */
    const PHONE = '5493416025555';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    /** Texto que devuelve el fake de Anthropic; se lee al momento de la llamada. */
    protected $texto_de_la_ia = 'Respuesta sugerida por la IA.';

    /** Payloads que viajaron a Kapso. */
    protected $payloads_kapso = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F25',
            'company_name' => 'Ferreteria F25',
            'email'        => 'whatsapp-f25-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F25',
            'email'    => 'whatsapp-f25-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-f25',
            'phone_number_id'    => '5491100000025',
            'webhook_secret'     => 'secreto-f25',
            'is_active'          => true,
            'ai_enabled_default' => true,
        ]);

        $this->dar_extension();
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
     * Stubs de las tres APIs externas. El `'*'` final evita que una request sin stub salga a
     * la red de verdad (Laravel 8 acumula los stubs de `Http::fake()`).
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

                return Http::response(['messages' => [['id' => 'wamid.F25']]], 200);
            },
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * Chat con la ventana de 24 h abierta y un mensaje entrante sin responder.
     *
     * @param  User  $owner
     * @param  string  $phone
     * @return WhatsappChat
     */
    protected function chat_con_entrante(User $owner, $phone = self::PHONE)
    {
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
            'body'             => 'hola, ¿tenés tornillos?',
        ]);

        return $chat;
    }

    /**
     * @param  WhatsappChat  $chat
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function salientes(WhatsappChat $chat)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->get();
    }

    /**
     * D1 + contrato 1: la sugerencia queda persistida como `a_confirmar`, SIN temporizador de
     * auto-envío, y la respuesta trae `suggestion` (compatibilidad) + `model` + `chat`.
     *
     * @group whatsapp
     * @test
     */
    public function suggest_persiste_a_confirmar_sin_auto_envio_y_devuelve_model_y_chat()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->texto_de_la_ia = 'Sí, tenemos tornillos de varios tamaños.';
        $this->fakes_de_red();

        $chat = $this->chat_con_entrante($this->comercio);
        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $chat->id . '/suggest');

        $response->assertStatus(200);
        $this->assertEquals('Sí, tenemos tornillos de varios tamaños.', $response->json('suggestion'));
        $this->assertEquals('Sí, tenemos tornillos de varios tamaños.', $response->json('model.body'));
        $this->assertEquals('a_confirmar', $response->json('model.ai_status'));
        $this->assertNull($response->json('model.ai_auto_send_at'), 'D1: nadie programó un auto-envío, la pidió una persona a mano.');
        $this->assertEquals($chat->id, $response->json('chat.id'));
        $this->assertEquals('esperando_aprobacion', $response->json('chat.estado_pendiente'));

        $salientes = $this->salientes($chat);
        $this->assertCount(1, $salientes, 'Quedó persistida: antes de esta misión suggest() no tocaba la base.');
        $this->assertEquals('a_confirmar', $salientes[0]->ai_status);
        $this->assertNull($salientes[0]->ai_auto_send_at);
        $this->assertEquals(0, count($this->payloads_kapso), 'Todavía no se confirmó: no puede haber salido nada.');
    }

    /**
     * D2: pedir una sugerencia nueva reemplaza (borra) la que ya hubiera esperando aprobación
     * en el chat. Nunca dos borradores a la vez.
     *
     * @group whatsapp
     * @test
     */
    public function suggest_reemplaza_la_pendiente_anterior()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        $chat = $this->chat_con_entrante($this->comercio);
        $anterior = WhatsappChatHelper::store_pending_ai_message($chat, 'Borrador viejo que ya no sirve.');
        $this->actingAs($this->empleado, 'web');

        $this->texto_de_la_ia = 'Borrador nuevo.';
        $response = $this->postJson('api/whatsapp-chats/' . $chat->id . '/suggest');

        $response->assertStatus(200);
        $this->assertNull(WhatsappChatMessage::find($anterior->id), 'El borrador viejo se borró: D2 no permite dos a la vez.');

        $salientes = $this->salientes($chat);
        $this->assertCount(1, $salientes, 'Solo queda la sugerencia nueva.');
        $this->assertEquals('Borrador nuevo.', $salientes[0]->body);
    }

    /**
     * Sin sugerencia (la IA no devolvió texto) sigue sin persistir nada, igual que antes de
     * esta misión: no hay nada que aprobar si no hubo respuesta.
     *
     * @group whatsapp
     * @test
     */
    public function suggest_con_ia_vacia_devuelve_422_y_no_persiste_nada()
    {
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        // Chat sin ningún mensaje: motivo 'sin_historial', un body vacío garantizado sin
        // mockear nada raro de Anthropic (mismo camino que usa 5_Tokens_agente_Test.php).
        $chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => '5493416025556',
            'ai_enabled'      => true,
            'unread_count'    => 0,
            'last_inbound_at' => now(),
        ]);
        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $chat->id . '/suggest');

        $response->assertStatus(422);
        $this->assertEquals('', $response->json('suggestion'));
        $this->assertNull($response->json('model'));
        $this->assertCount(0, $this->salientes($chat));
    }

    /**
     * Contrato 2: el PUT reemplaza el cuerpo cuando llega `body` con texto.
     *
     * @group whatsapp
     * @test
     */
    public function put_edita_el_body_de_una_pendiente()
    {
        $chat = $this->chat_con_entrante($this->comercio);
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Texto original.');
        $this->actingAs($this->empleado, 'web');

        $response = $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id, ['body' => 'Texto editado por el operador.']);

        $response->assertStatus(200);
        $this->assertEquals('Texto editado por el operador.', $response->json('model.body'));

        $mensaje->refresh();
        $this->assertEquals('Texto editado por el operador.', $mensaje->body);
        $this->assertEquals('a_confirmar', $mensaje->ai_status, 'Editar no confirma ni descarta.');
    }

    /**
     * D5: el PUT sin `body` solo pausa (cancela el auto-envío), sin tocar el texto. Es lo que
     * el front llama al entrar en modo edición sobre una pendiente con `ai_auto_send_at`.
     *
     * @group whatsapp
     * @test
     */
    public function put_sin_body_solo_pausa_el_auto_envio()
    {
        Queue::fake();

        $this->config->ai_confirm_delay_seconds = 120;
        $this->config->save();

        $chat = $this->chat_con_entrante($this->comercio);
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Texto que no cambia.');

        $scheduler = new WhatsappAiAutoSendScheduler();
        $scheduler->schedule_for_message($mensaje, $this->config);
        $this->assertNotNull($mensaje->fresh()->ai_auto_send_at);

        $this->actingAs($this->empleado, 'web');
        $response = $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id);

        $response->assertStatus(200);
        $this->assertEquals('Texto que no cambia.', $response->json('model.body'), 'Sin `body`, el texto no se toca.');

        $mensaje->refresh();
        $this->assertEquals('Texto que no cambia.', $mensaje->body);
        $this->assertNull($mensaje->ai_auto_send_at, 'D5: entrar en edición cancela el auto-envío.');
        $this->assertFalse(
            $scheduler->is_token_current((int) $mensaje->id, 1),
            'El token del auto-envío quedó invalidado: el job pendiente se va a auto-descartar.'
        );
    }

    /**
     * El PUT sobre un mensaje que ya salió pierde el UPDATE condicional (`WHERE ai_status =
     * 'a_confirmar'`) y devuelve el mismo `code` que confirm/discard para ese caso.
     *
     * @group whatsapp
     * @test
     */
    public function put_sobre_un_mensaje_ya_enviado_devuelve_422_ya_no_esta_pendiente()
    {
        $chat = $this->chat_con_entrante($this->comercio);
        $mensaje = WhatsappChatHelper::store_outbound_ai_message($chat, 'Ya salió hace rato.', 'wamid.VIEJO');

        $this->actingAs($this->empleado, 'web');
        $response = $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id, ['body' => 'Intento de editar algo ya enviado.']);

        $response->assertStatus(422);
        $this->assertEquals('ya_no_esta_pendiente', $response->json('code'));

        $mensaje->refresh();
        $this->assertEquals('Ya salió hace rato.', $mensaje->body, 'Un pedido rechazado no puede dejar el texto a medio camino.');
    }

    /**
     * Pertenencia: un empleado de otro negocio no puede editar un mensaje ajeno pasando
     * cualquier id. `find_owned_ai_message()` filtra por el chat, no por el mensaje suelto.
     *
     * @group whatsapp
     * @test
     */
    public function put_sobre_un_mensaje_de_otro_negocio_devuelve_404()
    {
        $otro_comercio = User::create([
            'name'     => 'Otro comercio F25',
            'email'    => 'whatsapp-f25-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $chat_ajeno = $this->chat_con_entrante($otro_comercio, '5493416029999');
        $mensaje_ajeno = WhatsappChatHelper::store_pending_ai_message($chat_ajeno, 'Respuesta de otra empresa.');

        $this->actingAs($this->empleado, 'web');
        $response = $this->putJson('api/whatsapp-chats/messages/' . $mensaje_ajeno->id, ['body' => 'No debería poder tocar esto.']);

        $response->assertStatus(404);

        $mensaje_ajeno->refresh();
        $this->assertEquals('Respuesta de otra empresa.', $mensaje_ajeno->body, 'El mensaje ajeno queda intacto.');
    }

    /**
     * `body` presente pero vacío (o solo espacios) es un 422 de validación, distinto de "no
     * vino la clave" (que solo pausa).
     *
     * @group whatsapp
     * @test
     */
    public function put_con_body_vacio_devuelve_422()
    {
        $chat = $this->chat_con_entrante($this->comercio);
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Texto que tiene que sobrevivir.');

        $this->actingAs($this->empleado, 'web');
        $response = $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id, ['body' => '   ']);

        $response->assertStatus(422);
        $this->assertNotNull($response->json('message'));

        $mensaje->refresh();
        $this->assertEquals('Texto que tiene que sobrevivir.', $mensaje->body);
        $this->assertEquals('a_confirmar', $mensaje->ai_status);
    }

    /**
     * `GET resumen` cuenta solo lo del dueño autenticado, nunca lo de otro negocio.
     *
     * @group whatsapp
     * @test
     */
    public function resumen_cuenta_solo_lo_del_dueno_autenticado()
    {
        $chat_propio_1 = $this->chat_con_entrante($this->comercio, '5493416025557');
        $chat_propio_2 = $this->chat_con_entrante($this->comercio, '5493416025558');
        WhatsappChatHelper::store_pending_ai_message($chat_propio_1, 'Pendiente 1.');
        WhatsappChatHelper::store_pending_ai_message($chat_propio_2, 'Pendiente 2.');

        $otro_comercio = User::create([
            'name'     => 'Otro comercio F25-resumen',
            'email'    => 'whatsapp-f25-resumen-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $chat_ajeno = $this->chat_con_entrante($otro_comercio, '5493416029998');
        WhatsappChatHelper::store_pending_ai_message($chat_ajeno, 'Pendiente ajeno, no tiene que contar.');

        $this->actingAs($this->empleado, 'web');
        $response = $this->getJson('api/whatsapp-chats/resumen');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('mensajes_por_aprobar'));
        $this->assertEquals(2, $response->json('chats_esperando_aprobacion'));
    }

    /**
     * Confirmar DESPUÉS de editar manda el texto editado, no el original: es el flujo real
     * del botón "Enviar" de la burbuja cuando el operador cambió algo antes de mandarla.
     *
     * @group whatsapp
     * @test
     */
    public function confirmar_despues_de_editar_manda_el_texto_editado()
    {
        $this->fakes_de_red();

        $chat = $this->chat_con_entrante($this->comercio);
        $mensaje = WhatsappChatHelper::store_pending_ai_message($chat, 'Texto original de la sugerencia.');

        $this->actingAs($this->empleado, 'web');

        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id, ['body' => 'Texto que el operador corrigió a mano.'])
            ->assertStatus(200);

        $this->putJson('api/whatsapp-chats/messages/' . $mensaje->id . '/confirm')->assertStatus(200);

        $this->assertEquals(1, count($this->payloads_kapso));
        $this->assertEquals('Texto que el operador corrigió a mano.', $this->payloads_kapso[0]['text']['body']);

        $mensaje->refresh();
        $this->assertEquals('enviado', $mensaje->ai_status);
        $this->assertEquals('Texto que el operador corrigió a mano.', $mensaje->body);
    }
}
