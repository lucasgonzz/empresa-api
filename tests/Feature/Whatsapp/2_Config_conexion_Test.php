<?php

namespace Tests\Feature\Whatsapp;

use App\Models\User;
use App\Models\WhatsappBotConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/2: el endpoint `whatsapp-bot/config`.
 *
 * Tres cosas que este archivo protege y que antes de la misión no protegía nadie:
 *
 * 1. El endpoint devuelve `kapso_api_key` y `webhook_secret` EN TEXTO PLANO. Hasta ahora
 *    cualquier empleado con token Sanctum los leía. Ahora es solo del dueño (D10).
 * 2. Las dos pantallas que pegan a este endpoint (ABM → Integraciones y el modal del
 *    módulo) mandan payloads PARCIALES. Que un guardado no pise lo que la otra pantalla
 *    tiene cargado no es un detalle: si se rompe, guardar la personalidad del agente
 *    borra las credenciales de Kapso y el bot deja de andar.
 * 3. El bot no puede quedar activo sin credenciales, y los tiempos de espera tienen
 *    techo.
 */
class Config_conexion_Test extends TestCase
{
    use DatabaseTransactions;
    use OtorgaAgentesIa;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: ningún test de esta suite sale a la red.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-2',
            'company_name' => 'Ferreteria F8-2',
            'email'        => 'whatsapp-f8-2-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // Misión agentes-ia-extension-unica (22/10/2026): la configuración del bot de WhatsApp es de `agentes_ia`.
        $this->otorgar_agentes_ia($this->comercio);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F8-2',
            'email'    => 'whatsapp-f8-2-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);
    }

    /**
     * Config ya existente del comercio, con las tres credenciales cargadas.
     *
     * @param array $extra
     * @return WhatsappBotConfig
     */
    protected function config_existente(array $extra = [])
    {
        return WhatsappBotConfig::create(array_merge([
            'user_id'         => $this->comercio->id,
            'kapso_api_key'   => 'kapso-original',
            'phone_number_id' => '5491100000000',
            'webhook_secret'  => 'secreto-original',
            'is_active'       => true,
        ], $extra));
    }

    /**
     * D10: el modelo viaja con las credenciales en texto plano, así que la pantalla es
     * del dueño y de nadie más.
     *
     * @group whatsapp
     * @test
     */
    public function un_empleado_no_puede_ver_ni_editar_la_configuracion()
    {
        $this->config_existente();

        $this->actingAs($this->empleado, 'web');

        $this->getJson('api/whatsapp-bot/config')->assertStatus(403);
        $this->putJson('api/whatsapp-bot/config', ['agent_personality' => 'me cuelo'])->assertStatus(403);

        // Y el intento no pudo haber tocado nada.
        $config = WhatsappBotConfig::where('user_id', $this->comercio->id)->first();
        $this->assertNull($config->agent_personality);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_dueno_activa_y_la_respuesta_no_trae_los_secretos()
    {
        // Misión kapso-secretos-fuera-del-navegador: antes este test afirmaba que `kapso_api_key` y
        // `webhook_secret` VIAJABAN en el JSON; ahora se afirma que NO viajan (y que sí viajan los derivados).
        $this->config_existente(['is_active' => false]);
        $this->actingAs($this->comercio, 'web');

        $response = $this->putJson('api/whatsapp-bot/config', ['is_active' => true]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('kapso_api_key', $response->json('model'));
        $this->assertArrayNotHasKey('webhook_secret', $response->json('model'));
        $this->assertEquals('5491100000000', $response->json('model.phone_number_id'));
        $this->assertTrue((bool) $response->json('model.is_active'));
        $this->assertTrue($response->json('model.kapso_api_key_configurada'));
        $this->assertTrue($response->json('model.webhook_secret_configurado'));
        $this->assertTrue($response->json('model.conexion_configurada'));

        $leido = $this->getJson('api/whatsapp-bot/config');
        $leido->assertStatus(200);
        $this->assertArrayNotHasKey('kapso_api_key', $leido->json('model'));
        $this->assertArrayNotHasKey('webhook_secret', $leido->json('model'));
        $this->assertStringNotContainsString('kapso-original', $leido->getContent());
        $this->assertStringNotContainsString('secreto-original', $leido->getContent());
    }

    /**
     * El modal del agente manda SOLO sus campos. Si el controller pisara con null lo que
     * no vino, guardar la personalidad dejaría el bot sin credenciales.
     *
     * @group whatsapp
     * @test
     */
    public function un_guardado_solo_del_agente_no_pisa_las_credenciales()
    {
        $this->config_existente();
        $this->actingAs($this->comercio, 'web');

        $this->putJson('api/whatsapp-bot/config', [
            'agent_personality'        => 'Sos el vendedor de la ferretería.',
            'ai_enabled_default'       => false,
            'auto_send_sale_pdf'       => true,
            'ai_reply_delay_seconds'   => 30,
            'ai_confirm_delay_seconds' => 120,
        ])->assertStatus(200);

        $config = WhatsappBotConfig::where('user_id', $this->comercio->id)->first();

        $this->assertEquals('kapso-original', $config->kapso_api_key);
        $this->assertEquals('5491100000000', $config->phone_number_id);
        $this->assertEquals('secreto-original', $config->webhook_secret);
        $this->assertEquals('Sos el vendedor de la ferretería.', $config->agent_personality);
        $this->assertEquals(30, (int) $config->ai_reply_delay_seconds);
        $this->assertEquals(120, (int) $config->ai_confirm_delay_seconds);
    }

    /**
     * La contracara: la pantalla de Integraciones manda solo lo técnico y no puede
     * borrar la personalidad ni los toggles que cargó el dueño.
     *
     * @group whatsapp
     * @test
     */
    public function un_guardado_solo_de_is_active_no_pisa_la_personalidad_ni_los_toggles()
    {
        // Misión kapso-secretos-fuera-del-navegador: el dueño ya no manda credenciales; la contracara que
        // importa (un guardado parcial no pisa lo funcional) se prueba con el único campo técnico que sí edita.
        $this->config_existente([
            'is_active'                => false,
            'agent_personality'        => 'Personalidad ya cargada.',
            'ai_enabled_default'       => false,
            'auto_send_sale_pdf'       => true,
            'ai_reply_delay_seconds'   => 45,
            'ai_confirm_delay_seconds' => 300,
        ]);

        $this->actingAs($this->comercio, 'web');

        $this->putJson('api/whatsapp-bot/config', ['is_active' => true])->assertStatus(200);

        $config = WhatsappBotConfig::where('user_id', $this->comercio->id)->first();

        $this->assertTrue((bool) $config->is_active);
        $this->assertEquals('kapso-original', $config->kapso_api_key);
        $this->assertEquals('Personalidad ya cargada.', $config->agent_personality);
        $this->assertFalse((bool) $config->ai_enabled_default);
        $this->assertTrue((bool) $config->auto_send_sale_pdf);
        $this->assertEquals(45, (int) $config->ai_reply_delay_seconds);
        $this->assertEquals(300, (int) $config->ai_confirm_delay_seconds);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function no_se_puede_activar_el_bot_sin_las_tres_credenciales()
    {
        $this->actingAs($this->comercio, 'web');

        $response = $this->putJson('api/whatsapp-bot/config', ['is_active' => true]);

        $response->assertStatus(422);
        $this->assertEquals(
            'No se puede activar el bot sin phone_number_id, kapso_api_key y webhook_secret.',
            $response->json('message')
        );

        $this->assertNull(
            WhatsappBotConfig::where('user_id', $this->comercio->id)->first(),
            'El 422 no puede haber creado la fila.'
        );

        // Con las credenciales ya provisionadas por el equipo (no por el dueño), sí se activa.
        $this->config_existente(['is_active' => false]);

        $this->putJson('api/whatsapp-bot/config', ['is_active' => true])->assertStatus(200);

        $config = WhatsappBotConfig::where('user_id', $this->comercio->id)->first();
        $this->assertTrue((bool) $config->is_active);
    }

    /**
     * El guard mira el valor FINAL de cada campo, no solo lo que vino en el request: con
     * las credenciales ya persistidas, activar mandando solo `is_active` tiene que andar.
     *
     * @group whatsapp
     * @test
     */
    public function activar_con_las_credenciales_ya_guardadas_funciona()
    {
        $this->config_existente(['is_active' => false]);
        $this->actingAs($this->comercio, 'web');

        $this->putJson('api/whatsapp-bot/config', ['is_active' => true])->assertStatus(200);

        $config = WhatsappBotConfig::where('user_id', $this->comercio->id)->first();
        $this->assertTrue((bool) $config->is_active);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function los_tiempos_de_espera_se_validan_contra_sus_techos()
    {
        $this->config_existente();
        $this->actingAs($this->comercio, 'web');

        $this->putJson('api/whatsapp-bot/config', ['ai_reply_delay_seconds' => -1])->assertStatus(422);
        $this->putJson('api/whatsapp-bot/config', ['ai_reply_delay_seconds' => 601])->assertStatus(422);
        $this->putJson('api/whatsapp-bot/config', ['ai_confirm_delay_seconds' => 3601])->assertStatus(422);
        $this->putJson('api/whatsapp-bot/config', ['ai_confirm_delay_seconds' => -5])->assertStatus(422);

        $config = WhatsappBotConfig::where('user_id', $this->comercio->id)->first();
        $this->assertEquals(0, (int) $config->ai_reply_delay_seconds, 'Ningún 422 pudo haber persistido nada.');
        $this->assertEquals(0, (int) $config->ai_confirm_delay_seconds);

        // Los valores del borde de adentro sí entran.
        $this->putJson('api/whatsapp-bot/config', [
            'ai_reply_delay_seconds'   => 30,
            'ai_confirm_delay_seconds' => 3600,
        ])->assertStatus(200);

        $config = $config->fresh();
        $this->assertEquals(30, (int) $config->ai_reply_delay_seconds);
        $this->assertEquals(3600, (int) $config->ai_confirm_delay_seconds);
    }

    /**
     * Misión kapso-secretos-fuera-del-navegador: antes una credencial vacía daba 422 (`required`); ahora
     * las credenciales las ignora el endpoint del dueño, así que un vacío (lo que manda una SPA vieja al
     * guardar sus inputs en blanco) no rompe ni BORRA nada.
     *
     * @group whatsapp
     * @test
     */
    public function una_credencial_vacia_se_ignora_y_no_borra_nada()
    {
        $this->config_existente();
        $this->actingAs($this->comercio, 'web');

        $this->putJson('api/whatsapp-bot/config', ['kapso_api_key' => ''])->assertStatus(200);
        $this->putJson('api/whatsapp-bot/config', ['phone_number_id' => ''])->assertStatus(200);
        $this->putJson('api/whatsapp-bot/config', ['webhook_secret' => ''])->assertStatus(200);

        $config = WhatsappBotConfig::where('user_id', $this->comercio->id)->first();
        $this->assertEquals('kapso-original', $config->kapso_api_key);
        $this->assertEquals('5491100000000', $config->phone_number_id);
        $this->assertEquals('secreto-original', $config->webhook_secret);
    }

    /**
     * Bomba latente que arregló la misión: las tres columnas técnicas son NOT NULL sin
     * default. Sin el relleno con '' en el alta, el primer guardado del modal del agente
     * (que no manda ninguna credencial) reventaba el updateOrCreate en MySQL estricto.
     *
     * @group whatsapp
     * @test
     */
    public function el_alta_desde_cero_solo_con_campos_del_agente_no_revienta()
    {
        $this->actingAs($this->comercio, 'web');

        $this->assertNull(WhatsappBotConfig::where('user_id', $this->comercio->id)->first());

        $response = $this->putJson('api/whatsapp-bot/config', [
            'agent_personality'  => 'Primera personalidad, sin credenciales todavía.',
            'ai_enabled_default' => true,
        ]);

        $response->assertStatus(200);

        $config = WhatsappBotConfig::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($config, 'La fila tiene que haberse creado.');
        $this->assertEquals('Primera personalidad, sin credenciales todavía.', $config->agent_personality);
        $this->assertSame('', (string) $config->kapso_api_key, 'Las credenciales que no vinieron se rellenan con vacío.');
        $this->assertSame('', (string) $config->phone_number_id);
        $this->assertSame('', (string) $config->webhook_secret);
        $this->assertFalse((bool) $config->is_active, 'Y el bot queda apagado, que es lo coherente sin credenciales.');
    }
}
