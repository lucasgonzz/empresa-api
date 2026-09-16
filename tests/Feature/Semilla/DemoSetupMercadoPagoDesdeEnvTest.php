<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Cuando no hay ninguna conexión de Mercado Pago que restaurar, la demo se conecta sola con las
 * credenciales de demo de `config('services.mercadopago')` (misión
 * `mp-precio-servidor-y-credenciales-env`, 16/9/2026).
 *
 * La única vía real hoy para conectar Mercado Pago desde ABM -> Integraciones es el OAuth (la
 * carga manual de Access Token/Public Key se sacó del SPA hace tiempo): esto no imita ninguna
 * pantalla, es un camino nuevo, pensado para que una demo recién armada, sin nadie que haya
 * conectado nunca, pueda cobrar igual — escribiendo el conector con la MISMA forma que deja el
 * callback del OAuth, así que la tienda no nota la diferencia.
 *
 * 🔴 Es un DEFAULT, no una fuente que gane siempre: si ya hay algo que restaurar (alguien conectó
 * por OAuth, o se restauró de un armado anterior), `run()` no llama a este método — lo prueba
 * `test_solo_se_llama_cuando_no_hay_nada_que_restaurar()` leyendo el fuente, igual que
 * `DemoSetupMercadoPagoTest` prueba el orden de la foto/restauración.
 *
 * 🔴 Las credenciales se leen de `config()`, no de `env()` directo (hallazgo del verificador
 * independiente sobre la primera versión de esta misión): con `config:cache` activo — lo normal
 * en producción — `env()` fuera de `config/*.php` devuelve el default, y esta misma clase de bug
 * ya rompió `DURACION_REPORTES` en producción. Por eso los tests fuerzan el valor con
 * `config(['services.mercadopago.demo_access_token' => ...])`, no con `putenv()`/`$_ENV`: eso
 * último dejaría de ejercitar el camino real en cuanto alguien cachee la config.
 *
 * No se corre `run()` entero: se invoca `conectar_mercado_pago_desde_env()` directo por reflexión.
 *
 * @group semilla
 */
class DemoSetupMercadoPagoDesdeEnvTest extends EmpresaTestCase
{
    const ACCESS_TOKEN = 'TEST-1234567890-token-de-prueba';
    const PUBLIC_KEY   = 'TEST-public-key-de-prueba';

    /** @var \App\Models\User */
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    protected function tearDown(): void
    {
        $this->configurar(null, null);

        parent::tearDown();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Fuerza (o borra, con null) las dos claves de config que lee
     * `conectar_mercado_pago_desde_env()` — el mismo repositorio que ve la aplicación, con o sin
     * `config:cache`, a diferencia de tocar `$_ENV`/`putenv()`.
     *
     * @param string|null $access_token
     * @param string|null $public_key
     * @return void
     */
    private function configurar($access_token, $public_key)
    {
        config([
            'services.mercadopago.demo_access_token' => $access_token,
            'services.mercadopago.demo_public_key'   => $public_key,
        ]);
    }

    private function tipo_mp()
    {
        return PaymentMethodType::firstOrCreate(['name' => 'MercadoPago']);
    }

    private function conectar()
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'conectar_mercado_pago_desde_env');
        $metodo->setAccessible(true);

        $metodo->invoke(null, $this->user);
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

    /*
    |---------------------------------------------------------------------------------------------
    | Tests
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 EL CASO QUE JUSTIFICA LA MISIÓN: con las dos claves cargadas y sin conector previo, la
     * instancia queda conectada y cobrando, sin que nadie toque el ABM.
     *
     * @return void
     */
    public function test_conecta_con_las_credenciales_de_la_config()
    {
        $this->configurar(self::ACCESS_TOKEN, self::PUBLIC_KEY);

        $this->conectar();

        $conector = $this->conector_actual();
        $this->assertNotNull($conector);
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
        $this->assertTrue($conector->is_connected());
        $this->assertSame(self::ACCESS_TOKEN, $conector->access_token, 'El token va descifrado: el cast lo cifra solo.');
        $this->assertSame(self::PUBLIC_KEY, $conector->public_key);
        $this->assertNull($conector->error_message);

        $espejo = $this->espejo_actual();
        $this->assertNotNull($espejo, 'El espejo de payment_methods tiene que existir: es de donde cobra la tienda vieja.');
        $this->assertSame(self::ACCESS_TOKEN, $espejo->access_token);
        $this->assertSame(self::PUBLIC_KEY, $espejo->public_key);
    }

    /**
     * El token queda CIFRADO en la base, igual que cualquier otra conexión de Mercado Pago.
     *
     * @return void
     */
    public function test_el_token_queda_cifrado_en_la_base()
    {
        $this->configurar(self::ACCESS_TOKEN, self::PUBLIC_KEY);

        $this->conectar();

        $crudo = DB::table('platform_connectors')
            ->where('id', $this->conector_actual()->id)
            ->value('access_token');

        $this->assertNotSame(self::ACCESS_TOKEN, $crudo);
        $this->assertNotEmpty($crudo);
    }

    /**
     * @return void
     */
    public function test_sin_ninguna_clave_no_hace_nada()
    {
        $this->conectar();

        $this->assertNull($this->conector_actual());
        $this->assertNull($this->espejo_actual());
    }

    /**
     * Con una sola de las dos claves tampoco alcanza: sin public key no hay con qué cobrar del
     * lado del browser (Checkout Pro la necesita), así que no tiene sentido conectar a medias.
     *
     * @return void
     */
    public function test_con_una_sola_clave_no_hace_nada()
    {
        $this->configurar(self::ACCESS_TOKEN, null);

        $this->conectar();

        $this->assertNull($this->conector_actual());
    }

    /**
     * 🔴 NO ES UNA FUENTE QUE GANE SIEMPRE: `run()` solo llama a este método cuando
     * `foto_de_mercado_pago()` no encontró nada que restaurar. Se lee el fuente de `run()` por
     * reflexión, igual que `DemoSetupMercadoPagoTest::test_la_foto_va_antes_del_fresh_...`.
     *
     * @return void
     */
    public function test_solo_se_llama_cuando_no_hay_nada_que_restaurar()
    {
        $run = new ReflectionMethod(DemoSetupHelper::class, 'run');
        $lineas = file($run->getFileName());
        $fuente = implode('', array_slice($lineas, $run->getStartLine() - 1, $run->getEndLine() - $run->getStartLine() + 1));

        $restaurar = strpos($fuente, 'self::restaurar_mercado_pago(');
        $guarda = strpos($fuente, 'if (empty($foto_mercado_pago)) {');
        $conectar = strpos($fuente, 'self::conectar_mercado_pago_desde_env(');

        foreach (['restaurar' => $restaurar, 'guarda' => $guarda, 'conectar' => $conectar] as $nombre => $posicion) {
            $this->assertNotFalse($posicion, "No se encontró '$nombre' en run(); si se renombró, actualizar este test.");
        }

        $this->assertGreaterThan($restaurar, $guarda, 'La guarda tiene que evaluarse después de intentar la restauración.');
        $this->assertGreaterThan($guarda, $conectar, 'conectar_mercado_pago_desde_env() tiene que quedar DENTRO de la guarda.');
    }
}
