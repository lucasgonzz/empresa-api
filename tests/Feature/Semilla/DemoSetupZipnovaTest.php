<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * La conexión de Zipnova de la demo sobrevive al rearmado (misión
 * `demo-integraciones-zipnova-mp`, 16/9/2026) — mismo mecanismo que ya tenía Mercado Pago desde
 * el 5/9 (`DemoSetupMercadoPagoTest`), extendido acá porque `DemoSetupHelper::run()` seguía
 * borrando el conector de Zipnova en cada `migrate:fresh` sin reponerlo.
 *
 * A diferencia de Mercado Pago, Zipnova no tiene espejo en otra tabla (no es un medio de pago):
 * alcanza con fotografiar y restaurar la fila de `platform_connectors` — token, cuenta, estado y
 * `extra_config` (depósito, bulto por defecto, envío gratis desde $, webhook registrado).
 *
 * No se corre `run()` entero (hace `migrate:fresh` sobre la base de testing): se invocan los dos
 * métodos privados por reflexión y el "fresh" se simula borrando la fila, igual que
 * `DemoSetupMercadoPagoTest`.
 *
 * @group semilla
 */
class DemoSetupZipnovaTest extends EmpresaTestCase
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

    private function plataforma_zipnova()
    {
        return Platform::firstOrCreate(
            ['slug' => Platform::SLUG_ZIPNOVA],
            ['name' => 'Zipnova']
        );
    }

    private function config_de_prueba()
    {
        return [
            'cuenta'             => 'ComercioCity',
            'deposito_origen_id' => 'DEP-1',
            'bulto_default'      => ['peso' => 1000, 'alto' => 10, 'ancho' => 10, 'profundidad' => 10],
            'envio_gratis_desde' => 50000,
            'webhook_id'         => 'wh-de-prueba',
            'webhook_url'        => 'https://api-demo.comerciocity.com/api/zipnova/webhook',
        ];
    }

    /**
     * Conector conectado, tal como lo deja `ZipnovaConexionService::conectar()`.
     *
     * @param array $atributos
     * @return \App\Models\PlatformConnector
     */
    private function conector_conectado(array $atributos = [])
    {
        return PlatformConnector::create(array_merge([
            'user_id'          => $this->user->id,
            'platform_id'      => $this->plataforma_zipnova()->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => 'VE9LRU4tREUtUFJVRUJBOlNFQ1JFVC1ERS1QUlVFQkE=',
            'refresh_token'    => null,
            'public_key'       => null,
            'platform_user_id' => '21850',
            'extra_config'     => $this->config_de_prueba(),
        ], $atributos));
    }

    private function foto()
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'foto_de_zipnova');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $this->user->id);
    }

    private function restaurar($foto)
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'restaurar_zipnova');
        $metodo->setAccessible(true);

        $metodo->invoke(null, $this->user, $foto);
    }

    /**
     * Lo que el `migrate:fresh` hace con esta tabla, sin el `migrate:fresh`.
     *
     * @return void
     */
    private function simular_fresh()
    {
        PlatformConnector::where('user_id', $this->user->id)->delete();
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
     * @return void
     */
    public function test_la_foto_guarda_lo_que_hace_falta_para_volver_a_conectar()
    {
        $this->conector_conectado();

        $foto = $this->foto();

        $this->assertNotNull($foto, 'Con un conector conectado tiene que haber foto.');
        $this->assertSame('VE9LRU4tREUtUFJVRUJBOlNFQ1JFVC1ERS1QUlVFQkE=', $foto['access_token'], 'El token va descifrado: es lo que se vuelve a cifrar al restaurar.');
        $this->assertSame('21850', $foto['platform_user_id']);
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $foto['status']);
        $this->assertEquals($this->config_de_prueba(), $foto['extra_config']);
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
     * 🔴 EL CASO QUE JUSTIFICA LA MISIÓN: después del fresh, el conector vuelve a estar
     * exactamente como estaba, y la tienda sigue cotizando y despachando con la misma cuenta.
     *
     * @return void
     */
    public function test_la_restauracion_deja_el_conector_como_estaba()
    {
        $original = $this->conector_conectado();

        $foto = $this->foto();
        $this->simular_fresh();

        $this->assertNull($this->conector_actual(), 'El fresh simulado tiene que haber borrado el conector.');

        $this->restaurar($foto);

        $conector = $this->conector_actual();
        $this->assertNotNull($conector, 'El conector tiene que volver a existir.');
        $this->assertNotSame($original->id, $conector->id, 'Es una fila nueva, como después de un fresh real.');
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
        $this->assertTrue($conector->is_connected());
        $this->assertSame('VE9LRU4tREUtUFJVRUJBOlNFQ1JFVC1ERS1QUlVFQkE=', $conector->access_token);
        $this->assertSame('21850', $conector->platform_user_id);
        $this->assertNull($conector->error_message);
        $this->assertEquals($this->config_de_prueba(), $conector->extra_config, 'El depósito, el bulto por defecto y el webhook registrado vuelven tal cual: no hay que reconectar contra la API de Zipnova.');
    }

    /**
     * El token restaurado queda CIFRADO en la base, igual que lo deja la conexión manual: si
     * alguien lo escribiera con el query builder quedaría en claro.
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

        $this->assertNotSame('VE9LRU4tREUtUFJVRUJBOlNFQ1JFVC1ERS1QUlVFQkE=', $crudo, 'El token no puede quedar en claro en la base.');
        $this->assertNotEmpty($crudo);
    }

    /**
     * @return void
     */
    public function test_una_foto_vacia_no_toca_nada()
    {
        $this->restaurar(null);
        $this->assertNull($this->conector_actual());

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

        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'foto_de_zipnova');
        $metodo->setAccessible(true);

        $this->assertNull($metodo->invoke(null, null));
    }

    /**
     * 🔴 EL ORDEN DENTRO DE `run()` ES LA MITAD DEL ARREGLO, y ninguna otra aserción lo mira: la
     * foto tiene que tomarse ANTES del `migrate:fresh` (después ya no hay nada que fotografiar) y
     * la restauración tiene que correr DESPUÉS del `foreach` de seeders y de `tienda()` (antes, la
     * migración que asegura la fila `zipnova` de `platforms` todavía no corrió y
     * `find_or_create_for_user_and_slug` devuelve null: la conexión se pierde con un warning en el
     * log y la suite verde).
     *
     * Se lee el fuente de `run()` por reflexión, igual que `DemoSetupMercadoPagoTest`.
     *
     * @return void
     */
    public function test_la_foto_va_antes_del_fresh_y_la_restauracion_despues_de_los_seeders()
    {
        $run = new ReflectionMethod(DemoSetupHelper::class, 'run');
        $lineas = file($run->getFileName());
        $fuente = implode('', array_slice($lineas, $run->getStartLine() - 1, $run->getEndLine() - $run->getStartLine() + 1));

        $foto = strpos($fuente, 'self::foto_de_zipnova(');
        $fresh = strpos($fuente, "Artisan::call('migrate:fresh'");
        $seeders = strpos($fuente, "Artisan::call('db:seed', ['--class' => \$seeder");
        $tienda = strpos($fuente, 'self::tienda(');
        $restaurar = strpos($fuente, 'self::restaurar_zipnova(');

        foreach (['foto' => $foto, 'fresh' => $fresh, 'seeders' => $seeders, 'tienda' => $tienda, 'restaurar' => $restaurar] as $nombre => $posicion) {
            $this->assertNotFalse($posicion, "No se encontró '$nombre' en run(); si se renombró, actualizar este test.");
        }

        $this->assertLessThan($fresh, $foto, 'La foto de Zipnova tiene que tomarse ANTES del migrate:fresh.');
        $this->assertGreaterThan($seeders, $restaurar, 'La restauración tiene que correr DESPUÉS del foreach de seeders (PlatformSeeder asegura la fila zipnova).');
        $this->assertGreaterThan($tienda, $restaurar, 'La restauración tiene que correr DESPUÉS de tienda().');
    }
}
