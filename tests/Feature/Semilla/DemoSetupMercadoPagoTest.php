<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * La conexión de Mercado Pago de la demo sobrevive al rearmado (misión `mercado-pago-cobro-demo`,
 * 5/9/2026).
 *
 * `DemoSetupHelper::run()` arranca con `migrate:fresh`, que borra el conector que se conectó por
 * OAuth desde ABM -> Integraciones y el espejo de `payment_methods`. Lo que fija esta clase es el
 * par foto/restauración que rodea a ese fresh:
 *
 * 1. La foto guarda lo justo para volver a conectar (tokens descifrados, vencimiento, cuenta,
 *    public key, y la configuración comercial del medio de pago).
 * 2. La restauración deja el conector CONECTADO con los mismos valores y repone el espejo en
 *    `payment_methods` con el MISMO método que usa el callback del OAuth: una sola definición.
 * 3. Sin conector, con conector sin conectar, con foto vacía o con un token que no se puede
 *    descifrar, no pasa nada — ni excepción ni filas nuevas. El setup de la demo no puede fallar
 *    por esto.
 *
 * No se corre `run()` entero (hace `migrate:fresh` sobre la base de testing y tarda nueve
 * minutos): se invocan los dos métodos privados por reflexión, igual que
 * `DemoSetupTiendaOnlineTest` hace con `tienda()`, y el "fresh" se simula borrando las filas.
 *
 * @group semilla
 */
class DemoSetupMercadoPagoTest extends EmpresaTestCase
{
    /** @var \App\Models\User */
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    private function plataforma_mp()
    {
        return Platform::firstOrCreate(
            ['slug' => Platform::SLUG_MERCADO_PAGO],
            ['name' => 'Mercado Pago']
        );
    }

    private function tipo_mp()
    {
        return PaymentMethodType::firstOrCreate(['name' => 'MercadoPago']);
    }

    /**
     * Conector conectado, tal como lo deja `MercadoPagoOAuthService::handle_callback()`.
     *
     * @param array $atributos
     * @return \App\Models\PlatformConnector
     */
    private function conector_conectado(array $atributos = [])
    {
        return PlatformConnector::create(array_merge([
            'user_id'          => $this->user->id,
            'platform_id'      => $this->plataforma_mp()->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => 'APP_USR-TOKEN-DE-PRUEBA',
            'refresh_token'    => 'TG-REFRESH-DE-PRUEBA',
            'expires_at'       => Carbon::now()->addDays(170)->startOfSecond(),
            'platform_user_id' => '163250661',
            'public_key'       => 'APP_USR-PUBLIC-KEY-DE-PRUEBA',
        ], $atributos));
    }

    /**
     * El espejo en `payment_methods`, con configuración comercial propia.
     *
     * @return \App\Models\PaymentMethod
     */
    private function espejo()
    {
        return PaymentMethod::create([
            'user_id'                => $this->user->id,
            'payment_method_type_id' => $this->tipo_mp()->id,
            'name'                   => 'Mercado Pago (tarjeta)',
            'description'            => 'Pagá con tarjeta o dinero en cuenta',
            'discount'               => 5,
            'surchage'               => null,
            'public_key'             => 'APP_USR-PUBLIC-KEY-DE-PRUEBA',
            'access_token'           => 'APP_USR-TOKEN-DE-PRUEBA',
        ]);
    }

    private function foto()
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'foto_de_mercado_pago');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $this->user->id);
    }

    private function restaurar($foto)
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'restaurar_mercado_pago');
        $metodo->setAccessible(true);

        $metodo->invoke(null, $this->user, $foto);
    }

    /**
     * Lo que el `migrate:fresh` hace con estas dos tablas, sin el `migrate:fresh`.
     *
     * @return void
     */
    private function simular_fresh()
    {
        PlatformConnector::where('user_id', $this->user->id)->delete();
        PaymentMethod::where('user_id', $this->user->id)->delete();
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
     * @return void
     */
    public function test_la_foto_guarda_lo_que_hace_falta_para_volver_a_conectar()
    {
        $conector = $this->conector_conectado();
        $this->espejo();

        $foto = $this->foto();

        $this->assertNotNull($foto, 'Con un conector conectado tiene que haber foto.');
        $this->assertSame('APP_USR-TOKEN-DE-PRUEBA', $foto['access_token'], 'El token va descifrado: es lo que se vuelve a cifrar al restaurar.');
        $this->assertSame('TG-REFRESH-DE-PRUEBA', $foto['refresh_token']);
        $this->assertSame($conector->expires_at->toDateTimeString(), $foto['expires_at']);
        $this->assertSame('163250661', $foto['platform_user_id']);
        $this->assertSame('APP_USR-PUBLIC-KEY-DE-PRUEBA', $foto['public_key']);
        $this->assertSame('Mercado Pago (tarjeta)', $foto['payment_method']['name']);
        $this->assertEquals(5, $foto['payment_method']['discount']);
    }

    /**
     * @return void
     */
    public function test_sin_conector_conectado_no_hay_foto()
    {
        $this->assertNull($this->foto(), 'Sin conector no hay nada que fotografiar.');

        $this->conector_conectado([
            'status'       => PlatformConnector::STATUS_SIN_CONECTAR,
            'access_token' => null,
        ]);

        $this->assertNull($this->foto(), 'Un conector sin token no está conectado: no hay foto.');
    }

    /**
     * 🔴 EL CASO QUE JUSTIFICA LA MISIÓN: después del fresh, el conector y el espejo vuelven a
     * estar exactamente como estaban, y la tienda sigue cobrando con la misma cuenta.
     *
     * @return void
     */
    public function test_la_restauracion_deja_el_conector_y_el_espejo_como_estaban()
    {
        $original = $this->conector_conectado();
        $this->espejo();

        $foto = $this->foto();
        $this->simular_fresh();

        $this->assertNull($this->conector_actual(), 'El fresh simulado tiene que haber borrado el conector.');

        $this->restaurar($foto);

        $conector = $this->conector_actual();
        $this->assertNotNull($conector, 'El conector tiene que volver a existir.');
        $this->assertNotSame($original->id, $conector->id, 'Es una fila nueva, como después de un fresh real.');
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
        $this->assertTrue($conector->is_connected());
        $this->assertSame('APP_USR-TOKEN-DE-PRUEBA', $conector->access_token);
        $this->assertSame('TG-REFRESH-DE-PRUEBA', $conector->refresh_token);
        $this->assertSame($original->expires_at->toDateTimeString(), $conector->expires_at->toDateTimeString());
        $this->assertSame('163250661', $conector->platform_user_id);
        $this->assertSame('APP_USR-PUBLIC-KEY-DE-PRUEBA', $conector->public_key);
        $this->assertNull($conector->error_message);

        $espejo = $this->espejo_actual();
        $this->assertNotNull($espejo, 'El espejo de payment_methods tiene que volver: es de donde cobra la tienda vieja.');
        $this->assertSame('APP_USR-TOKEN-DE-PRUEBA', $espejo->access_token);
        $this->assertSame('APP_USR-PUBLIC-KEY-DE-PRUEBA', $espejo->public_key);
        $this->assertSame('Mercado Pago (tarjeta)', $espejo->name, 'La configuración comercial del medio de pago también vuelve.');
        $this->assertSame('Pagá con tarjeta o dinero en cuenta', $espejo->description);
        $this->assertEquals(5, $espejo->discount);
    }

    /**
     * El token restaurado queda CIFRADO en la base, igual que lo deja el OAuth: si alguien lo
     * escribiera con el query builder quedaría en claro.
     *
     * @return void
     */
    public function test_el_token_restaurado_queda_cifrado_en_la_base()
    {
        $this->conector_conectado();
        $foto = $this->foto();
        $this->simular_fresh();

        $this->restaurar($foto);

        $crudo = DB::table('platform_connectors')
            ->where('id', $this->conector_actual()->id)
            ->value('access_token');

        $this->assertNotSame('APP_USR-TOKEN-DE-PRUEBA', $crudo, 'El token no puede quedar en claro en la base.');
        $this->assertNotEmpty($crudo);
    }

    /**
     * Sin espejo previo, la restauración lo crea con los textos del seeder, como hace el OAuth
     * con un comercio que nunca cargó el medio de pago a mano.
     *
     * @return void
     */
    public function test_sin_espejo_previo_la_restauracion_lo_crea()
    {
        $this->conector_conectado();
        $foto = $this->foto();
        $this->simular_fresh();

        $this->restaurar($foto);

        $espejo = $this->espejo_actual();
        $this->assertNotNull($espejo);
        $this->assertSame('APP_USR-TOKEN-DE-PRUEBA', $espejo->access_token);
        $this->assertSame('MercadoPago', $espejo->name);
    }

    /**
     * @return void
     */
    public function test_una_foto_vacia_no_toca_nada()
    {
        $this->restaurar(null);
        $this->assertNull($this->conector_actual());
        $this->assertNull($this->espejo_actual());

        $this->restaurar(['access_token' => null]);
        $this->assertNull($this->conector_actual());
    }

    /**
     * Un token que no se puede descifrar (APP_KEY rotada, fila escrita en claro) no rompe el
     * setup: la foto es null y la demo se arma sin conexión, como antes de esta misión.
     *
     * @return void
     */
    public function test_un_token_que_no_se_puede_descifrar_no_rompe_el_setup()
    {
        $conector = $this->conector_conectado();

        DB::table('platform_connectors')
            ->where('id', $conector->id)
            ->update(['access_token' => 'esto-no-es-un-ciphertext-valido']);

        $this->assertNull($this->foto(), 'Con un token indescifrable no hay foto, y no hay excepción.');
    }

    /**
     * Un conector con el token vencido pero con refresh_token también sobrevive, con su vencimiento
     * tal cual: el cron `mercadopago:refresh-tokens` lo renueva después. Descartarlo obligaría a
     * rehacer el OAuth a mano sin que nadie avise.
     *
     * @return void
     */
    public function test_un_conector_vencido_con_refresh_token_tambien_se_restaura()
    {
        $vencido = Carbon::now()->subDays(3)->startOfSecond();
        $this->conector_conectado(['expires_at' => $vencido]);

        $foto = $this->foto();
        $this->assertNotNull($foto, 'Un conector vencido con refresh_token tiene que fotografiarse.');

        $this->simular_fresh();
        $this->restaurar($foto);

        $conector = $this->conector_actual();
        $this->assertNotNull($conector);
        $this->assertSame($vencido->toDateTimeString(), $conector->expires_at->toDateTimeString(), 'El vencimiento vuelve tal cual: es lo que hace que el cron lo renueve.');
        $this->assertSame('TG-REFRESH-DE-PRUEBA', $conector->refresh_token);
        $this->assertFalse($conector->is_connected(), 'Sigue vencido hasta que el cron lo renueve: no se inventa una vigencia.');
    }

    /**
     * Sin `USER_ID` en la instancia no hay dueño que fotografiar, y eso se dice en el log: es la
     * única señal de que la demo va a perder la conexión en cada rearmado.
     *
     * @return void
     */
    public function test_sin_user_id_la_foto_avisa_y_no_explota()
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($mensaje) {
                return strpos($mensaje, 'USER_ID') !== false;
            });

        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'foto_de_mercado_pago');
        $metodo->setAccessible(true);

        $this->assertNull($metodo->invoke(null, null));
    }

    /**
     * 🔴 EL ORDEN DENTRO DE `run()` ES LA MITAD DEL ARREGLO, y ninguna otra aserción lo mira: la
     * foto tiene que tomarse ANTES del `migrate:fresh` (después ya no hay nada que fotografiar) y la
     * restauración tiene que correr DESPUÉS del `foreach` de seeders y de `tienda()` (antes,
     * `PlatformSeeder` no sembró la fila `mercado_pago` y `find_or_create_for_user_and_slug` devuelve
     * null: la conexión se pierde con un warning en el log y la suite verde).
     *
     * Se lee el fuente de `run()` por reflexión, igual que `AlineacionLocalDemoTest` hace con la
     * cola de seeders.
     *
     * @return void
     */
    public function test_la_foto_va_antes_del_fresh_y_la_restauracion_despues_de_los_seeders()
    {
        $run = new ReflectionMethod(DemoSetupHelper::class, 'run');
        $lineas = file($run->getFileName());
        $fuente = implode('', array_slice($lineas, $run->getStartLine() - 1, $run->getEndLine() - $run->getStartLine() + 1));

        $foto = strpos($fuente, 'self::foto_de_mercado_pago(');
        $fresh = strpos($fuente, "Artisan::call('migrate:fresh'");
        $seeders = strpos($fuente, "Artisan::call('db:seed', ['--class' => \$seeder");
        $tienda = strpos($fuente, 'self::tienda(');
        $restaurar = strpos($fuente, 'self::restaurar_mercado_pago(');

        foreach (['foto' => $foto, 'fresh' => $fresh, 'seeders' => $seeders, 'tienda' => $tienda, 'restaurar' => $restaurar] as $nombre => $posicion) {
            $this->assertNotFalse($posicion, "No se encontró '$nombre' en run(); si se renombró, actualizar este test.");
        }

        $this->assertLessThan($fresh, $foto, 'La foto de Mercado Pago tiene que tomarse ANTES del migrate:fresh.');
        $this->assertGreaterThan($seeders, $restaurar, 'La restauración tiene que correr DESPUÉS del foreach de seeders (PlatformSeeder, PaymentMethodTypeSeeder).');
        $this->assertGreaterThan($tienda, $restaurar, 'La restauración tiene que correr DESPUÉS de tienda().');
    }
}
