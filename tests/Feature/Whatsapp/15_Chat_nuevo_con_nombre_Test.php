<?php

namespace Tests\Feature\Whatsapp;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión whatsapp-sidebar-multimedia — el nombre del contacto al abrir un chat desde afuera del
 * módulo (`POST api/whatsapp-chats`).
 *
 * Lo que protege este archivo:
 *
 * 🔴 QUE EL OPERADOR NO VEA UN NÚMERO DE TELÉFONO PELADO. Los tres botones nuevos de la SPA
 * (Clientes, pedidos de la tienda y Compradores) mandan `display_name` junto con el teléfono, y
 * el backend lo descartaba: solo leía `phone` y `client_id`. Desde Clientes igual salía el
 * nombre, porque el chat va vinculado al cliente del ERP y de ahí lo saca la bandeja — por eso
 * el bug pasaba desapercibido. Desde un pedido de la tienda o desde Compradores no hay cliente
 * que lo nombre y el chat quedaba sin nombre para siempre.
 *
 * 🔴 QUE ACEPTARLO NO SE LLEVE PUESTO EL NOMBRE DE UN CHAT QUE YA EXISTÍA. El endpoint es
 * idempotente por teléfono y se lo come cada vez que el operador abre la misma conversación: si
 * pisara el nombre, el chat se llamaría según por dónde entró la última persona y se perdería
 * el nombre del perfil de WhatsApp que dejó Kapso. Rellenar uno vacío sí se hace, que es el
 * mismo criterio de `WhatsappChatHelper::store_inbound_message()`.
 *
 * La columna es `string(120)`: un nombre más largo se rechaza con 422 en vez de dejar que lo
 * corte MySQL.
 */
class Chat_nuevo_con_nombre_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** Teléfono del comprador, tal como lo manda la SPA (solo dígitos). */
    const PHONE = '5493416015555';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp U14-15',
            'company_name' => 'Ferreteria U14-15',
            'email'        => 'whatsapp-u14-15-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp U14-15',
            'email'    => 'whatsapp-u14-15-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-u14-15',
            'phone_number_id'    => '5491100000015',
            'webhook_secret'     => 'secreto-u14-15',
            'is_active'          => true,
            'ai_enabled_default' => false,
        ]);

        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');
    }

    /**
     * Asigna la extensión al comercio (creando la fila del catálogo si la base del slot todavía
     * no la tiene sembrada). El middleware resuelve al owner, así que con dársela al dueño
     * alcanza también para sus empleados.
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
     * Pega al endpoint que abren los tres botones de la SPA.
     *
     * @param array $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function abrir_chat(array $payload)
    {
        return $this->postJson('api/whatsapp-chats', $payload);
    }

    /**
     * Chat ya existente del comercio para el mismo teléfono.
     *
     * @param string|null $display_name
     * @return WhatsappChat
     */
    protected function chat_existente($display_name)
    {
        return WhatsappChat::create([
            'user_id'      => $this->comercio->id,
            'phone'        => self::PHONE,
            'display_name' => $display_name,
            'ai_enabled'   => false,
            'unread_count' => 0,
        ]);
    }

    /**
     * @param string $phone
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function chats($phone)
    {
        return WhatsappChat::where('user_id', $this->comercio->id)
            ->where('phone', $phone)
            ->get();
    }

    /**
     * 🔴 EL BUG. Es el caso del pedido de la tienda y el de Compradores: no hay `client_id` que
     * nombre el chat, así que si el `display_name` se descarta el operador abre la conversación
     * y ve un número de teléfono.
     *
     * @group whatsapp
     * @test
     */
    public function un_chat_nuevo_guarda_el_nombre_que_manda_el_boton()
    {
        $response = $this->abrir_chat([
            'phone'        => self::PHONE,
            'display_name' => 'Marisa Gómez',
        ]);

        $response->assertStatus(201);

        $chats = $this->chats(self::PHONE);
        $this->assertCount(1, $chats);
        $this->assertEquals('Marisa Gómez', $chats[0]->display_name, 'Sin el nombre, el operador ve un número pelado.');
        $this->assertNull($chats[0]->client_id, 'Un comprador de la tienda no es un cliente del ERP.');

        $this->assertEquals('Marisa Gómez', $response->json('model.display_name'), 'El front pinta la bandeja con lo que devuelve esta respuesta.');
    }

    /**
     * El endpoint es idempotente por teléfono y lo llaman los tres botones cada vez que se abre
     * la conversación. Pisar el nombre existente haría que el chat se llame según por dónde
     * entró la última persona, y se llevaría puesto el nombre del perfil de WhatsApp que dejó
     * Kapso al primer entrante.
     *
     * @group whatsapp
     * @test
     */
    public function un_chat_que_ya_tenia_nombre_no_lo_pierde()
    {
        $this->chat_existente('Marisa (perfil de WhatsApp)');

        $response = $this->abrir_chat([
            'phone'        => self::PHONE,
            'display_name' => 'MARISA GOMEZ SRL',
        ]);

        $response->assertStatus(201);

        $chats = $this->chats(self::PHONE);
        $this->assertCount(1, $chats, 'Idempotente: no se duplica el chat.');
        $this->assertEquals('Marisa (perfil de WhatsApp)', $chats[0]->display_name, 'El nombre que ya tenía manda.');
    }

    /**
     * El otro lado de la misma moneda: si el chat existe pero nació sin nombre (un entrante en el
     * que Kapso no mandó el nombre del perfil), rellenarlo no rompe nada y arregla el caso real.
     * Mismo criterio que `WhatsappChatHelper::store_inbound_message()`.
     *
     * @group whatsapp
     * @test
     */
    public function un_chat_que_estaba_sin_nombre_lo_toma_del_boton()
    {
        $this->chat_existente(null);

        $this->abrir_chat([
            'phone'        => self::PHONE,
            'display_name' => 'Marisa Gómez',
        ])->assertStatus(201);

        $chats = $this->chats(self::PHONE);
        $this->assertCount(1, $chats);
        $this->assertEquals('Marisa Gómez', $chats[0]->display_name);
    }

    /**
     * `whatsapp_chats.display_name` es un `string(120)`. Sin validación, un nombre más largo lo
     * corta MySQL por la mitad (o revienta la query, según el modo estricto de la base): mejor
     * rebotarlo con 422 y que el front avise.
     *
     * @group whatsapp
     * @test
     */
    public function un_nombre_mas_largo_que_la_columna_se_rechaza()
    {
        $response = $this->abrir_chat([
            'phone'        => self::PHONE,
            'display_name' => str_repeat('a', 121),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('display_name');

        $this->assertCount(0, $this->chats(self::PHONE), 'Si el nombre no entra, tampoco se crea el chat.');
    }

    /**
     * Los callers viejos (el modal de chat nuevo del propio módulo) no mandan `display_name` y
     * tienen que seguir andando igual: el campo es opcional, no obligatorio.
     *
     * @group whatsapp
     * @test
     */
    public function sin_display_name_el_chat_se_crea_como_siempre()
    {
        $response = $this->abrir_chat(['phone' => '+54 9 341 601-6666']);

        $response->assertStatus(201);

        $chats = $this->chats('5493416016666');
        $this->assertCount(1, $chats, 'El teléfono se sigue normalizando a solo dígitos.');
        $this->assertNull($chats[0]->display_name);
    }
}
