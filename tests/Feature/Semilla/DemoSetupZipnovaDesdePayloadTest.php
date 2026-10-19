<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\ZipnovaCredentialsHelper;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use App\Services\Zipnova\ZipnovaClient;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use ReflectionProperty;
use Tests\EmpresaTestCase;

/**
 * El demo-setup conecta Zipnova con las credenciales de prueba que manda el admin en el payload —
 * misión credenciales-demo-zipnova-mp, 14/10/2026.
 *
 * Contrato que se fija acá:
 *  - Payload COMPLETO (`zipnova_api_token` + `zipnova_api_secret`) → se conecta por
 *    `ZipnovaConexionService::conectar()`, el mismo servicio que la tarjeta del ABM: valida contra
 *    `GET /accounts` antes de guardar, trae los depósitos y deja el de origen elegido.
 *  - El payload gana a la conexión restaurada (la foto) y al `.env`.
 *  - Payload a medias o ausente → no hace nada ni llama a Zipnova.
 *  - Zipnova rechaza o no responde → NO frena el setup, no guarda nada y no deja ningún valor en el log.
 *
 * Todo con `Http::fake()`: nada sale a la red. Los valores son rellenos inventados que no se parecen
 * a una credencial real.
 *
 * No se corre `run()` entero (hace `migrate:fresh` sobre la base de testing): se invoca el método
 * privado por reflexión, mismo criterio que `DemoSetupZipnovaDesdeEnvTest`.
 *
 * @group semilla
 */
class DemoSetupZipnovaDesdePayloadTest extends EmpresaTestCase
{
    /** Host de la API de Zipnova, fijado por config para que los fakes matcheen siempre. */
    const BASE_URL = 'https://api.zipnova.com.ar/v2';

    /** Rellenos inventados (no son credenciales de nadie). */
    const API_TOKEN  = 'zp-token-de-prueba-0001';
    const API_SECRET = 'zp-secret-de-prueba-0002';

    /** Cuenta y depósito que devuelve el Zipnova fakeado. */
    const ACCOUNT_ID = 3355;
    const ORIGIN_ID  = 9323;

    /** @var \App\Models\User */
    private $user;

    /** @var array Las líneas de log del test: texto (mensaje + contexto) de cada una. */
    private $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zipnova.base_url' => self::BASE_URL]);
        config(['app.url' => 'https://api-demo-de-prueba.test']);

        $this->user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();

        $this->logs = [];
        $test = $this;
        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($test) {
            $test->logs[] = (string) $evento->message . ' ' . json_encode((array) $evento->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Zipnova responde bien: una cuenta, un depósito de despacho y uno que no (oficina), y acepta
     * el alta del webhook. Una ruta no fakeada responde 599: el test se entera si el código pega
     * a algo que no esperaba.
     *
     * @return void
     */
    private function fakear_conectar_ok()
    {
        Http::fake([
            self::BASE_URL . '/accounts' => Http::response(['data' => [
                ['id' => self::ACCOUNT_ID, 'name' => 'Cuenta de prueba SRL'],
            ]], 200),
            self::BASE_URL . '/addresses*' => Http::response(['data' => [
                ['id' => self::ORIGIN_ID, 'name' => 'Depósito Central', 'street' => 'Av. Colón', 'street_number' => '123', 'city' => ['name' => 'Córdoba'], 'zipcode' => '5000', 'use_for_shipping' => true],
                ['id' => 9324, 'name' => 'Oficina', 'street' => 'San Martín', 'street_number' => '50', 'city' => ['name' => 'Córdoba'], 'zipcode' => '5000', 'use_for_shipping' => false],
            ]], 200),
            self::BASE_URL . '/accounts/' . self::ACCOUNT_ID . '/webhooks' => Http::response(['id' => 777, 'topic' => 'status'], 201),
            self::BASE_URL . '/accounts/' . self::ACCOUNT_ID . '/webhooks/*' => Http::response([], 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);
    }

    /**
     * Llama a `conectar_zipnova_desde_payload()` (privado) con el payload dado.
     *
     * @param array $payload
     * @return bool
     */
    private function conectar(array $payload)
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'conectar_zipnova_desde_payload');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $this->user, $payload);
    }

    /**
     * El payload completo, con los rellenos de arriba.
     *
     * @param array $extra Claves a sumar o pisar.
     * @return array
     */
    private function payload_completo(array $extra = [])
    {
        return array_merge([
            'business_type'      => 'ferreteria',
            'zipnova_api_token'  => self::API_TOKEN,
            'zipnova_api_secret' => self::API_SECRET,
        ], $extra);
    }

    private function conector_actual()
    {
        return PlatformConnector::find_for_user_and_slug($this->user->id, Platform::SLUG_ZIPNOVA);
    }

    private function plataforma_zipnova()
    {
        return Platform::firstOrCreate(['slug' => Platform::SLUG_ZIPNOVA], ['name' => 'Zipnova']);
    }

    /**
     * Un conector ya conectado con OTRAS credenciales y un webhook registrado: lo que deja
     * `restaurar_zipnova()` (la foto de una conexión anterior).
     *
     * @return PlatformConnector
     */
    private function conector_restaurado()
    {
        return PlatformConnector::create([
            'user_id'          => $this->user->id,
            'platform_id'      => $this->plataforma_zipnova()->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => ZipnovaClient::codificar_credencial('token-viejo-0001', 'secret-viejo-0002'),
            'platform_user_id' => (string) self::ACCOUNT_ID,
            'extra_config'     => array_merge(ZipnovaCredentialsHelper::config_defaults(), [
                'origin_id'  => 1,
                'webhook_id' => 555,
            ]),
        ]);
    }

    /**
     * Ninguna línea de log, de ningún nivel, trae un valor del payload.
     *
     * @return void
     */
    private function assert_logs_sin_valores()
    {
        foreach ($this->logs as $linea) {
            foreach ([self::API_TOKEN, self::API_SECRET, base64_encode(self::API_TOKEN . ':' . self::API_SECRET)] as $valor) {
                $this->assertStringNotContainsString($valor, $linea, 'Una línea de log trae un valor de las credenciales.');
            }
        }
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Tests
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 EL CASO QUE JUSTIFICA LA MISIÓN: con el payload completo la demo queda conectada, con las
     * credenciales validadas, la cuenta resuelta por Zipnova y el depósito de origen YA elegido
     * (sin tocar «Actualizar depósitos»).
     *
     * @return void
     */
    public function test_conecta_con_el_payload_y_deja_el_deposito_de_origen_elegido()
    {
        $this->fakear_conectar_ok();

        $this->assertTrue($this->conectar($this->payload_completo()));

        $conector = $this->conector_actual();
        $this->assertNotNull($conector);
        $this->assertTrue($conector->is_connected());
        $this->assertSame((string) self::ACCOUNT_ID, $conector->platform_user_id);
        $this->assertSame(
            ZipnovaClient::codificar_credencial(self::API_TOKEN, self::API_SECRET),
            $conector->access_token,
            'El token queda con el mismo formato que escribe la tarjeta del ABM: base64(token:secret).'
        );

        $config = $conector->extra_config;
        $this->assertSame(self::ORIGIN_ID, $config['origin_id'], 'El depósito de origen tiene que quedar elegido.');
        $this->assertCount(1, $config['origins'], 'Solo los depósitos habilitados para despachar.');
        $this->assertSame(777, $config['webhook_id']);

        $this->assertTrue(ZipnovaCredentialsHelper::esta_conectado($this->user->id));

        $this->assert_logs_sin_valores();
    }

    /**
     * El webhook se registra apuntando a ESTA instancia. En un test (consola) la URL sale de
     * `config('app.url')`: el `request()->root()` de consola es `http://localhost`.
     *
     * @return void
     */
    public function test_el_webhook_apunta_a_esta_instancia()
    {
        $this->fakear_conectar_ok();

        $this->assertTrue($this->conectar($this->payload_completo()));

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL . '/accounts/' . self::ACCOUNT_ID . '/webhooks'
                && $request['url'] === 'https://api-demo-de-prueba.test/api/zipnova/webhook';
        });
    }

    /**
     * En un request HTTP real (el demo-setup del admin) la URL del webhook sale del `root()` del
     * request, igual que en la tarjeta del ABM: en el shared la API se sirve bajo `/public`.
     * Se simula "no estoy en consola" forzando la propiedad del contenedor, que se restaura.
     *
     * @return void
     */
    public function test_con_un_request_real_el_webhook_sale_del_root_del_request()
    {
        $this->fakear_conectar_ok();

        $propiedad = new ReflectionProperty(get_class($this->app), 'isRunningInConsole');
        $propiedad->setAccessible(true);
        $valor_original = $propiedad->getValue($this->app);
        $request_original = $this->app['request'];

        try {
            $propiedad->setValue($this->app, false);
            $this->app->instance('request', Request::create(
                'https://api-demo-real.test/public/api/admin-sync/demo-setup',
                'POST',
                [],
                [],
                [],
                // Como en el shared: el index.php vive bajo /public y eso entra en el root().
                ['SCRIPT_NAME' => '/public/index.php', 'SCRIPT_FILENAME' => '/home/api/public/index.php', 'PHP_SELF' => '/public/index.php']
            ));

            $this->assertTrue($this->conectar($this->payload_completo()));
        } finally {
            $propiedad->setValue($this->app, $valor_original);
            $this->app->instance('request', $request_original);
        }

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && substr($request->url(), -9) === '/webhooks'
                && $request['url'] === 'https://api-demo-real.test/public/api/zipnova/webhook';
        });
    }

    /**
     * 🔴 El payload GANA a la conexión restaurada (que puede ser vieja o rota): las credenciales
     * nuevas reemplazan a las viejas y el webhook viejo se da de baja antes de registrar el nuevo.
     *
     * @return void
     */
    public function test_el_payload_gana_a_la_conexion_restaurada()
    {
        $this->fakear_conectar_ok();
        $this->conector_restaurado();

        $this->assertTrue($this->conectar($this->payload_completo()));

        $conector = $this->conector_actual();
        $this->assertSame(
            ZipnovaClient::codificar_credencial(self::API_TOKEN, self::API_SECRET),
            $conector->access_token,
            'Las credenciales del payload tienen que reemplazar a las de la foto restaurada.'
        );
        $this->assertSame(777, $conector->extra_config['webhook_id'], 'El webhook nuevo reemplaza al restaurado.');

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === self::BASE_URL . '/accounts/' . self::ACCOUNT_ID . '/webhooks/555';
        });
    }

    /**
     * 🔴 El payload GANA al `.env`: aunque `conectar_zipnova_desde_env()` haya dejado una conexión
     * (con otras credenciales y sin depósito), la del payload la pisa.
     *
     * @return void
     */
    public function test_el_payload_gana_al_env()
    {
        $this->fakear_conectar_ok();

        config([
            'services.zipnova.demo_api_token'  => 'env-token-de-prueba-0003',
            'services.zipnova.demo_api_secret' => 'env-secret-de-prueba-0004',
            'services.zipnova.demo_account_id' => '999',
        ]);

        DemoSetupHelper::conectar_zipnova_desde_env($this->user);
        $this->assertSame(
            ZipnovaClient::codificar_credencial('env-token-de-prueba-0003', 'env-secret-de-prueba-0004'),
            $this->conector_actual()->access_token,
            'Precondición: el .env dejó su conexión.'
        );

        $this->assertTrue($this->conectar($this->payload_completo()));

        $conector = $this->conector_actual();
        $this->assertSame(ZipnovaClient::codificar_credencial(self::API_TOKEN, self::API_SECRET), $conector->access_token);
        $this->assertSame((string) self::ACCOUNT_ID, $conector->platform_user_id);
        $this->assertSame(self::ORIGIN_ID, $conector->extra_config['origin_id']);
    }

    /**
     * Un payload a medias (solo el token, solo el secret, vacío o con algo que no es texto) se
     * ignora: no se llama a Zipnova, no se guarda nada y el log dice CUÁL falta sin traer valores.
     *
     * @return void
     */
    public function test_un_payload_a_medias_se_ignora()
    {
        Http::fake();

        $casos = [
            'solo el token'        => ['zipnova_api_token' => self::API_TOKEN],
            'solo el secret'       => ['zipnova_api_secret' => self::API_SECRET],
            'secret vacío'         => ['zipnova_api_token' => self::API_TOKEN, 'zipnova_api_secret' => '   '],
            'token que no es texto' => ['zipnova_api_token' => ['x'], 'zipnova_api_secret' => self::API_SECRET],
            'secret nulo'          => ['zipnova_api_token' => self::API_TOKEN, 'zipnova_api_secret' => null],
        ];

        foreach ($casos as $nombre => $payload) {
            $this->assertFalse($this->conectar($payload), "Caso '$nombre': no tendría que conectar.");
        }

        Http::assertNothingSent();
        $this->assertNull($this->conector_actual());
        $this->assert_logs_sin_valores();
    }

    /**
     * Sin ningún campo (un admin sin desplegar, o credenciales sin cargar) no pasa nada: ni
     * llamada a Zipnova, ni conector, ni una línea de log del demo-setup.
     *
     * @return void
     */
    public function test_sin_campos_no_hace_nada()
    {
        Http::fake();

        $this->assertFalse($this->conectar(['business_type' => 'ferreteria']));

        Http::assertNothingSent();
        $this->assertNull($this->conector_actual());
        $this->assertSame([], array_values(array_filter($this->logs, function ($linea) {
            return strpos($linea, 'DemoSetupHelper') !== false;
        })), 'Sin campos no tiene que salir ninguna línea de log.');
    }

    /**
     * 🔴 Credenciales rechazadas (401): devuelve false SIN excepción —el setup sigue—, no guarda
     * nada, no toca la conexión restaurada y no deja ningún valor en el log.
     *
     * @return void
     */
    public function test_credenciales_rechazadas_no_frenan_el_setup_ni_guardan_nada()
    {
        Http::fake([
            self::BASE_URL . '/accounts' => Http::response(['message' => 'Unauthenticated.'], 401),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->assertFalse($this->conectar($this->payload_completo()));

        $this->assertNull($this->conector_actual(), 'Un rechazo de Zipnova dejó un conector en la base.');
        $this->assertNotEmpty(array_filter($this->logs, function ($linea) {
            return strpos($linea, 'no aceptó las credenciales') !== false && strpos($linea, 'HTTP 401') !== false;
        }), 'El motivo tiene que quedar en el log (con el código, sin valores).');
        $this->assert_logs_sin_valores();
    }

    /**
     * Con una conexión restaurada y credenciales rechazadas, la restaurada queda como estaba: el
     * payload fallido no la rompe (la cadena "foto → .env" sigue intacta).
     *
     * @return void
     */
    public function test_un_rechazo_deja_intacta_la_conexion_restaurada()
    {
        Http::fake([
            self::BASE_URL . '/accounts' => Http::response(['message' => 'Unauthenticated.'], 401),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);
        $restaurado = $this->conector_restaurado();
        $token_antes = $restaurado->access_token;

        $this->assertFalse($this->conectar($this->payload_completo()));

        $this->assertSame($token_antes, $this->conector_actual()->access_token);
        $this->assertSame(555, $this->conector_actual()->extra_config['webhook_id']);
    }

    /**
     * Zipnova caído (5xx): mismo comportamiento que un rechazo.
     *
     * @return void
     */
    public function test_zipnova_caido_no_frena_el_setup()
    {
        Http::fake([
            '*' => Http::response(['message' => 'Server error'], 500),
        ]);

        $this->assertFalse($this->conectar($this->payload_completo()));

        $this->assertNull($this->conector_actual());
        $this->assert_logs_sin_valores();
    }

    /**
     * Si `zipnova_account_id` no coincide con la cuenta que devuelve Zipnova, gana Zipnova y el
     * desacuerdo se loguea sin valores.
     *
     * @return void
     */
    public function test_un_account_id_que_no_coincide_gana_lo_que_dice_zipnova()
    {
        $this->fakear_conectar_ok();

        $this->assertTrue($this->conectar($this->payload_completo(['zipnova_account_id' => '424242'])));

        $this->assertSame((string) self::ACCOUNT_ID, $this->conector_actual()->platform_user_id);
        $this->assertNotEmpty(array_filter($this->logs, function ($linea) {
            return strpos($linea, 'no coincide con la cuenta') !== false;
        }));
        foreach ($this->logs as $linea) {
            $this->assertStringNotContainsString('424242', $linea, 'El log del desacuerdo no lleva valores.');
        }
    }

    /**
     * Si coincide, no hay aviso.
     *
     * @return void
     */
    public function test_un_account_id_que_coincide_no_avisa()
    {
        $this->fakear_conectar_ok();

        $this->assertTrue($this->conectar($this->payload_completo(['zipnova_account_id' => (string) self::ACCOUNT_ID])));

        $this->assertEmpty(array_filter($this->logs, function ($linea) {
            return strpos($linea, 'no coincide con la cuenta') !== false;
        }));
    }

    /**
     * 🔴 Un `QueryException` (que trae el SQL con los bindings, o sea el token) no deja el valor en
     * el log: se loguea la clase y el SQLSTATE. Se lo provoca con un listener de `saving` del conector
     * que tira el mismo tipo de excepción que tiraría MySQL al insertar.
     *
     * @return void
     */
    public function test_un_error_de_base_no_deja_el_token_en_el_log()
    {
        $this->fakear_conectar_ok();

        $codificada = ZipnovaClient::codificar_credencial(self::API_TOKEN, self::API_SECRET);

        // Se escucha el evento del modelo por su nombre y se lo olvida al terminar (nada de
        // `flushEventListeners()`, que le sacaría a los demás tests los observers del modelo).
        $evento = 'eloquent.saving: ' . PlatformConnector::class;

        Event::listen($evento, function () use ($codificada) {
            throw new QueryException(
                'insert into platform_connectors (access_token) values (?)',
                [$codificada],
                new \Exception('SQLSTATE[HY000]: error inventado por el test')
            );
        });

        try {
            $resultado = $this->conectar($this->payload_completo());
        } finally {
            Event::forget($evento);
        }

        $this->assertFalse($resultado);
        $this->assertNotEmpty(array_filter($this->logs, function ($linea) {
            return strpos($linea, 'QueryException') !== false;
        }), 'Tiene que quedar en el log que fue un error de base.');
        $this->assert_logs_sin_valores();
    }
}
