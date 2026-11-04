<?php

namespace Tests\Feature\PruebaGratuita;

use App\Exceptions\BaseConDatosException;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\PruebaConfig;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * El demo setup de la prueba gratuita (C1, misión prueba-experiencia-empresa, 3/11/2026):
 *
 *  1. `password` (mínimo 8) queda como contraseña del dueño; sin el campo, el documento, como siempre.
 *  2. `prueba` {dias, vence_at, video_url, whatsapp_soporte} fija `users.expired_at` y la configuración.
 *  3. Un `password` corto o un `prueba` que no es objeto contestan 422 ANTES de vaciar nada.
 *  4. `FOR_USER=prueba` cuenta como demo para la siembra, pero la guarda del `migrate:fresh` sigue activa
 *     salvo que el POST traiga la clave de admin de la instancia (y el flag no sale del payload).
 *
 * @group semilla
 */
class DemoSetupDeLaPruebaTest extends EmpresaTestCase
{
    const CORTE = 'migrate:fresh cortado por el test de la prueba gratuita';
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-la-prueba-7c1e';

    protected function setUp(): void
    {
        parent::setUp();

        // El modo prueba existe solo con FOR_USER=prueba (lo escribe el script de alta del admin).
        config(['app.FOR_USER' => 'prueba']);
        PruebaHelper::olvidar_cache();
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    /**
     * Llama a `password_inicial()` (privada) con el payload dado.
     *
     * @param array $data
     * @return string
     */
    private function password_inicial(array $data)
    {
        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'password_inicial');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $data);
    }

    private function verificar_que_artisan_es_un_mock()
    {
        $this->assertInstanceOf(
            MockInterface::class,
            Artisan::getFacadeRoot(),
            'SEGURO: Artisan no quedó mockeado y el setup vaciaría la base de testing. No se sigue.'
        );
    }

    /*
    |------------------------------------------------------------------------------------------------
    | 1. La contraseña
    |------------------------------------------------------------------------------------------------
    */

    public function test_con_password_de_8_o_mas_el_dueno_queda_con_esa_contrasena()
    {
        $hash = $this->password_inicial(['doc_number' => '20123456', 'password' => 'mi-clave-larga']);

        $this->assertTrue(Hash::check('mi-clave-larga', $hash));
        $this->assertFalse(Hash::check('20123456', $hash), 'Con password, el documento ya no abre la cuenta.');
    }

    public function test_sin_password_la_contrasena_sigue_siendo_el_documento()
    {
        $hash = $this->password_inicial(['doc_number' => '20123456']);

        $this->assertTrue(Hash::check('20123456', $hash));
    }

    public function test_un_password_corto_se_rechaza_con_422_antes_de_vaciar_nada()
    {
        // La validación no depende del modo: sin el marcador, la segunda guarda (base con datos) no se mete.
        config(['app.FOR_USER' => null]);
        Artisan::shouldReceive('call')->never();
        $this->verificar_que_artisan_es_un_mock();

        try {
            DemoSetupHelper::run(['business_type' => 'ferreteria', 'password' => 'corta']);
            $this->fail('Un password de menos de 8 tenía que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }
    }

    public function test_un_prueba_que_no_es_objeto_se_rechaza_con_422_antes_de_vaciar_nada()
    {
        // La validación no depende del modo: sin el marcador, la segunda guarda (base con datos) no se mete.
        config(['app.FOR_USER' => null]);
        Artisan::shouldReceive('call')->never();
        $this->verificar_que_artisan_es_un_mock();

        try {
            DemoSetupHelper::run(['business_type' => 'ferreteria', 'prueba' => 'si']);
            $this->fail('Un `prueba` que no es objeto tenía que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('prueba', $e->errors());
        }
    }

    public function test_un_vence_at_que_no_es_fecha_se_rechaza_con_422()
    {
        // La validación no depende del modo: sin el marcador, la segunda guarda (base con datos) no se mete.
        config(['app.FOR_USER' => null]);
        Artisan::shouldReceive('call')->never();
        $this->verificar_que_artisan_es_un_mock();

        try {
            DemoSetupHelper::run(['business_type' => 'ferreteria', 'prueba' => ['vence_at' => 'la semana que viene']]);
            $this->fail('Un vence_at inválido tenía que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('prueba.vence_at', $e->errors());
        }
    }

    /*
    |------------------------------------------------------------------------------------------------
    | 2. La configuración de la prueba y el vencimiento
    |------------------------------------------------------------------------------------------------
    */

    public function test_activar_desde_payload_guarda_la_config_y_fija_expired_at_respetando_la_zona()
    {
        $dueno = User::where('email', \Database\Seeders\testing\TestingFerreteriaSeeder::USER_EMAIL)->first();

        PruebaHelper::activar_desde_payload($dueno, [
            'dias'             => 7,
            'vence_at'         => '2026-11-10T14:00:00-03:00',
            'video_url'        => 'https://videos.comerciocity.com/bienvenida.mp4',
            'whatsapp_soporte' => '+5493444622139',
        ]);

        $config = PruebaConfig::where('owner_id', $dueno->id)->first();
        $this->assertNotNull($config);
        $this->assertSame(7, (int) $config->dias);
        $this->assertSame('https://videos.comerciocity.com/bienvenida.mp4', $config->video_url);
        $this->assertSame('+5493444622139', $config->whatsapp_soporte);
        $this->assertNull($config->modal_visto_at);

        $this->assertSame('2026-11-10 14:00:00', (string) DB::table('users')->where('id', $dueno->id)->value('expired_at'));
        $this->assertSame('2026-11-10T14:00:00-03:00', PruebaHelper::iso(PruebaHelper::vence_at($dueno->id)));
        $this->assertTrue(PruebaHelper::activa($dueno->id));
        $this->assertFalse(PruebaHelper::vencida($dueno->id));
    }

    public function test_sin_vence_at_pero_con_dias_vence_a_los_dias_de_ahora()
    {
        $dueno = User::where('email', \Database\Seeders\testing\TestingFerreteriaSeeder::USER_EMAIL)->first();

        PruebaHelper::activar_desde_payload($dueno, ['dias' => 10]);

        $vence_at = PruebaHelper::vence_at($dueno->id);
        $this->assertNotNull($vence_at);
        $this->assertEqualsWithDelta(Carbon::now()->addDays(10)->timestamp, $vence_at->timestamp, 120);
    }

    public function test_sin_prueba_no_hay_config_y_el_resumen_del_spa_es_null()
    {
        $dueno = User::where('email', \Database\Seeders\testing\TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertFalse(PruebaHelper::activa($dueno->id));
        $this->assertNull(PruebaHelper::resumen_para_spa($dueno->id));
    }

    /*
    |------------------------------------------------------------------------------------------------
    | 3. FOR_USER=prueba y la guarda
    |------------------------------------------------------------------------------------------------
    */

    public function test_for_user_prueba_cuenta_como_demo_para_la_siembra_y_es_instancia_de_prueba_es_estricta()
    {
        config(['app.env' => 'production', 'app.FOR_USER' => 'prueba']);
        $this->assertTrue(DemoSetupHelper::es_instancia_de_demo());
        $this->assertTrue(DemoSetupHelper::es_instancia_de_prueba());

        config(['app.env' => 'production', 'app.FOR_USER' => 'demo']);
        $this->assertTrue(DemoSetupHelper::es_instancia_de_demo());
        $this->assertFalse(DemoSetupHelper::es_instancia_de_prueba());

        config(['app.env' => 'testing', 'app.FOR_USER' => null]);
        $this->assertFalse(DemoSetupHelper::es_instancia_de_prueba(), 'La suite NO es una instancia de prueba: la estricta no mira app.env.');
        $this->assertFalse(PruebaHelper::puede_haber_prueba(), 'Sin FOR_USER=prueba no hay modo prueba, ni en la suite: cero costo por request.');
    }

    public function test_en_una_instancia_de_prueba_con_datos_y_sin_clave_el_setup_se_niega_sin_tocar_nada()
    {
        config(['app.env' => 'production', 'app.FOR_USER' => 'prueba']);
        Artisan::shouldReceive('call')->never();
        $this->verificar_que_artisan_es_un_mock();

        $articulos = (int) DB::table('articles')->count();
        $this->assertGreaterThan(0, $articulos, 'El fixture tiene que tener datos para que la guarda actúe.');

        try {
            DemoSetupHelper::run(['business_type' => 'ferreteria', 'con_clave_de_admin' => false]);
            $this->fail('Con datos y sin clave, run() tenía que negarse.');
        } catch (BaseConDatosException $e) {
            $this->assertSame($articulos, (int) DB::table('articles')->count(), 'No se tocó nada.');
        }
    }

    public function test_en_una_instancia_de_prueba_con_la_clave_el_setup_pasa_la_guarda()
    {
        config(['app.env' => 'production', 'app.FOR_USER' => 'prueba']);
        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate:fresh', ['--force' => true])
            ->andThrow(new \RuntimeException(self::CORTE));
        $this->verificar_que_artisan_es_un_mock();

        try {
            DemoSetupHelper::run(['business_type' => 'ferreteria', 'con_clave_de_admin' => true]);
            $this->fail('Tenía que llegar al migrate:fresh (cortado por el mock).');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::CORTE, $e->getMessage());
        }
    }

    public function test_el_endpoint_no_deja_que_el_flag_de_la_clave_venga_en_el_payload()
    {
        config(['app.env' => 'production', 'app.FOR_USER' => 'prueba']);
        config(['services.admin_api.api_key' => self::CLAVE_DE_ADMIN]);
        Artisan::shouldReceive('call')->never();
        $this->verificar_que_artisan_es_un_mock();

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', [
            'business_type'      => 'ferreteria',
            'con_clave_de_admin' => true,
        ]);

        $respuesta->assertStatus(409);
        $this->assertTrue((bool) $respuesta->json('base_con_datos'));
    }

    public function test_el_endpoint_con_la_clave_de_la_instancia_pasa_la_guarda()
    {
        config(['app.env' => 'production', 'app.FOR_USER' => 'prueba']);
        config(['services.admin_api.api_key' => self::CLAVE_DE_ADMIN]);
        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate:fresh', ['--force' => true])
            ->andThrow(new \RuntimeException(self::CORTE));
        $this->verificar_que_artisan_es_un_mock();

        $respuesta = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->postJson('/api/admin-sync/demo-setup', ['business_type' => 'ferreteria']);

        // El mock corta en el migrate:fresh: 500 con el corte, pero NO el 409 de la guarda.
        $respuesta->assertStatus(500);
        $this->assertStringContainsString(self::CORTE, (string) $respuesta->json('error'));
    }

    /**
     * La primera sentencia de run() sigue siendo la guarda de siempre (lo fija
     * GuardaDeInstanciaDelDemoSetupTest) y la segunda, la de la prueba, también viene antes de cualquier
     * Artisan::call.
     */
    public function test_la_guarda_de_la_prueba_viene_antes_de_cualquier_artisan_call()
    {
        $run    = new ReflectionMethod(DemoSetupHelper::class, 'run');
        $lineas = file($run->getFileName());
        $fuente = implode('', array_slice($lineas, $run->getStartLine() - 1, $run->getEndLine() - $run->getStartLine() + 1));

        $guarda_prueba  = strpos($fuente, "self::es_instancia_de_prueba() && empty(\$data['con_clave_de_admin'])");
        $validacion     = strpos($fuente, 'PruebaHelper::validar_payload([');
        $primer_artisan = strpos($fuente, 'Artisan::call(');

        $this->assertNotFalse($guarda_prueba, 'run() ya no tiene la guarda de la prueba.');
        $this->assertNotFalse($validacion, 'run() ya no valida el payload de la prueba.');
        $this->assertLessThan($primer_artisan, $guarda_prueba);
        $this->assertLessThan($primer_artisan, $validacion);
    }
}
