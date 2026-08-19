<?php

namespace Tests\Feature\Whatsapp;

use App\Models\AiTokenUsage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/5: registro del consumo de tokens del agente.
 *
 * Hasta esta misión, todo lo que el agente de WhatsApp gastaba en Anthropic no quedaba
 * registrado en ningún lado. Ahora las tres puertas de entrada graban en
 * `ai_token_usages` con procesos distintos (D6), porque son la misma llamada HTTP pero
 * con dueños del gasto diferentes:
 *
 *   - `whatsapp_respuesta`  → la respuesta automática (job). Sin persona: auth_user_id null.
 *   - `whatsapp_sugerencia` → el botón "Sugerir respuesta". Lo pidió alguien.
 *   - `whatsapp_resumen`    → el resumen de la conversación. Lo pidió alguien.
 *
 * También se protege que el metering nunca cambie el resultado visible: una respuesta sin
 * bloque `usage` graba la fila en cero y devuelve el texto igual, y una llamada fallida no
 * graba nada (no hay consumo que imputar).
 */
class Tokens_agente_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat */
    protected $chat;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: cada test setea su fake si la necesita.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-5',
            'company_name' => 'Ferreteria F8-5',
            'email'        => 'whatsapp-f8-5-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F8-5',
            'email'    => 'whatsapp-f8-5-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'         => $this->comercio->id,
            'kapso_api_key'   => 'kapso-f8-5',
            'phone_number_id' => '5491100000005',
            'webhook_secret'  => 'secreto-f8-5',
            'is_active'       => true,
        ]);

        $this->chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => '5493416003344',
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => '¿Tenés tornillos de 3 pulgadas?',
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
     * Stubs de red. El `'*'` final evita que una request sin stub salga de verdad.
     *
     * @param array $respuesta_anthropic Body con el que responde api.anthropic.com.
     * @param int   $status
     * @return void
     */
    protected function fakes_de_red(array $respuesta_anthropic, $status = 200)
    {
        Http::fake([
            'api.openai.com/*'    => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => Http::response($respuesta_anthropic, $status),
            '*'                   => Http::response([], 200),
        ]);
    }

    /**
     * Body típico de Anthropic con bloque `usage` completo.
     *
     * @param string $texto
     * @return array
     */
    protected function respuesta_con_usage($texto)
    {
        return [
            'model'   => 'claude-modelo-de-prueba',
            'content' => [['type' => 'text', 'text' => $texto]],
            'usage'   => [
                'input_tokens'                => 321,
                'output_tokens'               => 45,
                'cache_creation_input_tokens' => 10,
                'cache_read_input_tokens'     => 5,
            ],
        ];
    }

    /**
     * Filas de consumo del comercio del test.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function consumos()
    {
        return AiTokenUsage::where('user_id', $this->comercio->id)->get();
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_respuesta_automatica_graba_una_fila_con_los_tokens_del_usage()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red($this->respuesta_con_usage('Sí, tenemos.'));

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertEquals('Sí, tenemos.', $texto);

        $filas = $this->consumos();
        $this->assertCount(1, $filas, 'Una llamada del agente deja exactamente una fila de consumo.');

        $fila = $filas[0];
        $this->assertEquals('whatsapp_respuesta', $fila->proceso);
        $this->assertEquals(321, (int) $fila->input_tokens);
        $this->assertEquals(45, (int) $fila->output_tokens);
        $this->assertEquals(10, (int) $fila->cache_creation_input_tokens);
        $this->assertEquals(5, (int) $fila->cache_read_input_tokens);
        $this->assertEquals($this->chat->id, (int) $fila->referencia_id);
        $this->assertEquals($this->comercio->id, (int) $fila->user_id, 'El gasto se le imputa al dueño de la cuenta.');
        $this->assertNull($fila->auth_user_id, 'La respuesta automática no la pidió nadie: auth_user_id queda null.');
        $this->assertNotEquals('', (string) $fila->modelo);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_resumen_graba_su_propio_proceso()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red($this->respuesta_con_usage('El cliente preguntó por tornillos.'));

        $texto = (new WhatsappBotAiService())->generate_summary($this->chat);

        $this->assertEquals('El cliente preguntó por tornillos.', $texto);

        $filas = $this->consumos();
        $this->assertCount(1, $filas);
        $this->assertEquals('whatsapp_resumen', $filas[0]->proceso);
        $this->assertEquals($this->chat->id, (int) $filas[0]->referencia_id);
    }

    /**
     * D6: la sugerencia es la misma llamada HTTP que la respuesta automática, pero el
     * gasto tiene otro dueño. Sin procesos separados, Lucas no puede distinguir lo que
     * gasta el bot solo de lo que le piden a mano.
     *
     * @group whatsapp
     * @test
     */
    public function el_endpoint_de_sugerencia_imputa_el_gasto_a_la_persona_que_lo_pidio()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red($this->respuesta_con_usage('Te sugiero contestar esto.'));

        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $this->chat->id . '/suggest');

        $response->assertStatus(200);
        $this->assertEquals('Te sugiero contestar esto.', $response->json('suggestion'));

        $filas = $this->consumos();
        $this->assertCount(1, $filas);
        $this->assertEquals('whatsapp_sugerencia', $filas[0]->proceso);
        $this->assertEquals($this->comercio->id, (int) $filas[0]->user_id, 'La cuenta es la del dueño...');
        $this->assertEquals($this->empleado->id, (int) $filas[0]->auth_user_id, '...pero el pedido fue de la persona.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_endpoint_de_resumen_imputa_el_gasto_a_la_persona_que_lo_pidio()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red($this->respuesta_con_usage('Resumen pedido a mano.'));

        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $this->chat->id . '/summary');

        $response->assertStatus(200);
        $this->assertEquals('Resumen pedido a mano.', $response->json('summary'));

        $filas = $this->consumos();
        $this->assertCount(1, $filas);
        $this->assertEquals('whatsapp_resumen', $filas[0]->proceso);
        $this->assertEquals($this->empleado->id, (int) $filas[0]->auth_user_id);
    }

    /**
     * El metering es contabilidad de fondo: no puede cambiar lo que ve el cliente. Si la
     * respuesta viene sin bloque `usage`, la fila se graba igual en cero y el texto llega
     * intacto.
     *
     * @group whatsapp
     * @test
     */
    public function una_respuesta_sin_bloque_usage_graba_la_fila_en_cero_y_devuelve_el_texto()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red([
            'model'   => 'claude-modelo-de-prueba',
            'content' => [['type' => 'text', 'text' => 'Respuesta sin usage.']],
        ]);

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertEquals('Respuesta sin usage.', $texto, 'El texto tiene que llegar igual.');

        $filas = $this->consumos();
        $this->assertCount(1, $filas);
        $this->assertEquals(0, (int) $filas[0]->input_tokens);
        $this->assertEquals(0, (int) $filas[0]->output_tokens);
        $this->assertEquals('whatsapp_respuesta', $filas[0]->proceso);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_llamada_fallida_no_graba_ninguna_fila()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red(['error' => ['message' => 'se cayó todo']], 500);

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertEquals('', $texto);
        $this->assertCount(
            0,
            $this->consumos(),
            'Sin respuesta válida no hay consumo que imputar: la fila no se graba.'
        );
    }
}
