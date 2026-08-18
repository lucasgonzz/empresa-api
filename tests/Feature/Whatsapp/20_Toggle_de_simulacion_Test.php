<?php

namespace Tests\Feature\Whatsapp;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión personalizacion-agente-whatsapp — Parte B (addendum): toggle
 * `chat_simulation_enabled` que gatea el botón de simular un mensaje del cliente, tanto
 * dentro de la conversación como desde la bandeja.
 *
 * 🔴 Lo que protege este archivo es que el checkbox de la pantalla de Configuración sea
 * HONESTO: no alcanza con que el front esconda el botón cuando el toggle está apagado, porque
 * cualquiera con Postman podría seguir pegándole al endpoint. El guard tiene que estar
 * también del lado del servidor (`simulate_inbound_devuelve_422_si_el_toggle_esta_apagado`).
 *
 * Setup calcado de 8_Simular_entrante_Test.php: el endpoint de simulación está gateado además
 * por la extensión 'whatsapp' (`check_extencion_empresa:whatsapp`), así que hace falta
 * `dar_extension()` antes de pegarle.
 */
class Toggle_de_simulacion_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'whatsapp';

    /** Ruta del endpoint de simulación. */
    const RUTA_SIMULAR = 'api/whatsapp-bot/simulate-inbound';

    /** Ruta del endpoint de configuración. */
    const RUTA_CONFIG = 'api/whatsapp-bot/config';

    /** Personalidad cargada por el dueño; el toggle no la puede pisar. */
    const PERSONALIDAD = 'Sos el vendedor de la ferretería del barrio, tuteás y sos breve.';

    /** Habilidades cargadas por el dueño; el toggle no las puede pisar. */
    const HABILIDADES = 'Sos experto en ferretería y bulonería.';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp toggle simulacion',
            'company_name' => 'Ferreteria toggle simulacion',
            'email'        => 'whatsapp-toggle-simulacion-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // A propósito NO se manda `chat_simulation_enabled` acá: la default de la columna
        // (false) es la que tiene que quedar vigente para el caso "toggle apagado".
        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-toggle-simulacion',
            'phone_number_id'    => '5491100000017',
            'webhook_secret'     => 'secreto-toggle-simulacion',
            'is_active'          => true,
            'ai_enabled_default' => true,
            'agent_personality'  => self::PERSONALIDAD,
            'agent_skills'       => self::HABILIDADES,
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
     * @group whatsapp
     * @test
     */
    public function simulate_inbound_devuelve_422_si_el_toggle_esta_apagado()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $this->assertFalse((bool) $this->config->fresh()->chat_simulation_enabled, 'La default de la columna tiene que ser false.');

        $response = $this->postJson(self::RUTA_SIMULAR, ['phone' => '3416005517', 'body' => 'hola']);

        $response->assertStatus(422);
        $this->assertStringContainsString('desactivada', (string) $response->json('message'));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function simulate_inbound_funciona_con_el_toggle_prendido()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $this->config->chat_simulation_enabled = true;
        $this->config->save();

        $response = $this->postJson(self::RUTA_SIMULAR, ['phone' => '3416005517', 'body' => 'hola, ¿tenés tornillos?']);

        $response->assertStatus(201);
    }

    /**
     * Mismo espíritu que el caso más importante del 16: cada campo nuevo del endpoint de
     * configuración necesita su propio caso de no-pisado.
     *
     * @group whatsapp
     * @test
     */
    public function guardar_el_toggle_no_pisa_agent_skills_ni_agent_personality()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs($this->comercio, 'web');

        $this->putJson(self::RUTA_CONFIG, ['chat_simulation_enabled' => true])->assertStatus(200);

        $guardada = $this->config->fresh();
        $this->assertTrue((bool) $guardada->chat_simulation_enabled, 'El toggle sí se tiene que haber prendido.');
        $this->assertEquals(self::PERSONALIDAD, $guardada->agent_personality, 'La personalidad no se toca.');
        $this->assertEquals(self::HABILIDADES, $guardada->agent_skills, 'Las habilidades no se tocan.');

        // Y al revés: guardar las habilidades desde la otra pantalla no puede apagar el toggle.
        $this->putJson(self::RUTA_CONFIG, ['agent_skills' => 'Otras habilidades.'])->assertStatus(200);

        $guardada = $this->config->fresh();
        $this->assertEquals('Otras habilidades.', $guardada->agent_skills);
        $this->assertTrue(
            (bool) $guardada->chat_simulation_enabled,
            'Guardar las habilidades NO puede apagar el toggle de simulación.'
        );
    }
}
