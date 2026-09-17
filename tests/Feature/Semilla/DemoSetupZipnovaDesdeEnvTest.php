<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\ZipnovaCredentialsHelper;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use App\Services\Zipnova\ZipnovaClient;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Cuando no hay ninguna conexión de Zipnova que restaurar, la demo (o una base local) se conecta
 * sola con las credenciales de demo de `config('services.zipnova')` — misión
 * mp-zipnova-seed-local, 17/9/2026. Espejo de `DemoSetupMercadoPagoDesdeEnvTest`, con las
 * diferencias propias de Zipnova: no hay espejo en `payment_methods`, y la credencial se guarda
 * codificada (`base64(token:secret)`, ver `ZipnovaClient::codificar_credencial()`), no en dos
 * columnas sueltas.
 *
 * 🔴 Esto NO pega contra la API real de Zipnova (`GET /accounts`, webhook): escribe el conector
 * directo, igual que `conectar_mercado_pago_desde_env()` no pasa por el OAuth real de Mercado
 * Pago. Por eso no hay nada que mockear de red en este archivo.
 *
 * No se corre `run()` entero: se invoca `conectar_zipnova_desde_env()` directo por reflexión,
 * mismo criterio que el test de Mercado Pago.
 *
 * @group semilla
 */
class DemoSetupZipnovaDesdeEnvTest extends EmpresaTestCase
{
    const API_TOKEN  = 'token-de-prueba';
    const API_SECRET = 'secret-de-prueba';
    const ACCOUNT_ID = '21850';

    /** @var \App\Models\User */
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    protected function tearDown(): void
    {
        $this->configurar(null, null, null);

        parent::tearDown();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Fuerza (o borra, con null) las tres claves de config que lee
     * `conectar_zipnova_desde_env()` — el mismo repositorio que ve la aplicación, con o sin
     * `config:cache`, a diferencia de tocar `$_ENV`/`putenv()`.
     *
     * @param string|null $api_token
     * @param string|null $api_secret
     * @param string|null $account_id
     * @return void
     */
    private function configurar($api_token, $api_secret, $account_id)
    {
        config([
            'services.zipnova.demo_api_token'  => $api_token,
            'services.zipnova.demo_api_secret' => $api_secret,
            'services.zipnova.demo_account_id' => $account_id,
        ]);
    }

    private function conectar()
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'conectar_zipnova_desde_env');
        $metodo->setAccessible(true);

        $metodo->invoke(null, $this->user);
    }

    private function conector_actual()
    {
        return PlatformConnector::find_for_user_and_slug($this->user->id, Platform::SLUG_ZIPNOVA);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Tests
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 EL CASO QUE JUSTIFICA LA MISIÓN: con las dos credenciales cargadas y sin conector previo,
     * la instancia queda conectada, sin que nadie toque el ABM.
     *
     * @return void
     */
    public function test_conecta_con_las_credenciales_de_la_config()
    {
        $this->configurar(self::API_TOKEN, self::API_SECRET, self::ACCOUNT_ID);

        $this->conectar();

        $conector = $this->conector_actual();
        $this->assertNotNull($conector);
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
        $this->assertTrue($conector->is_connected());
        $this->assertSame(
            ZipnovaClient::codificar_credencial(self::API_TOKEN, self::API_SECRET),
            $conector->access_token,
            'El token va descifrado y con el mismo formato que escribe ZipnovaConexionService::conectar(): base64(token:secret).'
        );
        $this->assertSame(self::ACCOUNT_ID, $conector->platform_user_id);
        $this->assertNull($conector->error_message);
        // assertEquals (no assertSame): PHP compara arrays asociativos por igualdad estricta
        // teniendo en cuenta el orden de las claves, y acá no importa el orden, solo el contenido.
        $this->assertEquals(ZipnovaCredentialsHelper::config_defaults()['bulto_default'], $conector->extra_config['bulto_default']);
    }

    /**
     * El token queda CIFRADO en la base, igual que cualquier otra conexión de Zipnova.
     *
     * @return void
     */
    public function test_el_token_queda_cifrado_en_la_base()
    {
        $this->configurar(self::API_TOKEN, self::API_SECRET, self::ACCOUNT_ID);

        $this->conectar();

        $crudo = DB::table('platform_connectors')
            ->where('id', $this->conector_actual()->id)
            ->value('access_token');

        $this->assertNotSame(ZipnovaClient::codificar_credencial(self::API_TOKEN, self::API_SECRET), $crudo);
        $this->assertNotEmpty($crudo);
    }

    /**
     * Sin `demo_account_id` (opcional: la demo real a veces no lo conoce de antemano), igual
     * conecta — queda sin `platform_user_id`, como un conector recién creado por OAuth antes de
     * resolver la cuenta.
     *
     * @return void
     */
    public function test_conecta_sin_account_id()
    {
        $this->configurar(self::API_TOKEN, self::API_SECRET, null);

        $this->conectar();

        $conector = $this->conector_actual();
        $this->assertNotNull($conector);
        $this->assertTrue($conector->is_connected());
        $this->assertNull($conector->platform_user_id);
    }

    /**
     * @return void
     */
    public function test_sin_ninguna_clave_no_hace_nada()
    {
        $this->conectar();

        $this->assertNull($this->conector_actual());
    }

    /**
     * Con una sola de las dos credenciales tampoco alcanza: sin el secret no hay con qué armar
     * la credencial Basic que Zipnova espera.
     *
     * @return void
     */
    public function test_con_una_sola_credencial_no_hace_nada()
    {
        $this->configurar(self::API_TOKEN, null, self::ACCOUNT_ID);

        $this->conectar();

        $this->assertNull($this->conector_actual());
    }

    /**
     * 🔴 NO ES UNA FUENTE QUE GANE SIEMPRE: `run()` solo llama a este método cuando
     * `foto_de_zipnova()` no encontró nada que restaurar. Se lee el fuente de `run()` por
     * reflexión, mismo criterio que el test análogo de Mercado Pago.
     *
     * @return void
     */
    public function test_solo_se_llama_cuando_no_hay_nada_que_restaurar()
    {
        $run = new ReflectionMethod(DemoSetupHelper::class, 'run');
        $lineas = file($run->getFileName());
        $fuente = implode('', array_slice($lineas, $run->getStartLine() - 1, $run->getEndLine() - $run->getStartLine() + 1));

        $restaurar = strpos($fuente, 'self::restaurar_zipnova(');
        $guarda = strpos($fuente, 'if (empty($foto_zipnova)) {');
        $conectar = strpos($fuente, 'self::conectar_zipnova_desde_env(');

        foreach (['restaurar' => $restaurar, 'guarda' => $guarda, 'conectar' => $conectar] as $nombre => $posicion) {
            $this->assertNotFalse($posicion, "No se encontró '$nombre' en run(); si se renombró, actualizar este test.");
        }

        $this->assertGreaterThan($restaurar, $guarda, 'La guarda tiene que evaluarse después de intentar la restauración.');
        $this->assertGreaterThan($guarda, $conectar, 'conectar_zipnova_desde_env() tiene que quedar DENTRO de la guarda.');
    }
}
