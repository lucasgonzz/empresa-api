<?php

namespace Tests\Feature\Setup;

use App\Http\Controllers\Helpers\BorradoTotalDeBaseHelper;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * La guarda de instancia de `demo-setup` (misión guarda-demo-setup, 10/10/2026).
 *
 * EL CASO: `DemoSetupHelper::run()` arranca con `migrate:fresh` y sus dos puertas
 * (POST /api/admin-sync/demo-setup y POST /demo-setup) no piden clave ni sesión. Hasta esta fecha,
 * un POST con `{"business_type":"x"}` a la API de cualquier cliente le vaciaba la base de
 * producción. El blindaje del 5/10 (BlindajeDelUserSetupTest) cubrió `user-setup` y no esta puerta.
 *
 * Ahora, fuera de una instancia de demo (`FOR_USER=demo`, o `local`/`testing`), `run()` aplica la
 * guarda de `user-setup`: base vacía → sigue; base con datos → 409 sin tocar nada. A diferencia de
 * `user-setup`, acá el borrado autorizado (`forzar_borrado_total` + `confirmar_base_de_datos`) NO
 * alcanza: las dos puertas son públicas y el nombre de la base se deduce del subdominio.
 *
 * Para simular "una instancia de cliente" se pone `app.env = production` y `app.FOR_USER = null`
 * con `config()` dentro del test (la app se recrea en cada uno, no se arrastra).
 *
 * 🔴 NINGÚN TEST DE ESTA CLASE CORRE UN `migrate:fresh` REAL: vaciaría la base de testing del slot.
 * Misma red de seguridad que BlindajeDelUserSetupTest: `Artisan::call` va mockeado y se VERIFICA
 * que el facade sea un `MockInterface` ANTES de postear; los casos que no tienen que llegar al
 * `migrate:fresh` usan `->never()` y los que sí lo cortan con una excepción apenas lo tocan; hay un
 * renglón marcador en `migrations` y el conteo de users/articles/sales antes y después. El caso
 * "base vacía" borra con DELETE DENTRO de la transacción del test (nunca `truncate`).
 *
 * Ningún test sale a internet ni deja un servidor levantado.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group setup
 */
class GuardaDeInstanciaDelDemoSetupTest extends EmpresaTestCase
{
    /** Renglón inventado en `migrations` que un `migrate:fresh` no puede dejar en pie. */
    const MARCADOR = 'marcador_del_test_de_guarda_de_instancia_del_demo_setup';

    /** Lo que tira el `migrate:fresh` mockeado apenas lo tocan: prueba de que se llegó hasta ahí. */
    const CORTE = 'corte del test de guarda de demo-setup: se llegó al migrate:fresh';

    /** @var array Conteos de tablas de negocio antes del request, por tabla. */
    protected $conteos_antes = [];

    /**
     * Planta el marcador en `migrations` y fotografía los conteos.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('migrations')->insert([
            'migration' => self::MARCADOR,
            'batch'     => 9999,
        ]);

        $this->conteos_antes = $this->conteos();
    }

    /**
     * Suelta el candado de setup por las dudas: un candado tomado traba los tests que siguen.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        DemoSetupLockHelper::soltar(false);

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Helpers de los tests
    // ------------------------------------------------------------------------------------------

    /**
     * Pone la instalación como la de un cliente real: producción y sin marcador de demo.
     *
     * @return void
     */
    protected function como_instancia_de_cliente()
    {
        config(['app.env' => 'production', 'app.FOR_USER' => null]);

        $this->assertFalse(DemoSetupHelper::es_instancia_de_demo(), 'La simulación de cliente real no quedó puesta.');
    }

    /**
     * Cuántas filas tienen hoy las tablas que un `migrate:fresh` dejaría en cero.
     *
     * @return array<string, int>
     */
    protected function conteos()
    {
        return [
            'users'    => (int) DB::table('users')->count(),
            'articles' => (int) DB::table('articles')->count(),
            'sales'    => (int) DB::table('sales')->count(),
        ];
    }

    /**
     * Verifica que Artisan quedó mockeado. Se llama ANTES de postear: si el reemplazo no quedó
     * puesto, el setup vaciaría la base de testing y no se sigue.
     *
     * @return void
     */
    protected function verificar_que_artisan_es_un_mock()
    {
        $this->assertInstanceOf(
            MockInterface::class,
            Artisan::getFacadeRoot(),
            'SEGURO: Artisan no quedó mockeado y el setup vaciaría la base de testing. No se sigue.'
        );
    }

    /**
     * Artisan mockeado para un caso que NO tiene que llegar a ningún `Artisan::call`.
     *
     * @return void
     */
    protected function sin_migrate_fresh_jamas()
    {
        Artisan::shouldReceive('call')->never();

        $this->verificar_que_artisan_es_un_mock();
    }

    /**
     * Artisan mockeado para un caso que SÍ tiene que llegar al `migrate:fresh`: se lo espera una
     * vez y se lo corta con una excepción, así el setup real nunca sigue.
     *
     * @return void
     */
    protected function migrate_fresh_cortado()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate:fresh', ['--force' => true])
            ->andThrow(new \RuntimeException(self::CORTE));

        $this->verificar_que_artisan_es_un_mock();
    }

    /**
     * El payload que manda admin-api (lo mínimo), con lo que se le quiera sumar.
     *
     * @param  array $extra
     * @return array
     */
    protected function payload(array $extra = [])
    {
        return array_merge([
            'business_type' => 'ferreteria',
            'user_name'     => 'Demo del test de guarda',
            'company_name'  => 'Demo del test de guarda',
        ], $extra);
    }

    /**
     * Falla si la base de testing no tiene datos de negocio: los casos "con datos" no probarían
     * nada sobre una base vacía.
     *
     * @return void
     */
    protected function exigir_base_con_datos()
    {
        $this->assertNotEmpty(
            BorradoTotalDeBaseHelper::resumen_de_datos(),
            'La base de testing no tiene datos de negocio: este caso no prueba nada. Hay que sembrarla.'
        );
    }

    /**
     * Vacía las tablas de negocio DENTRO de la transacción del test (el rollback lo deshace) para
     * armar el caso "instalación de cero".
     *
     * 🔴 DELETE y nunca `truncate` (hace commit implícito: vaciaría la base de verdad). Antes se
     * comprueba que haya una transacción abierta.
     *
     * @return void
     */
    protected function vaciar_las_tablas_de_negocio()
    {
        $this->assertGreaterThanOrEqual(
            1,
            DB::transactionLevel(),
            'SEGURO: no hay una transacción abierta, el DELETE no se podría deshacer. No se sigue.'
        );

        Schema::disableForeignKeyConstraints();

        try {
            foreach (BorradoTotalDeBaseHelper::TABLAS_DE_NEGOCIO as $tabla) {
                DB::table($tabla)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->assertSame([], BorradoTotalDeBaseHelper::resumen_de_datos(), 'La base de testing tenía que quedar vacía dentro de la transacción.');
    }

    /**
     * La base quedó como estaba: el marcador sigue en `migrations` y los conteos no cambiaron.
     *
     * @return void
     */
    protected function assert_base_intacta()
    {
        $this->assertSame(
            1,
            (int) DB::table('migrations')->where('migration', self::MARCADOR)->count(),
            'Desapareció el marcador de migrations: corrió un migrate:fresh sobre la base de testing.'
        );

        $this->assertSame(
            $this->conteos_antes,
            $this->conteos(),
            'Cambió la cantidad de filas de users/articles/sales: el request rebotado tocó la base.'
        );
    }

    /**
     * Lo que se verifica de un request que llegó al `migrate:fresh` (cortado por el mock) por la
     * puerta de la API: el controller lo atrapa en su catch (\Throwable) y responde 500 con el texto.
     *
     * @param  \Illuminate\Testing\TestResponse $respuesta
     * @return void
     */
    protected function assert_llego_al_migrate_fresh($respuesta)
    {
        $respuesta->assertStatus(500);

        $this->assertStringContainsString(self::CORTE, (string) $respuesta->json('error'));
    }

    // ------------------------------------------------------------------------------------------
    // Instancia de cliente: lo que se cierra
    // ------------------------------------------------------------------------------------------

    /**
     * EL AGUJERO: el POST de siempre a la API de un cliente con datos. Antes vaciaba la base; ahora
     * 409, con el mismo cuerpo que user-setup y sin el nombre de la base.
     *
     * @test
     */
    public function en_un_cliente_con_datos_la_api_responde_409_y_no_toca_nada()
    {
        $this->exigir_base_con_datos();
        $this->como_instancia_de_cliente();
        $this->sin_migrate_fresh_jamas();

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', $this->payload());

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['base_con_datos' => true, 'en_curso' => false]);
        $this->assertNotEmpty($respuesta->json('error'));
        $this->assertContains('users', $respuesta->json('con_datos'));

        // La ruta es pública: ni el nombre de la base ni conteos en la respuesta.
        $this->assertStringNotContainsString(BorradoTotalDeBaseHelper::nombre_de_la_base(), $respuesta->getContent());

        $this->assert_base_intacta();
    }

    /**
     * La segunda puerta, el formulario web: vuelve al form con el mensaje y no toca nada.
     *
     * @test
     */
    public function en_un_cliente_con_datos_el_form_web_vuelve_con_el_mensaje_y_no_toca_nada()
    {
        $this->exigir_base_con_datos();
        $this->como_instancia_de_cliente();
        $this->sin_migrate_fresh_jamas();

        $respuesta = $this->post('/demo-setup', $this->payload());

        $respuesta->assertRedirect(route('demo.form'));
        $respuesta->assertSessionHas('status');
        $this->assertStringContainsString('ya tiene datos de negocio', (string) session('status'));
        $this->assertStringNotContainsString(BorradoTotalDeBaseHelper::nombre_de_la_base(), (string) session('status'));

        $this->assert_base_intacta();
    }

    /**
     * El flag solo, sin el nombre de la base, no alcanza (mismo contrato que user-setup).
     *
     * @test
     */
    public function en_un_cliente_el_flag_sin_la_confirmacion_no_alcanza()
    {
        $this->exigir_base_con_datos();
        $this->como_instancia_de_cliente();
        $this->sin_migrate_fresh_jamas();

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', $this->payload([
            BorradoTotalDeBaseHelper::FLAG => true,
        ]));

        $respuesta->assertStatus(409);
        $this->assert_base_intacta();
    }

    // ------------------------------------------------------------------------------------------
    // Lo que tiene que seguir andando
    // ------------------------------------------------------------------------------------------

    /**
     * Instalación de cero (base vacía) fuera de una demo: sigue como siempre. Es lo que hace la
     * etapa 8 de DemoInstallationService de admin-api sobre una demo nueva, aunque le faltara el
     * marcador.
     *
     * @test
     */
    public function en_un_cliente_con_la_base_vacia_sigue_hasta_el_migrate_fresh()
    {
        $this->como_instancia_de_cliente();
        $this->vaciar_las_tablas_de_negocio();
        $this->migrate_fresh_cortado();

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', $this->payload());

        $this->assert_llego_al_migrate_fresh($respuesta);
    }

    /**
     * 🔴 A DIFERENCIA DE USER-SETUP: el flag con el nombre EXACTO de la base tampoco alcanza, por la
     * API. Esta puerta no tiene clave y el nombre de la base se deduce del subdominio: aceptarlo
     * sería el mismo agujero con un paso más.
     *
     * @test
     */
    public function en_un_cliente_ni_el_borrado_autorizado_por_la_api_vacia_la_base()
    {
        $this->exigir_base_con_datos();
        $this->como_instancia_de_cliente();
        $this->sin_migrate_fresh_jamas();

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', $this->payload([
            BorradoTotalDeBaseHelper::FLAG         => true,
            BorradoTotalDeBaseHelper::CONFIRMACION => BorradoTotalDeBaseHelper::nombre_de_la_base(),
        ]));

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['base_con_datos' => true, 'en_curso' => false]);
        $this->assert_base_intacta();
    }

    /**
     * Lo mismo por el formulario web.
     *
     * @test
     */
    public function en_un_cliente_ni_el_borrado_autorizado_por_el_form_web_vacia_la_base()
    {
        $this->exigir_base_con_datos();
        $this->como_instancia_de_cliente();
        $this->sin_migrate_fresh_jamas();

        $respuesta = $this->post('/demo-setup', $this->payload([
            BorradoTotalDeBaseHelper::FLAG         => '1',
            BorradoTotalDeBaseHelper::CONFIRMACION => BorradoTotalDeBaseHelper::nombre_de_la_base(),
        ]));

        $respuesta->assertRedirect(route('demo.form'));
        $this->assertStringContainsString('ya tiene datos de negocio', (string) session('status'));
        $this->assert_base_intacta();
    }

    /**
     * El rechazo suelta el candado (lo hace el `finally`): un segundo POST tiene que volver a pasar
     * por la guarda (409 de base con datos), no rebotar con el 409 del candado (`en_curso: true`).
     *
     * @test
     */
    public function despues_de_un_rechazo_el_candado_queda_libre()
    {
        $this->exigir_base_con_datos();
        $this->como_instancia_de_cliente();
        $this->sin_migrate_fresh_jamas();

        $primero = $this->postJson('/api/admin-sync/demo-setup', $this->payload());
        $segundo = $this->postJson('/api/admin-sync/demo-setup', $this->payload());

        $primero->assertStatus(409)->assertJson(['en_curso' => false]);
        $segundo->assertStatus(409)->assertJson(['en_curso' => false, 'base_con_datos' => true]);
        $this->assert_base_intacta();
    }

    /**
     * LA DEMO DE PRODUCCIÓN: `APP_ENV=production` con `FOR_USER=demo` y la base llena de la demo
     * anterior. El rearmado de cada lead tiene que seguir pasando sin preguntar nada.
     *
     * @test
     */
    public function en_una_demo_con_datos_el_rearmado_sigue_hasta_el_migrate_fresh()
    {
        $this->exigir_base_con_datos();
        config(['app.env' => 'production', 'app.FOR_USER' => 'demo']);
        $this->migrate_fresh_cortado();

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', $this->payload());

        $this->assert_llego_al_migrate_fresh($respuesta);
    }

    /**
     * El formulario web en una demo también sigue hasta el `migrate:fresh` (el controller web no
     * atrapa el corte del mock: sale como 500 del handler, que es lo esperable acá).
     *
     * @test
     */
    public function en_una_demo_el_form_web_sigue_hasta_el_migrate_fresh()
    {
        $this->exigir_base_con_datos();
        config(['app.env' => 'production', 'app.FOR_USER' => 'demo']);
        $this->migrate_fresh_cortado();

        $respuesta = $this->post('/demo-setup', $this->payload());

        $respuesta->assertStatus(500);
    }

    /**
     * La máquina de Lucas (`APP_ENV=local`) se comporta como una demo, igual que en `semilla:datos`.
     *
     * @test
     */
    public function en_local_con_datos_sigue_hasta_el_migrate_fresh()
    {
        $this->exigir_base_con_datos();
        config(['app.env' => 'local', 'app.FOR_USER' => null]);
        $this->migrate_fresh_cortado();

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', $this->payload());

        $this->assert_llego_al_migrate_fresh($respuesta);
    }

    /**
     * El marcador se compara exacto: `FOR_USER` de otro cliente (los hay: hipermax, feito) no es demo.
     *
     * @test
     */
    public function un_for_user_que_no_es_demo_no_cuenta_como_demo()
    {
        config(['app.env' => 'production', 'app.FOR_USER' => 'hipermax']);
        $this->assertFalse(DemoSetupHelper::es_instancia_de_demo());

        config(['app.FOR_USER' => 'Demo']);
        $this->assertFalse(DemoSetupHelper::es_instancia_de_demo(), 'El marcador es exacto, como en semilla:datos.');

        config(['app.FOR_USER' => 'demo']);
        $this->assertTrue(DemoSetupHelper::es_instancia_de_demo());
    }

    // ------------------------------------------------------------------------------------------
    // El orden dentro de run()
    // ------------------------------------------------------------------------------------------

    /**
     * 🔴 EL ORDEN DENTRO DE `run()` ES TODO EL ARREGLO y ninguna otra aserción lo mira: los demás
     * tests mockean Artisan, así que seguirían en verde si alguien moviera la guarda después del
     * `migrate:fresh`. Se lee el fuente de `run()` por reflexión: la primera sentencia es la guarda,
     * y viene antes de cualquier `Artisan::call`.
     *
     * @test
     */
    public function la_guarda_es_la_primera_sentencia_de_run_y_viene_antes_del_migrate_fresh()
    {
        $run    = new ReflectionMethod(DemoSetupHelper::class, 'run');
        $lineas = file($run->getFileName());
        $fuente = implode('', array_slice($lineas, $run->getStartLine() - 1, $run->getEndLine() - $run->getStartLine() + 1));

        $guarda         = strpos($fuente, 'BorradoTotalDeBaseHelper::exigir_base_sin_datos_o_autorizacion(');
        $fresh          = strpos($fuente, "Artisan::call('migrate:fresh'");
        $primer_artisan = strpos($fuente, 'Artisan::call(');

        $this->assertNotFalse($guarda, 'run() ya no llama a la guarda de borrado total.');
        $this->assertNotFalse($fresh, 'run() ya no tiene el migrate:fresh: este test tiene que revisarse.');
        $this->assertLessThan($fresh, $guarda, 'La guarda tiene que estar ANTES del migrate:fresh.');
        $this->assertLessThan($primer_artisan, $guarda, 'La guarda tiene que estar antes de cualquier Artisan::call.');

        // La primera sentencia del cuerpo, sin comentarios ni espacios, es exactamente el if de la guarda.
        $cuerpo = substr($fuente, strpos($fuente, '{') + 1);
        $codigo = '';

        foreach (token_get_all('<?php ' . $cuerpo) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $codigo .= is_array($token) ? $token[1] : $token;

            if (strlen($codigo) >= 200) {
                break;
            }
        }

        // Con un payload VACÍO: si alguien le vuelve a pasar `$data`, el borrado autorizado reabre el agujero.
        $esperado = 'if(!self::es_instancia_de_demo()){BorradoTotalDeBaseHelper::exigir_base_sin_datos_o_autorizacion([]);}';

        $this->assertSame($esperado, substr($codigo, 0, strlen($esperado)), 'La primera sentencia de run() tiene que ser la guarda de instancia.');
    }
}
