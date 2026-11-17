<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Models\WhatsappBotConfig;
use App\Services\WhatsappBotSendService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgentesIa\AgentesIaTestCase;

/**
 * Misión kapso-secretos-fuera-del-navegador (17/11/2026).
 *
 * `kapso_api_key` y `webhook_secret` ya no viajan al navegador del dueño: el modelo los oculta, el endpoint
 * `api/whatsapp-bot/config` rechaza (403) que el dueño los cargue o cambie el `phone_number_id`, y el único
 * camino de escritura es la puerta del admin `api/admin-sync/whatsapp-bot/conexion` (`admin.api.key:siempre`),
 * que tampoco los devuelve. Los servicios siguen leyendo el atributo (`$hidden` solo afecta la serialización).
 *
 * 🔴 Todos los valores son de prueba; nada sale a la red (`Http::fake()`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Secretos_fuera_del_navegador_Test extends AgentesIaTestCase
{
    const CLAVE_KAPSO = 'kapso-clave-de-prueba-777';

    const SECRETO     = 'secreto-webhook-de-prueba-888';

    const NUMERO      = '5491100000123';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->dar_extension();

        WhatsappBotConfig::where('user_id', $this->comercio()->id)->delete();
    }

    /**
     * Fila ya provisionada del comercio.
     *
     * @param  array  $extra
     * @return WhatsappBotConfig
     */
    protected function config_provisionada(array $extra = [])
    {
        return WhatsappBotConfig::create(array_merge([
            'user_id'         => $this->comercio()->id,
            'kapso_api_key'   => self::CLAVE_KAPSO,
            'phone_number_id' => self::NUMERO,
            'webhook_secret'  => self::SECRETO,
            'is_active'       => true,
        ], $extra));
    }

    /**
     * @return WhatsappBotConfig|null
     */
    protected function fila()
    {
        return WhatsappBotConfig::where('user_id', $this->comercio()->id)->first();
    }

    /**
     * Afirma que ni la clave ni el secreto aparecen en ningún lado del texto dado.
     *
     * @param  string  $texto
     * @return void
     */
    protected function assert_sin_secretos($texto)
    {
        $this->assertStringNotContainsString(self::CLAVE_KAPSO, $texto);
        $this->assertStringNotContainsString(self::SECRETO, $texto);
        $this->assertStringNotContainsString('kapso_api_key"', $texto);
        $this->assertStringNotContainsString('webhook_secret"', $texto);
    }

    // ------------------------------------------------------------------
    // Serialización
    // ------------------------------------------------------------------

    /**
     * @group whatsapp
     * @test
     */
    public function el_modelo_no_serializa_los_secretos_pero_los_servicios_los_siguen_leyendo()
    {
        $config = $this->config_provisionada()->fresh();

        $this->assertArrayNotHasKey('kapso_api_key', $config->toArray());
        $this->assertArrayNotHasKey('webhook_secret', $config->toArray());
        $this->assert_sin_secretos($config->toJson());

        $this->assertTrue($config->toArray()['kapso_api_key_configurada']);
        $this->assertTrue($config->toArray()['webhook_secret_configurado']);
        $this->assertTrue($config->toArray()['conexion_configurada']);

        // `$hidden` no afecta el acceso por atributo: es lo que leen WhatsappBotSendService y el webhook.
        $this->assertSame(self::CLAVE_KAPSO, $config->kapso_api_key);
        $this->assertSame(self::SECRETO, $config->webhook_secret);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_usuario_con_la_relacion_cargada_tampoco_los_serializa()
    {
        $this->config_provisionada();

        $usuario = $this->comercio()->load('whatsapp_bot_config');

        $this->assertNotNull($usuario->whatsapp_bot_config);
        $this->assert_sin_secretos($usuario->toJson());
        $this->assert_sin_secretos(json_encode($usuario->toArray()));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_conexion_incompleta_se_informa_como_no_configurada()
    {
        $config = $this->config_provisionada(['webhook_secret' => '', 'is_active' => false])->fresh();

        $this->assertTrue($config->kapso_api_key_configurada);
        $this->assertFalse($config->webhook_secret_configurado);
        $this->assertFalse($config->conexion_configurada);
    }

    // ------------------------------------------------------------------
    // Endpoint del dueño
    // ------------------------------------------------------------------

    /**
     * @group whatsapp
     * @test
     */
    public function el_get_y_el_put_del_dueno_no_traen_los_secretos()
    {
        $this->config_provisionada();

        $get = $this->getJson('api/whatsapp-bot/config')->assertStatus(200);
        $this->assert_sin_secretos($get->getContent());
        $this->assertTrue($get->json('model.conexion_configurada'));
        $this->assertSame(self::NUMERO, $get->json('model.phone_number_id'));

        $put = $this->putJson('api/whatsapp-bot/config', ['agent_personality' => 'Hola'])->assertStatus(200);
        $this->assert_sin_secretos($put->getContent());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_dueno_que_manda_una_clave_o_un_secreto_recibe_403_y_no_se_guarda_nada()
    {
        $this->config_provisionada();

        foreach (['kapso_api_key', 'webhook_secret'] as $campo) {
            $respuesta = $this->putJson('api/whatsapp-bot/config', [
                $campo              => 'intento-del-dueno',
                'agent_personality' => 'No tiene que guardarse',
                'is_active'         => false,
            ]);

            $respuesta->assertStatus(403);
            $this->assertSame('Las credenciales de WhatsApp las configura el equipo de ComercioCity.', $respuesta->json('message'));
        }

        $fila = $this->fila();
        $this->assertSame(self::CLAVE_KAPSO, $fila->kapso_api_key);
        $this->assertSame(self::SECRETO, $fila->webhook_secret);
        $this->assertNull($fila->agent_personality, 'El 403 no puede haber guardado nada del request.');
        $this->assertTrue((bool) $fila->is_active);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function un_campo_tecnico_con_forma_de_array_es_422_y_no_un_500_ni_guarda_nada()
    {
        $this->config_provisionada();

        foreach (['kapso_api_key', 'webhook_secret', 'phone_number_id'] as $campo) {
            $respuesta = $this->putJson('api/whatsapp-bot/config', [
                $campo              => ['x'],
                'agent_personality' => 'No tiene que guardarse',
            ]);

            $respuesta->assertStatus(422);
            // El error de validación nombra el campo (es lo que corresponde); lo que no puede aparecer son los VALORES guardados.
            $this->assertStringNotContainsString(self::CLAVE_KAPSO, $respuesta->getContent());
            $this->assertStringNotContainsString(self::SECRETO, $respuesta->getContent());
        }

        $fila = $this->fila();
        $this->assertSame(self::CLAVE_KAPSO, $fila->kapso_api_key);
        $this->assertSame(self::SECRETO, $fila->webhook_secret);
        $this->assertSame(self::NUMERO, $fila->phone_number_id);
        $this->assertNull($fila->agent_personality);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_dueno_que_cambia_el_numero_recibe_403_y_con_el_mismo_numero_se_ignora()
    {
        $this->config_provisionada();

        $this->putJson('api/whatsapp-bot/config', [
            'phone_number_id'   => '5493419999999',
            'agent_personality' => 'No tiene que guardarse',
        ])->assertStatus(403);

        $fila = $this->fila();
        $this->assertSame(self::NUMERO, $fila->phone_number_id);
        $this->assertNull($fila->agent_personality);

        // Una SPA vieja reenvía el valor que leyó: no es un cambio.
        $this->putJson('api/whatsapp-bot/config', [
            'phone_number_id'   => self::NUMERO,
            'agent_personality' => 'Sí se guarda',
        ])->assertStatus(200);

        $this->assertSame('Sí se guarda', $this->fila()->agent_personality);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function guardar_funcionales_is_active_o_un_payload_vacio_conserva_las_credenciales()
    {
        $this->config_provisionada();

        $payloads = [
            ['agent_personality' => 'Vendedor de ferretería', 'ai_reply_delay_seconds' => 20],
            ['is_active' => false],
            ['is_active' => true],
            [],
            ['kapso_api_key' => '', 'webhook_secret' => '', 'phone_number_id' => ''],
        ];

        foreach ($payloads as $payload) {
            $this->putJson('api/whatsapp-bot/config', $payload)->assertStatus(200);

            $fila = $this->fila();
            $this->assertSame(self::CLAVE_KAPSO, $fila->kapso_api_key);
            $this->assertSame(self::SECRETO, $fila->webhook_secret);
            $this->assertSame(self::NUMERO, $fila->phone_number_id);
        }

        $this->assertSame('Vendedor de ferretería', $this->fila()->agent_personality);
        $this->assertTrue((bool) $this->fila()->is_active);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_dueno_no_puede_activar_sin_conexion_provisionada()
    {
        $this->putJson('api/whatsapp-bot/config', ['is_active' => true])->assertStatus(422);
        $this->assertNull($this->fila());

        // Alta solo con campos funcionales: la fila nace con las columnas técnicas en ''.
        $this->putJson('api/whatsapp-bot/config', ['agent_personality' => 'Primera vez'])->assertStatus(200);

        $this->assertSame('', (string) $this->fila()->kapso_api_key);
        $this->assertFalse((bool) $this->fila()->is_active);
    }

    // ------------------------------------------------------------------
    // Puerta del admin
    // ------------------------------------------------------------------

    /**
     * @group whatsapp
     * @test
     */
    public function la_puerta_sin_clave_del_admin_es_401_aunque_la_instancia_no_exija_clave()
    {
        $this->getJson('api/admin-sync/whatsapp-bot/conexion')->assertStatus(401);
        $this->putJson('api/admin-sync/whatsapp-bot/conexion', ['kapso_api_key' => self::CLAVE_KAPSO])->assertStatus(401);

        // Instancia sin clave cargada y con el flag apagado: sigue siendo 401 (modo `siempre`).
        config(['services.admin_api.api_key' => null, 'services.admin_api.require_api_key' => false]);

        $this->getJson('api/admin-sync/whatsapp-bot/conexion')->assertStatus(401);
        $this->putJson('api/admin-sync/whatsapp-bot/conexion', ['kapso_api_key' => self::CLAVE_KAPSO], ['X-Admin-Api-Key' => 'cualquiera'])
             ->assertStatus(401);

        $this->assertNull($this->fila(), 'Un PUT sin clave válida escribió credenciales.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_puerta_con_la_clave_equivocada_es_401()
    {
        $this->putJson('api/admin-sync/whatsapp-bot/conexion', ['kapso_api_key' => self::CLAVE_KAPSO], ['X-Admin-Api-Key' => 'otra-clave'])
             ->assertStatus(401);

        $this->assertNull($this->fila());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_puerta_sin_dueno_resoluble_es_409()
    {
        config(['app.USER_ID' => 99999999]);

        $this->getJson('api/admin-sync/whatsapp-bot/conexion', $this->cabeceras_de_admin())->assertStatus(409);
        $this->putJson('api/admin-sync/whatsapp-bot/conexion', ['phone_number_id' => self::NUMERO], $this->cabeceras_de_admin())->assertStatus(409);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_puerta_dice_que_no_existe_la_conexion_antes_del_alta()
    {
        $this->getJson('api/admin-sync/whatsapp-bot/conexion', $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertExactJson(['ok' => true, 'conexion' => [
                 'existe'                     => false,
                 'phone_number_id'            => null,
                 'kapso_api_key_configurada'  => false,
                 'webhook_secret_configurado' => false,
                 'conexion_configurada'       => false,
                 'is_active'                  => false,
             ]]);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_puerta_da_el_alta_y_la_respuesta_no_trae_secretos()
    {
        $respuesta = $this->putJson('api/admin-sync/whatsapp-bot/conexion', [
            'phone_number_id' => self::NUMERO,
            'kapso_api_key'   => self::CLAVE_KAPSO,
            'webhook_secret'  => self::SECRETO,
            'is_active'       => true,
        ], $this->cabeceras_de_admin());

        $respuesta->assertStatus(200)->assertExactJson(['ok' => true, 'conexion' => [
            'existe'                     => true,
            'phone_number_id'            => self::NUMERO,
            'kapso_api_key_configurada'  => true,
            'webhook_secret_configurado' => true,
            'conexion_configurada'       => true,
            'is_active'                  => true,
        ]]);
        $this->assert_sin_secretos($respuesta->getContent());

        $fila = $this->fila();
        $this->assertSame(self::CLAVE_KAPSO, $fila->kapso_api_key);
        $this->assertSame(self::SECRETO, $fila->webhook_secret);
        $this->assertSame(self::NUMERO, $fila->phone_number_id);
        $this->assertTrue((bool) $fila->is_active);

        $get = $this->getJson('api/admin-sync/whatsapp-bot/conexion', $this->cabeceras_de_admin())->assertStatus(200);
        $this->assert_sin_secretos($get->getContent());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_puerta_actualiza_parcial_y_omitido_o_vacio_no_borra()
    {
        $this->config_provisionada(['is_active' => false]);

        // Solo el secreto: lo demás se conserva.
        $this->putJson('api/admin-sync/whatsapp-bot/conexion', ['webhook_secret' => 'secreto-rotado-999'], $this->cabeceras_de_admin())
             ->assertStatus(200);

        $fila = $this->fila();
        $this->assertSame('secreto-rotado-999', $fila->webhook_secret);
        $this->assertSame(self::CLAVE_KAPSO, $fila->kapso_api_key);
        $this->assertSame(self::NUMERO, $fila->phone_number_id);

        // Vacíos y null: no se toca nada.
        $this->putJson('api/admin-sync/whatsapp-bot/conexion', [
            'phone_number_id' => '',
            'kapso_api_key'   => null,
            'webhook_secret'  => '   ',
        ], $this->cabeceras_de_admin())->assertStatus(200);

        $fila = $this->fila();
        $this->assertSame('secreto-rotado-999', $fila->webhook_secret);
        $this->assertSame(self::CLAVE_KAPSO, $fila->kapso_api_key);
        $this->assertSame(self::NUMERO, $fila->phone_number_id);

        // Activar con las tres finales cargadas.
        $this->putJson('api/admin-sync/whatsapp-bot/conexion', ['is_active' => true], $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertJsonPath('conexion.is_active', true);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_puerta_rechaza_con_422_activar_sin_las_tres_credenciales()
    {
        $respuesta = $this->putJson('api/admin-sync/whatsapp-bot/conexion', [
            'phone_number_id' => self::NUMERO,
            'is_active'       => true,
        ], $this->cabeceras_de_admin());

        $respuesta->assertStatus(422);
        $this->assertNull($this->fila(), 'El 422 no puede haber creado la fila.');

        $this->putJson('api/admin-sync/whatsapp-bot/conexion', ['phone_number_id' => str_repeat('9', 192)], $this->cabeceras_de_admin())
             ->assertStatus(422);
    }

    /**
     * Alta parcial sin activar: las columnas NOT NULL que no vinieron se rellenan con ''.
     *
     * @group whatsapp
     * @test
     */
    public function la_puerta_da_el_alta_parcial_sin_reventar_las_columnas_not_null()
    {
        $this->putJson('api/admin-sync/whatsapp-bot/conexion', ['phone_number_id' => self::NUMERO], $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertJsonPath('conexion.existe', true)
             ->assertJsonPath('conexion.conexion_configurada', false);

        $this->assertSame('', (string) $this->fila()->kapso_api_key);
        $this->assertSame('', (string) $this->fila()->webhook_secret);
    }

    // ------------------------------------------------------------------
    // El bot sigue andando con una config provisionada por la puerta
    // ------------------------------------------------------------------

    /**
     * @group whatsapp
     * @test
     */
    public function el_webhook_con_firma_hmac_y_el_envio_funcionan_con_la_config_provisionada_por_la_puerta()
    {
        $this->putJson('api/admin-sync/whatsapp-bot/conexion', [
            'phone_number_id' => self::NUMERO,
            'kapso_api_key'   => self::CLAVE_KAPSO,
            'webhook_secret'  => self::SECRETO,
            'is_active'       => true,
        ], $this->cabeceras_de_admin())->assertStatus(200);

        // Webhook: firma correcta entra y persiste el chat; firma incorrecta es 401.
        $payload = ['message' => ['from' => '5493415550001', 'type' => 'text', 'text' => ['body' => 'Hola, ¿tienen martillos?']]];
        $firma   = hash_hmac('sha256', json_encode($payload), self::SECRETO);

        $this->postJson('api/whatsapp-bot/webhook', $payload, ['X-Kapso-Signature' => $firma])->assertStatus(200);
        $this->assertSame(1, \App\Models\WhatsappChat::where('user_id', $this->comercio()->id)->count());

        $mala = hash_hmac('sha256', json_encode($payload), 'otro-secreto');
        $this->postJson('api/whatsapp-bot/webhook', $payload, ['X-Kapso-Signature' => $mala])->assertStatus(401);

        // Envío: sale a Kapso con la clave provisionada en X-API-Key.
        // El setUp de la base ya fakeó '*' (y el primer stub que matchea gana): se parte de un Factory limpio.
        Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200)]);

        $id = (new WhatsappBotSendService())->send_text('5493415550002', 'Hola', $this->fila());

        $this->assertNotNull($id, 'El envío no salió con la config provisionada.');

        Http::assertSent(function (ClientRequest $request) {
            return strpos($request->url(), self::NUMERO . '/messages') !== false
                && $request->header('X-API-Key') === [self::CLAVE_KAPSO];
        });
    }

    // ------------------------------------------------------------------
    // El asistente de IA no ve la tabla
    // ------------------------------------------------------------------

    /**
     * @group whatsapp
     * @test
     */
    public function whatsapp_bot_configs_sigue_fuera_del_esquema_del_asistente_de_ia()
    {
        $this->assertArrayHasKey('whatsapp_bot_configs', EsquemaDeDatosIaHelper::TABLAS_EXCLUIDAS);
        $this->assertFalse(EsquemaDeDatosIaHelper::existe('whatsapp_bot_configs'));
        $this->assertNotContains('whatsapp_bot_configs', EsquemaDeDatosIaHelper::entidades());
    }
}
