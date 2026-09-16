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
 * credenciales del `.env` de la instancia (misión `mp-precio-servidor-y-credenciales-env`,
 * 16/9/2026).
 *
 * Es el mismo mecanismo que "pegar el Access Token y la Public Key" a mano desde ABM ->
 * Integraciones (la opción manual del conector), pero automático y con la credencial que ya está
 * cargada en la instancia — pensado para que una demo recién armada, sin nadie que haya conectado
 * nunca, pueda cobrar igual.
 *
 * 🔴 Es un DEFAULT, no una fuente que gane siempre: si ya hay algo que restaurar (alguien conectó
 * a mano, por OAuth o pegando otra credencial), `run()` no llama a este método — lo prueba
 * `test_solo_se_llama_cuando_no_hay_nada_que_restaurar()` leyendo el fuente, igual que
 * `DemoSetupMercadoPagoTest` prueba el orden de la foto/restauración.
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
        $this->variable('MERCADOPAGO_DEMO_ACCESS_TOKEN', null);
        $this->variable('MERCADOPAGO_DEMO_PUBLIC_KEY', null);

        parent::tearDown();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Fuerza (o borra, con null) una variable en las tres fuentes que lee `env()` de Laravel.
     *
     * @param string $nombre
     * @param string|null $valor
     * @return void
     */
    private function variable($nombre, $valor)
    {
        if ($valor === null) {
            unset($_ENV[$nombre], $_SERVER[$nombre]);
            putenv($nombre);

            return;
        }

        $_ENV[$nombre] = $valor;
        $_SERVER[$nombre] = $valor;
        putenv($nombre.'='.$valor);
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
     * 🔴 EL CASO QUE JUSTIFICA LA MISIÓN: con las dos variables cargadas y sin conector previo, la
     * instancia queda conectada y cobrando, sin que nadie toque el ABM.
     *
     * @return void
     */
    public function test_conecta_con_las_credenciales_del_env()
    {
        $this->variable('MERCADOPAGO_DEMO_ACCESS_TOKEN', self::ACCESS_TOKEN);
        $this->variable('MERCADOPAGO_DEMO_PUBLIC_KEY', self::PUBLIC_KEY);

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
        $this->variable('MERCADOPAGO_DEMO_ACCESS_TOKEN', self::ACCESS_TOKEN);
        $this->variable('MERCADOPAGO_DEMO_PUBLIC_KEY', self::PUBLIC_KEY);

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
    public function test_sin_ninguna_variable_no_hace_nada()
    {
        $this->conectar();

        $this->assertNull($this->conector_actual());
        $this->assertNull($this->espejo_actual());
    }

    /**
     * Con una sola de las dos variables tampoco alcanza: sin public key no hay con qué cobrar del
     * lado del browser (Checkout Pro la necesita), así que no tiene sentido conectar a medias.
     *
     * @return void
     */
    public function test_con_una_sola_variable_no_hace_nada()
    {
        $this->variable('MERCADOPAGO_DEMO_ACCESS_TOKEN', self::ACCESS_TOKEN);

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
