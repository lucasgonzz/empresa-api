<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * El demo-setup conecta Mercado Pago con las credenciales de prueba que manda el admin en el
 * payload — misión credenciales-demo-zipnova-mp, 14/10/2026.
 *
 * Contrato que se fija acá:
 *  - Payload COMPLETO (`mercadopago_access_token` + `mercadopago_public_key`) → conector conectado
 *    y espejo en `payment_methods`, EXACTAMENTE lo mismo que deja `conectar_mercado_pago_desde_env()`
 *    (es el mismo cuerpo, compartido).
 *  - El payload gana a la conexión restaurada y al `.env`.
 *  - Payload a medias o ausente → no hace nada.
 *  - Un error de base no deja ningún valor en el log.
 *  - No se llama a Mercado Pago: el admin ya las probó al cargarlas.
 *
 * Valores de relleno inventados. No se corre `run()` entero: se invoca el método privado por
 * reflexión, mismo criterio que `DemoSetupMercadoPagoDesdeEnvTest`.
 *
 * @group semilla
 */
class DemoSetupMercadoPagoDesdePayloadTest extends EmpresaTestCase
{
    /** Rellenos inventados (no son credenciales de nadie). */
    const ACCESS_TOKEN = 'TEST-relleno-access-token-0001';
    const PUBLIC_KEY   = 'TEST-relleno-public-key-0002';

    /** @var \App\Models\User */
    private $user;

    /** @var array Texto (mensaje + contexto) de cada línea de log del test. */
    private $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();

        $this->logs = [];
        $test = $this;
        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($test) {
            $test->logs[] = (string) $evento->message . ' ' . json_encode((array) $evento->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
    }

    protected function tearDown(): void
    {
        config([
            'services.mercadopago.demo_access_token' => null,
            'services.mercadopago.demo_public_key'   => null,
        ]);

        parent::tearDown();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Llama a `conectar_mercado_pago_desde_payload()` (privado) con el payload dado.
     *
     * @param array $payload
     * @return bool
     */
    private function conectar(array $payload)
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'conectar_mercado_pago_desde_payload');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $this->user, $payload);
    }

    private function payload_completo(array $extra = [])
    {
        return array_merge([
            'business_type'            => 'ferreteria',
            'mercadopago_access_token' => self::ACCESS_TOKEN,
            'mercadopago_public_key'   => self::PUBLIC_KEY,
        ], $extra);
    }

    private function tipo_mp()
    {
        return PaymentMethodType::firstOrCreate(['name' => 'MercadoPago']);
    }

    private function conector_actual()
    {
        return PlatformConnector::find_for_user_and_slug($this->user->id, Platform::SLUG_MERCADO_PAGO);
    }

    private function espejo_actual()
    {
        return PaymentMethod::where('user_id', $this->user->id)
            ->where('payment_method_type_id', $this->tipo_mp()->id)
            ->first();
    }

    /**
     * Una conexión anterior con OTRAS credenciales, como la deja `restaurar_mercado_pago()`.
     *
     * @return void
     */
    private function dejar_conexion_restaurada()
    {
        $plataforma = Platform::firstOrCreate(['slug' => Platform::SLUG_MERCADO_PAGO], ['name' => 'Mercado Pago']);

        PlatformConnector::create([
            'user_id'          => $this->user->id,
            'platform_id'      => $plataforma->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => 'APP_USR-viejo-access-token-0003',
            'public_key'       => 'APP_USR-viejo-public-key-0004',
            'refresh_token'    => 'refresh-viejo-0005',
            'platform_user_id' => '1234',
        ]);

        PaymentMethod::updateOrCreate(
            ['user_id' => $this->user->id, 'payment_method_type_id' => $this->tipo_mp()->id],
            ['name' => 'Mi MercadoPago', 'description' => 'Texto del comercio', 'access_token' => 'APP_USR-viejo-access-token-0003', 'public_key' => 'APP_USR-viejo-public-key-0004']
        );
    }

    private function assert_logs_sin_valores()
    {
        foreach ($this->logs as $linea) {
            foreach ([self::ACCESS_TOKEN, self::PUBLIC_KEY] as $valor) {
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
     * 🔴 EL CASO QUE JUSTIFICA LA MISIÓN: con el payload completo la demo queda con Mercado Pago
     * conectado, en el conector y en el espejo de `payment_methods` (el que lee la tienda vieja).
     * Sin ninguna llamada a Mercado Pago.
     *
     * @return void
     */
    public function test_conecta_con_el_payload_y_deja_conector_y_espejo()
    {
        Http::fake();

        $this->assertTrue($this->conectar($this->payload_completo()));

        $conector = $this->conector_actual();
        $this->assertNotNull($conector);
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
        $this->assertTrue($conector->is_connected());
        $this->assertSame(self::ACCESS_TOKEN, $conector->access_token);
        $this->assertSame(self::PUBLIC_KEY, $conector->public_key);
        $this->assertNull($conector->refresh_token);
        $this->assertNull($conector->expires_at);
        $this->assertNull($conector->error_message);

        $espejo = $this->espejo_actual();
        $this->assertNotNull($espejo, 'Sin el espejo la tienda vieja no cobra.');
        $this->assertSame(self::ACCESS_TOKEN, $espejo->access_token);
        $this->assertSame(self::PUBLIC_KEY, $espejo->public_key);
        $this->assertSame('MercadoPago', $espejo->name);

        Http::assertNothingSent();
        $this->assert_logs_sin_valores();
    }

    /**
     * Desde el payload y desde el `.env` el resultado es el MISMO (es un solo cuerpo compartido):
     * misma forma de conector y de espejo, con las credenciales de cada origen.
     *
     * @return void
     */
    public function test_deja_lo_mismo_que_el_camino_del_env()
    {
        config([
            'services.mercadopago.demo_access_token' => self::ACCESS_TOKEN,
            'services.mercadopago.demo_public_key'   => self::PUBLIC_KEY,
        ]);

        DemoSetupHelper::conectar_mercado_pago_desde_env($this->user);
        $desde_env = [$this->conector_actual()->only(['status', 'access_token', 'public_key', 'refresh_token', 'expires_at', 'platform_user_id', 'error_message']),
            $this->espejo_actual()->only(['name', 'description', 'access_token', 'public_key'])];

        PlatformConnector::where('user_id', $this->user->id)->where('platform_id', $this->conector_actual()->platform_id)->delete();
        PaymentMethod::where('id', $this->espejo_actual()->id)->delete();

        $this->assertTrue($this->conectar($this->payload_completo()));
        $desde_payload = [$this->conector_actual()->only(['status', 'access_token', 'public_key', 'refresh_token', 'expires_at', 'platform_user_id', 'error_message']),
            $this->espejo_actual()->only(['name', 'description', 'access_token', 'public_key'])];

        $this->assertEquals($desde_env, $desde_payload);
    }

    /**
     * 🔴 El payload GANA a la conexión restaurada: conector y espejo quedan con las credenciales
     * nuevas, y la configuración comercial del medio de pago (nombre, descripción) sigue como estaba.
     *
     * @return void
     */
    public function test_el_payload_gana_a_la_conexion_restaurada()
    {
        $this->dejar_conexion_restaurada();

        $this->assertTrue($this->conectar($this->payload_completo()));

        $conector = $this->conector_actual();
        $this->assertSame(self::ACCESS_TOKEN, $conector->access_token);
        $this->assertSame(self::PUBLIC_KEY, $conector->public_key);
        $this->assertNull($conector->refresh_token, 'El refresh token de la cuenta vieja no puede sobrevivir.');
        $this->assertNull($conector->platform_user_id);

        $espejo = $this->espejo_actual();
        $this->assertSame(self::ACCESS_TOKEN, $espejo->access_token);
        $this->assertSame(self::PUBLIC_KEY, $espejo->public_key);
        $this->assertSame('Mi MercadoPago', $espejo->name, 'La configuración comercial del medio de pago no se pisa.');
    }

    /**
     * 🔴 El payload GANA al `.env`.
     *
     * @return void
     */
    public function test_el_payload_gana_al_env()
    {
        config([
            'services.mercadopago.demo_access_token' => 'TEST-env-access-token-0006',
            'services.mercadopago.demo_public_key'   => 'TEST-env-public-key-0007',
        ]);
        DemoSetupHelper::conectar_mercado_pago_desde_env($this->user);

        $this->assertTrue($this->conectar($this->payload_completo()));

        $this->assertSame(self::ACCESS_TOKEN, $this->conector_actual()->access_token);
        $this->assertSame(self::ACCESS_TOKEN, $this->espejo_actual()->access_token);
    }

    /**
     * Un payload a medias (uno solo de los dos, vacío o no-texto) se ignora: no escribe nada.
     *
     * @return void
     */
    public function test_un_payload_a_medias_se_ignora()
    {
        $casos = [
            'solo el access token' => ['mercadopago_access_token' => self::ACCESS_TOKEN],
            'solo la public key'   => ['mercadopago_public_key' => self::PUBLIC_KEY],
            'public key vacía'     => ['mercadopago_access_token' => self::ACCESS_TOKEN, 'mercadopago_public_key' => ' '],
            'token no es texto'    => ['mercadopago_access_token' => 12345, 'mercadopago_public_key' => self::PUBLIC_KEY],
        ];

        foreach ($casos as $nombre => $payload) {
            $this->assertFalse($this->conectar($payload), "Caso '$nombre': no tendría que conectar.");
        }

        $this->assertNull($this->conector_actual());
        $this->assertNull($this->espejo_actual());
        $this->assert_logs_sin_valores();
    }

    /**
     * Sin los campos no hace nada (el estado de siempre).
     *
     * @return void
     */
    public function test_sin_campos_no_hace_nada()
    {
        $this->assertFalse($this->conectar(['business_type' => 'ferreteria']));

        $this->assertNull($this->conector_actual());
        $this->assertNull($this->espejo_actual());
    }

    /**
     * 🔴 Un `QueryException` (con el token en los bindings) no deja ningún valor en el log: solo la
     * clase y el SQLSTATE. Devuelve false y no tira.
     *
     * @return void
     */
    public function test_un_error_de_base_no_deja_los_valores_en_el_log()
    {
        $evento = 'eloquent.saving: ' . PlatformConnector::class;

        Event::listen($evento, function () {
            throw new QueryException(
                'insert into platform_connectors (access_token, public_key) values (?, ?)',
                [self::ACCESS_TOKEN, self::PUBLIC_KEY],
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
        }));
        $this->assert_logs_sin_valores();
    }
}
