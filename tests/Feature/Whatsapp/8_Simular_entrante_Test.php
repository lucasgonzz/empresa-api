<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/8: simulación de mensajes entrantes.
 *
 * El endpoint inyecta un mensaje como si lo hubiera mandado el cliente por WhatsApp, para
 * poder probar el agente de punta a punta sin depender de que alguien escriba de verdad a
 * un número de Kapso. La fila que queda es idéntica a la de un mensaje real (`direction`
 * 'in', `source` 'cliente'): ese es el punto, y por eso el gate tiene que ser serio.
 *
 * 🔴 Lo más importante que protege este archivo es el AISLAMIENTO. El webhook resuelve la
 * config con el atajo mono-tenant `where('is_active', true)->first()` (bug preexistente,
 * fuera del alcance de la misión). Si la simulación copiara ese atajo, el dueño de una
 * empresa podría inyectar mensajes en la cuenta de otra. Acá la config se resuelve SIEMPRE
 * por el usuario autenticado.
 */
class Simular_entrante_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'whatsapp';

    /** Ruta del endpoint de simulación. */
    const RUTA = 'api/whatsapp-bot/simulate-inbound';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: ningún test de esta suite sale a la red.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-8',
            'company_name' => 'Ferreteria F8-8',
            'email'        => 'whatsapp-f8-8-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F8-8',
            'email'    => 'whatsapp-f8-8-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-f8-8',
            'phone_number_id'    => '5491100000008',
            'webhook_secret'     => 'secreto-f8-8',
            'is_active'          => true,
            'ai_enabled_default' => true,
        ]);
    }

    /**
     * Asigna la extensión al comercio (creándola si la base del slot no la tiene sembrada).
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
     * @group whatsapp
     * @test
     */
    public function sin_autenticacion_devuelve_401()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->postJson(self::RUTA, ['phone' => '3416005566', 'body' => 'hola'])->assertStatus(401);

        $this->assertEquals(0, WhatsappChat::where('user_id', $this->comercio->id)->count());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function sin_la_extension_whatsapp_devuelve_403()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs($this->comercio, 'web');

        $this->postJson(self::RUTA, ['phone' => '3416005566', 'body' => 'hola'])->assertStatus(403);

        $this->assertEquals(0, WhatsappChat::where('user_id', $this->comercio->id)->count());
    }

    /**
     * Un empleado puede operar los chats, pero inyectar entrantes es del dueño: la fila
     * resultante es indistinguible de un mensaje real del cliente.
     *
     * @group whatsapp
     * @test
     */
    public function un_empleado_no_puede_simular_entrantes()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');

        $this->postJson(self::RUTA, ['phone' => '3416005566', 'body' => 'hola'])->assertStatus(403);

        $this->assertEquals(0, WhatsappChat::where('user_id', $this->comercio->id)->count());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_dueno_simula_y_queda_un_entrante_igual_a_uno_real()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $response = $this->postJson(self::RUTA, [
            'phone' => '+54 9 341 600-5566',
            'body'  => 'hola, ¿tenés tornillos?',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('5493416005566', $response->json('model.phone'), 'El teléfono se persiste normalizado.');

        $chat = WhatsappChat::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($chat);
        $this->assertEquals(1, (int) $chat->unread_count);
        $this->assertNotNull(
            $chat->last_inbound_at,
            'La ventana de 24 h se abre igual que con un mensaje real: es lo que permite probar el agente.'
        );
        $this->assertTrue($chat->is_within_service_window());

        $mensajes = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->get();
        $this->assertCount(1, $mensajes);
        $this->assertEquals('in', $mensajes[0]->direction);
        $this->assertEquals('cliente', $mensajes[0]->source, 'La fila es indistinguible de un entrante real.');
        $this->assertEquals('hola, ¿tenés tornillos?', $mensajes[0]->body);
    }

    /**
     * La simulación recorre el MISMO camino que el webhook, debounce incluido.
     *
     * Se configura una demora a propósito: con `ai_reply_delay_seconds = 0` el scheduler
     * despacha en `sync` + `afterResponse`, o sea que el job CORRE (no se encola) al
     * terminar el request y `Queue::assertPushed` no lo vería. Con demora, el despacho va
     * a la cola y es observable, que es justo lo que se quiere afirmar acá.
     *
     * @group whatsapp
     * @test
     */
    public function la_simulacion_dispara_el_agente()
    {
        Http::fake(['*' => Http::response([], 200)]);
        Queue::fake();
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $this->config->ai_reply_delay_seconds = 30;
        $this->config->save();

        $this->postJson(self::RUTA, ['phone' => '3416005566', 'body' => 'hola'])->assertStatus(201);

        Queue::assertPushed(GenerateWhatsappAiReplyJob::class, 1);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function sin_telefono_o_sin_cuerpo_devuelve_422()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $this->postJson(self::RUTA, ['phone' => '', 'body' => 'hola'])->assertStatus(422);
        $this->postJson(self::RUTA, ['phone' => '3416005566', 'body' => ''])->assertStatus(422);
        $this->postJson(self::RUTA, ['body' => 'hola'])->assertStatus(422);
        $this->postJson(self::RUTA, ['phone' => '3416005566'])->assertStatus(422);
        // Solo espacios pasa la validación pero no es un mensaje: lo corta el guard del controller.
        $this->postJson(self::RUTA, ['phone' => '3416005566', 'body' => '   '])->assertStatus(422);

        $this->assertEquals(0, WhatsappChat::where('user_id', $this->comercio->id)->count());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function sin_configuracion_de_whatsapp_devuelve_422()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $sin_config = User::create([
            'name'     => 'Comercio sin config F8-8',
            'email'    => 'whatsapp-f8-8-sinconfig-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();
        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'WhatsApp']);
        }
        $sin_config->extencions()->attach($extencion->id);

        $this->actingAs($sin_config, 'web');

        $this->postJson(self::RUTA, ['phone' => '3416005566', 'body' => 'hola'])->assertStatus(422);
    }

    /**
     * 🔴 Aislamiento entre empresas: aunque otra empresa tenga la config ACTIVA (que es lo
     * que agarraría el atajo mono-tenant del webhook), el mensaje simulado cae siempre en
     * la cuenta del que está autenticado.
     *
     * @group whatsapp
     * @test
     */
    public function un_dueno_no_puede_inyectar_en_la_cuenta_de_otra_empresa()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->dar_extension();

        $otro_comercio = User::create([
            'name'     => 'Otro comercio F8-8',
            'email'    => 'whatsapp-f8-8-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        // El otro comercio tiene su config ACTIVA: es la que devolvería
        // `WhatsappBotConfig::where('is_active', true)->first()` si el orden lo favoreciera.
        WhatsappBotConfig::create([
            'user_id'         => $otro_comercio->id,
            'kapso_api_key'   => 'kapso-del-otro',
            'phone_number_id' => '5491199999999',
            'webhook_secret'  => 'secreto-del-otro',
            'is_active'       => true,
        ]);

        $this->actingAs($this->comercio, 'web');

        $this->postJson(self::RUTA, ['phone' => '3416005566', 'body' => 'hola'])->assertStatus(201);

        $this->assertEquals(
            1,
            WhatsappChat::where('user_id', $this->comercio->id)->count(),
            'El chat tiene que quedar bajo la cuenta del usuario autenticado.'
        );
        $this->assertEquals(
            0,
            WhatsappChat::where('user_id', $otro_comercio->id)->count(),
            'Y la otra empresa no puede haber recibido nada.'
        );
    }
}
