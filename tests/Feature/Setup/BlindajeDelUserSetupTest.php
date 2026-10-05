<?php

namespace Tests\Feature\Setup;

use App\Exceptions\BaseConDatosException;
use App\Http\Controllers\Helpers\BorradoTotalDeBaseHelper;
use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * El blindaje de `admin-sync/user-setup` (misión blindar-user-setup, 5/10/2026).
 *
 * EL CASO REAL: `UserSetupHelper::run()` arranca con `migrate:fresh` y la ruta
 * POST /api/admin-sync/user-setup no pedía ninguna clave. El 5/10/2026 un test de admin-api que
 * apuntaba a la URL de Panchito hizo un POST real y le vació la base de producción (102.754 ventas,
 * 6.952 artículos). Ahora `run()` se niega (409, sin tocar nada) si la base ya tiene datos de
 * negocio, salvo que el payload traiga `forzar_borrado_total` Y `confirmar_base_de_datos` con el
 * nombre exacto de la base; y hay un middleware de clave de admin detrás de una variable apagada
 * por defecto.
 *
 * 🔴 NINGÚN TEST DE ESTA CLASE CORRE UN `migrate:fresh` REAL: vaciaría la base de testing del slot.
 * `Artisan::call` va mockeado y se VERIFICA que el facade sea un `MockInterface` ANTES de postear
 * (misma red de seguridad que SecretosEnErroresDelSetupTest). Los casos que tienen que NO llegar al
 * `migrate:fresh` usan `->never()`; los que tienen que llegar lo cortan con una excepción apenas
 * lo tocan. Además hay un renglón marcador en `migrations` (lo que un `migrate:fresh` no puede dejar
 * en pie, mismo recurso que CandadoDeSetupTest) y el conteo de users/articles/sales antes y después.
 *
 * Para el caso "base vacía" se vacían las tablas de negocio con DELETE DENTRO de la transacción del
 * test (DatabaseTransactions, vía EmpresaTestCase): el rollback lo deshace. Nunca `truncate`, que
 * hace commit implícito y vaciaría la base de verdad. Antes de borrar se comprueba que haya una
 * transacción abierta.
 *
 * Ningún test sale a internet ni deja un servidor levantado.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group setup
 */
class BlindajeDelUserSetupTest extends EmpresaTestCase
{
    /** Renglón inventado en `migrations` que un `migrate:fresh` no puede dejar en pie. */
    const MARCADOR = 'marcador_del_test_de_blindaje_del_user_setup';

    /** Lo que tira el `migrate:fresh` mockeado apenas lo tocan: prueba de que se llegó hasta ahí. */
    const CORTE = 'corte del test de blindaje: se llegó al migrate:fresh';

    /** Id de dueño que la base de testing no usa. */
    const ID_DE_USUARIO_LIBRE = 987654;

    /** La clave de admin "de la instancia" para los casos del middleware. */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-0123456789';

    /** @var array Las líneas de log del test: [nivel, texto (mensaje + contexto)]. */
    protected $logs = [];

    /** @var array Conteos de tablas de negocio antes del request, por tabla. */
    protected $conteos_antes = [];

    /** @var resource|false Handle del candado cuando el test lo toma a mano; se suelta en tearDown. */
    protected $candado = false;

    /**
     * Planta el marcador en `migrations`, fotografía los conteos y engancha el listener que junta las líneas de log.
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

        $this->logs = [];

        $test = $this;

        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($test) {
            $test->logs[] = [
                'nivel' => (string) $evento->level,
                'texto' => (string) $evento->message . ' '
                    . (string) json_encode((array) $evento->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ];
        });
    }

    /**
     * Suelta el candado si el test lo dejó tomado, para que no trabe a los que siguen.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Un candado que queda tomado por un test traba los que siguen.
        DemoSetupLockHelper::soltar($this->candado);
        $this->candado = false;

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Helpers de los tests
    // ------------------------------------------------------------------------------------------

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
     * Artisan mockeado para un caso que NO tiene que llegar al `migrate:fresh` (ni a ningún
     * Artisan::call): si lo toca, el mock revienta.
     *
     * @return void
     */
    protected function sin_migrate_fresh_jamas()
    {
        Artisan::shouldReceive('call')->never();

        $this->verificar_que_artisan_es_un_mock();
    }

    /**
     * Artisan mockeado para un caso que SÍ tiene que llegar al `migrate:fresh`: se lo espera
     * `$veces` veces y se lo corta con una excepción, así el setup real nunca sigue.
     *
     * @param  int $veces
     * @return void
     */
    protected function migrate_fresh_cortado($veces = 1)
    {
        Artisan::shouldReceive('call')
            ->times($veces)
            ->with('migrate:fresh', ['--force' => true])
            ->andThrow(new \RuntimeException(self::CORTE));

        $this->verificar_que_artisan_es_un_mock();
    }

    /**
     * El payload de siempre de admin-api (sin los campos nuevos), con lo que se le quiera sumar.
     *
     * @param  array $extra
     * @return array
     */
    protected function payload(array $extra = [])
    {
        return array_merge([
            'business_type' => 'ferreteria',
            'user_id'       => self::ID_DE_USUARIO_LIBRE,
            'user_name'     => 'Cliente del test de blindaje',
            'company_name'  => 'Cliente del test de blindaje',
            'email'         => 'blindaje-' . uniqid() . '@test.local',
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
     * armar el caso "base recién instalada y sin ningún dato".
     *
     * 🔴 DELETE y nunca `truncate` (hace commit implícito: vaciaría la base de verdad). Antes se
     * comprueba que haya una transacción abierta, y se apagan las FK solo para poder borrar en
     * cualquier orden (es una variable de sesión: no cierra la transacción).
     *
     * Con `$excepto` se vacían TODAS LAS DEMÁS (para probar una tabla por vez): en ese caso no se
     * afirma nada sobre el estado final, lo afirma el que llama.
     *
     * @param  string[] $excepto Tablas de la lista que NO se vacían.
     * @return void
     */
    protected function vaciar_las_tablas_de_negocio(array $excepto = [])
    {
        $this->assertGreaterThanOrEqual(
            1,
            DB::transactionLevel(),
            'SEGURO: no hay una transacción abierta, el DELETE no se podría deshacer. No se sigue.'
        );

        Schema::disableForeignKeyConstraints();

        try {
            foreach (BorradoTotalDeBaseHelper::TABLAS_DE_NEGOCIO as $tabla) {
                if (in_array($tabla, $excepto, true)) {
                    continue;
                }

                DB::table($tabla)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        if (empty($excepto)) {
            $this->assertSame([], BorradoTotalDeBaseHelper::resumen_de_datos(), 'La base de testing tenía que quedar vacía dentro de la transacción.');
        }
    }

    /**
     * Lo que se verifica de TODO 409 por base con datos.
     *
     * @param  \Illuminate\Testing\TestResponse $respuesta
     * @param  string|null                      $exactamente Si se pasa, `con_datos` tiene que ser
     *                                                       EXACTAMENTE esa tabla y ninguna otra.
     * @return void
     */
    protected function assert_409_por_base_con_datos($respuesta, $exactamente = null)
    {
        $respuesta->assertStatus(409);
        $respuesta->assertJson(['base_con_datos' => true, 'en_curso' => false]);

        $this->assertNotEmpty($respuesta->json('error'), 'El 409 tiene que traer un mensaje para mostrar.');

        $con_datos = $respuesta->json('con_datos');

        $this->assertIsArray($con_datos);

        if ($exactamente === null) {
            $this->assertContains('users', $con_datos, 'La base de testing tiene usuarios: tiene que figurar.');
        } else {
            $this->assertSame([$exactamente], $con_datos, 'Con solo esa tabla con datos, es la única que tiene que figurar.');
        }

        // Una lista de nombres de tabla, sin conteos (nada de tabla => filas).
        $this->assertSame(array_values($con_datos), $con_datos, 'con_datos es una lista, no un mapa con conteos.');

        foreach ($con_datos as $tabla) {
            $this->assertIsString($tabla);
        }

        // La ruta es pública hasta que se prenda la clave: ni el nombre de la base ni conteos.
        $this->assertStringNotContainsString(
            BorradoTotalDeBaseHelper::nombre_de_la_base(),
            $respuesta->getContent(),
            'El 409 le cuenta a cualquiera cómo se llama la base.'
        );
    }

    /**
     * La base quedó como estaba: el marcador sigue en `migrations` y los conteos no cambiaron.
     * Un `migrate:fresh` dropea y repuebla `migrations` y `users` desde cero: ninguna de las dos
     * cosas sobrevive.
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
     * Las líneas de log (de cualquier nivel) cuyo texto contiene todos los fragmentos.
     *
     * @param  string[] $fragmentos
     * @return array
     */
    protected function logs_que_contienen(array $fragmentos)
    {
        return array_values(array_filter($this->logs, function ($log) use ($fragmentos) {
            foreach ($fragmentos as $fragmento) {
                if (strpos($log['texto'], $fragmento) === false) {
                    return false;
                }
            }

            return true;
        }));
    }

    // ------------------------------------------------------------------------------------------
    // 1 a 4: base con datos, lo que NO alcanza para vaciarla
    // ------------------------------------------------------------------------------------------

    /**
     * El payload de siempre (el que manda admin-api hoy) sobre una base con datos: 409 y no toca nada.
     * Es EXACTAMENTE lo que le pasó a Panchito, con el resultado contrario.
     *
     * @test
     */
    public function una_base_con_datos_y_el_payload_de_siempre_responde_409_y_no_toca_nada()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload());

        $this->assert_409_por_base_con_datos($respuesta);
        $this->assert_base_intacta();

        // El detalle (con el nombre de la base y los conteos) va al log del servidor, no a la respuesta.
        $rechazos = $this->logs_que_contienen(['se rechazó un setup', BorradoTotalDeBaseHelper::nombre_de_la_base(), 'con_datos']);

        $this->assertNotEmpty($rechazos, 'El rechazo tiene que quedar en el log con la base y los conteos.');
        $this->assertSame('warning', $rechazos[0]['nivel']);
    }

    /**
     * El flag solo, sin la confirmación del nombre de la base, no alcanza.
     *
     * @test
     */
    public function el_flag_sin_la_confirmacion_no_alcanza()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload([
            BorradoTotalDeBaseHelper::FLAG => true,
        ]));

        $this->assert_409_por_base_con_datos($respuesta);
        $this->assert_base_intacta();
    }

    /**
     * El flag con una confirmación que NO es el nombre exacto de la base no alcanza: otra base, la
     * misma en otra capitalización, con espacios alrededor, vacía, o con un tipo que no es string.
     *
     * @test
     */
    public function el_flag_con_la_confirmacion_equivocada_no_alcanza()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $base = BorradoTotalDeBaseHelper::nombre_de_la_base();

        // Ojo: por HTTP no se prueba "el nombre con un espacio de más" porque el middleware global
        // TrimStrings se lo saca antes de que llegue al helper (y entonces es el nombre exacto). Ese
        // caso, con el dato crudo, está en esta_autorizado_acepta_solo_el_flag_valido_con_la_confirmacion_exacta.
        $equivocadas = [
            'otra base'              => 'empresa_otra_base_cualquiera',
            'otra capitalización'    => strtoupper($base),
            'con un carácter de más' => $base . '_',
            'solo un pedazo'         => substr($base, 0, -1),
            'vacía'                  => '',
            'null'                   => null,
            'un array con el nombre' => [$base],
            'un número'              => 12345,
        ];

        foreach ($equivocadas as $caso => $confirmacion) {
            $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload([
                BorradoTotalDeBaseHelper::FLAG         => true,
                BorradoTotalDeBaseHelper::CONFIRMACION => $confirmacion,
            ]));

            $this->assertSame(409, $respuesta->getStatusCode(), 'Confirmación equivocada (' . $caso . '): tenía que rebotar.');
            $this->assert_409_por_base_con_datos($respuesta);
        }

        $this->assert_base_intacta();
    }

    /**
     * Un flag falso o ambiguo no alcanza aunque la confirmación sea la correcta.
     *
     * @test
     */
    public function un_flag_falso_o_ambiguo_no_alcanza_aunque_la_confirmacion_sea_correcta()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $base = BorradoTotalDeBaseHelper::nombre_de_la_base();

        $ambiguos = [
            'false'        => false,
            "'false'"      => 'false',
            '0'            => 0,
            "'0'"          => '0',
            "''"           => '',
            "'si'"         => 'si',
            "'TRUE'"       => 'TRUE',
            'null'         => null,
            'un array'     => [true],
        ];

        foreach ($ambiguos as $caso => $flag) {
            $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload([
                BorradoTotalDeBaseHelper::FLAG         => $flag,
                BorradoTotalDeBaseHelper::CONFIRMACION => $base,
            ]));

            $this->assertSame(409, $respuesta->getStatusCode(), 'Flag ' . $caso . ': tenía que rebotar.');
            $this->assert_409_por_base_con_datos($respuesta);
        }

        $this->assert_base_intacta();
    }

    // ------------------------------------------------------------------------------------------
    // 5 y 6: los dos casos en que SÍ se llega al migrate:fresh
    // ------------------------------------------------------------------------------------------

    /**
     * Con datos + el flag + la confirmación correcta SÍ se llega al `migrate:fresh` (acá cortado
     * por el mock, que lo espera una vez por cada valor aceptado del flag), y el borrado total
     * autorizado deja su rastro en el log.
     *
     * @test
     */
    public function con_el_flag_y_la_confirmacion_correcta_llega_al_migrate_fresh_y_deja_rastro_en_el_log()
    {
        $this->exigir_base_con_datos();

        $valores_del_flag = [true, 1, '1', 'true'];

        $this->migrate_fresh_cortado(count($valores_del_flag));

        $base = BorradoTotalDeBaseHelper::nombre_de_la_base();

        foreach ($valores_del_flag as $flag) {
            $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload([
                BorradoTotalDeBaseHelper::FLAG         => $flag,
                BorradoTotalDeBaseHelper::CONFIRMACION => $base,
            ]));

            // NO es el 409: la guarda lo dejó pasar y llegó al migrate:fresh, que el mock cortó con
            // una excepción (el controlador la contesta como 500 `internal error:`).
            $this->assertSame(
                500,
                $respuesta->getStatusCode(),
                'Flag ' . var_export($flag, true) . ' + confirmación correcta: tenía que llegar al migrate:fresh.'
            );
            $this->assertStringStartsWith('internal error: ', (string) $respuesta->json('error'));
            $this->assertStringContainsString(self::CORTE, (string) $respuesta->json('error'));
            $this->assertNull($respuesta->json('base_con_datos'));
        }

        $this->assert_base_intacta();

        // El borrado total autorizado queda registrado, con la base y lo que había adentro.
        $autorizados = $this->logs_que_contienen(['AUTORIZADO', $base, 'con_datos']);

        $this->assertCount(count($valores_del_flag), $autorizados, 'Cada borrado autorizado tiene que dejar su línea de log.');
        $this->assertSame('warning', $autorizados[0]['nivel']);
    }

    /**
     * Base VACÍA: llega al `migrate:fresh` con el payload de siempre, sin ningún parámetro nuevo.
     * Es "sigue funcionando como hoy" para el alta de clientes de /instalar-cliente y /implementar.
     *
     * @test
     */
    public function una_base_vacia_llega_al_migrate_fresh_sin_ningun_parametro()
    {
        $this->vaciar_las_tablas_de_negocio();
        $this->migrate_fresh_cortado();

        $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload());

        $this->assertSame(500, $respuesta->getStatusCode(), 'Una base vacía tenía que llegar al migrate:fresh (no 409).');
        $this->assertStringContainsString(self::CORTE, (string) $respuesta->json('error'));
        $this->assertNull($respuesta->json('base_con_datos'));

        // Una base vacía no es noticia: ni línea de rechazo ni de borrado autorizado.
        $this->assertSame([], $this->logs_que_contienen(['BorradoTotalDeBaseHelper']));
    }

    // ------------------------------------------------------------------------------------------
    // 7: el candado
    // ------------------------------------------------------------------------------------------

    /**
     * El candado sigue mandando: con el candado tomado, el 409 es el del candado (`en_curso: true`)
     * y no el de la base con datos, aunque la base tenga datos.
     *
     * @test
     */
    public function con_el_candado_tomado_el_409_es_el_del_candado_y_no_el_de_la_base_con_datos()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $this->candado = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($this->candado, 'El candado tenía que estar libre al empezar el test.');

        $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload());

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['en_curso' => true]);
        $respuesta->assertJsonMissing(['base_con_datos' => true]);

        $this->assert_base_intacta();
    }

    /**
     * El candado se suelta después de un 409 por base con datos (el `finally` del controlador):
     * un segundo POST vuelve a rebotar POR DATOS, no por `en_curso`, y el candado queda libre.
     *
     * @test
     */
    public function el_candado_se_suelta_despues_de_un_409_por_base_con_datos()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $primero = $this->postJson('/api/admin-sync/user-setup', $this->payload());
        $this->assert_409_por_base_con_datos($primero);

        $segundo = $this->postJson('/api/admin-sync/user-setup', $this->payload());
        $this->assert_409_por_base_con_datos($segundo);
        $segundo->assertJson(['en_curso' => false]);

        $libre = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($libre, 'El candado quedó tomado después de un 409 por base con datos.');
        DemoSetupLockHelper::soltar($libre);

        $this->assert_base_intacta();
    }

    // ------------------------------------------------------------------------------------------
    // 8: la puerta web
    // ------------------------------------------------------------------------------------------

    /**
     * La cuarta puerta, el formulario web POST /user-setup, sobre una base con datos: no llama a
     * Artisan y vuelve al formulario con el motivo (sin el nombre de la base).
     *
     * @test
     */
    public function la_puerta_web_se_niega_sobre_una_base_con_datos_y_vuelve_al_formulario_con_el_motivo()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $respuesta = $this->post('/user-setup', [
            'business_type' => 'distribuidora',
            'user_id'       => self::ID_DE_USUARIO_LIBRE,
        ]);

        $respuesta->assertRedirect(route('user.form'));
        $respuesta->assertSessionHas('error');
        $respuesta->assertSessionMissing('status');

        $mensaje = (string) session('error');

        $this->assertStringContainsString('ya tiene datos de negocio', $mensaje);
        $this->assertStringContainsString('users', $mensaje, 'El mensaje dice qué tablas tienen datos.');
        $this->assertStringNotContainsString(BorradoTotalDeBaseHelper::nombre_de_la_base(), $mensaje);

        $this->assert_base_intacta();
    }

    /**
     * 🔴 La puerta web NO se puede forzar: aunque el POST traiga el flag y el nombre EXACTO de la
     * base (que es lo que autoriza el borrado total por la API), el controlador los saca antes de
     * llegar a `run()` y la guarda se niega igual. Es la cuarta puerta, pública (el formulario es
     * un GET abierto y el CSRF lo saca cualquiera). Confirmado en vivo en la verificación de la
     * misión: sin este filtro, un POST web con los dos campos vaciaba la base.
     *
     * @test
     */
    public function la_puerta_web_se_niega_aunque_el_post_traiga_el_flag_y_la_confirmacion_correctas()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $respuesta = $this->post('/user-setup', [
            'business_type'                        => 'distribuidora',
            'user_id'                              => self::ID_DE_USUARIO_LIBRE,
            BorradoTotalDeBaseHelper::FLAG         => true,
            BorradoTotalDeBaseHelper::CONFIRMACION => BorradoTotalDeBaseHelper::nombre_de_la_base(),
        ]);

        $respuesta->assertRedirect(route('user.form'));
        $respuesta->assertSessionHas('error');
        $respuesta->assertSessionMissing('status');

        $this->assertStringContainsString('ya tiene datos de negocio', (string) session('error'));

        $this->assert_base_intacta();
    }

    /**
     * La misma puerta web sobre una base vacía llega al `migrate:fresh`, como siempre (acá cortado
     * por el mock).
     *
     * @test
     */
    public function la_puerta_web_sobre_una_base_vacia_llega_al_migrate_fresh()
    {
        $this->vaciar_las_tablas_de_negocio();
        $this->migrate_fresh_cortado();

        // Sin el manejo de excepciones de Laravel, la excepción del mock sale tal cual (no un 500 genérico).
        $this->withoutExceptionHandling();

        $llego = false;

        try {
            $this->post('/user-setup', [
                'business_type' => 'distribuidora',
                'user_id'       => self::ID_DE_USUARIO_LIBRE,
            ]);
        } catch (\RuntimeException $e) {
            $llego = ($e->getMessage() === self::CORTE);
        }

        $this->assertTrue($llego, 'La puerta web sobre una base vacía tenía que llegar al migrate:fresh.');

        // Aunque el setup reventó a mitad de camino, el `finally` soltó el candado.
        $libre = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($libre, 'La puerta web dejó el candado tomado después de reventar.');
        DemoSetupLockHelper::soltar($libre);
    }

    /**
     * La cuarta puerta TOMA el candado, como las otras tres: con el candado tomado (otro setup en
     * pleno `migrate:fresh`) el POST web vuelve al formulario con el aviso y no llama a Artisan.
     * Sin esto, ese POST veía las tablas ausentes, pasaba la guarda de datos y le pisaba la corrida.
     *
     * @test
     */
    public function con_el_candado_tomado_la_puerta_web_vuelve_al_formulario_con_el_aviso_y_no_toca_nada()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $this->candado = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($this->candado, 'El candado tenía que estar libre al empezar el test.');

        $respuesta = $this->post('/user-setup', [
            'business_type' => 'distribuidora',
            'user_id'       => self::ID_DE_USUARIO_LIBRE,
        ]);

        $respuesta->assertRedirect(route('user.form'));
        $respuesta->assertSessionHas('error');
        $respuesta->assertSessionMissing('status');

        $mensaje = (string) session('error');

        $this->assertStringContainsString('setup corriendo', $mensaje);
        // Es el aviso del candado, no el de la base con datos (ese caso no llegó ni a evaluarse).
        $this->assertStringNotContainsString('datos de negocio', $mensaje);

        $this->assert_base_intacta();
    }

    /**
     * El candado se suelta después de un rechazo por base con datos en la puerta web: un segundo
     * POST vuelve a rebotar POR DATOS (no por el candado), y el candado queda libre.
     *
     * @test
     */
    public function el_candado_se_suelta_despues_de_un_rechazo_por_datos_en_la_puerta_web()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        foreach ([1, 2] as $intento) {
            $respuesta = $this->post('/user-setup', [
                'business_type' => 'distribuidora',
                'user_id'       => self::ID_DE_USUARIO_LIBRE,
            ]);

            $respuesta->assertRedirect(route('user.form'));
            $respuesta->assertSessionHas('error');

            $this->assertStringContainsString(
                'datos de negocio',
                (string) session('error'),
                'Intento ' . $intento . ': tenía que rebotar por la base con datos, no por el candado.'
            );
        }

        $libre = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($libre, 'El candado quedó tomado después de un rechazo por datos en la puerta web.');
        DemoSetupLockHelper::soltar($libre);

        $this->assert_base_intacta();
    }

    // ------------------------------------------------------------------------------------------
    // 9: unitarios del helper
    // ------------------------------------------------------------------------------------------

    /**
     * Tabla de valores de `esta_autorizado()`: el flag es true/1/'1'/'true' Y la confirmación es el
     * nombre exacto de la base (sensible a mayúsculas). Todo lo demás, no.
     *
     * @test
     */
    public function esta_autorizado_acepta_solo_el_flag_valido_con_la_confirmacion_exacta()
    {
        $base = BorradoTotalDeBaseHelper::nombre_de_la_base();

        $flag     = BorradoTotalDeBaseHelper::FLAG;
        $confirma = BorradoTotalDeBaseHelper::CONFIRMACION;

        $autorizados = [
            'true + base'   => [$flag => true,   $confirma => $base],
            '1 + base'      => [$flag => 1,      $confirma => $base],
            "'1' + base"    => [$flag => '1',    $confirma => $base],
            "'true' + base" => [$flag => 'true', $confirma => $base],
        ];

        foreach ($autorizados as $caso => $data) {
            $this->assertTrue(BorradoTotalDeBaseHelper::esta_autorizado($data), 'Tenía que autorizar: ' . $caso);
        }

        $no_autorizados = [
            'payload vacío'              => [],
            'solo el flag'               => [$flag => true],
            'solo la confirmación'       => [$confirma => $base],
            'flag false'                 => [$flag => false,   $confirma => $base],
            "flag 'false'"               => [$flag => 'false', $confirma => $base],
            'flag 0'                     => [$flag => 0,       $confirma => $base],
            "flag '0'"                   => [$flag => '0',     $confirma => $base],
            "flag ''"                    => [$flag => '',      $confirma => $base],
            "flag 'si'"                  => [$flag => 'si',    $confirma => $base],
            "flag 'yes'"                 => [$flag => 'yes',   $confirma => $base],
            "flag 'TRUE'"                => [$flag => 'TRUE',  $confirma => $base],
            'flag 2'                     => [$flag => 2,       $confirma => $base],
            'flag null'                  => [$flag => null,    $confirma => $base],
            'flag array'                 => [$flag => [true],  $confirma => $base],
            'confirmación equivocada'    => [$flag => true, $confirma => $base . '_x'],
            'confirmación en mayúsculas' => [$flag => true, $confirma => strtoupper($base)],
            'confirmación con espacio'   => [$flag => true, $confirma => ' ' . $base],
            'confirmación con espacio al final' => [$flag => true, $confirma => $base . ' '],
            'confirmación vacía'         => [$flag => true, $confirma => ''],
            'confirmación null'          => [$flag => true, $confirma => null],
            'confirmación array'         => [$flag => true, $confirma => [$base]],
            'confirmación número'        => [$flag => true, $confirma => 1],
        ];

        foreach ($no_autorizados as $caso => $data) {
            $this->assertFalse(BorradoTotalDeBaseHelper::esta_autorizado($data), 'NO tenía que autorizar: ' . $caso);
        }
    }

    /**
     * Sobre la base de testing, la guarda tira la excepción con los datos para el log y un mensaje
     * genérico (sin el nombre de la base), y con autorización o con la base vacía no tira nada.
     *
     * @test
     */
    public function la_guarda_tira_la_excepcion_con_los_datos_y_un_mensaje_generico()
    {
        $this->exigir_base_con_datos();

        $base = BorradoTotalDeBaseHelper::nombre_de_la_base();

        try {
            BorradoTotalDeBaseHelper::exigir_base_sin_datos_o_autorizacion([]);
            $this->fail('Una base con datos y sin autorización tenía que tirar BaseConDatosException.');
        } catch (BaseConDatosException $e) {
            $this->assertSame($base, $e->base());
            $this->assertArrayHasKey('users', $e->resumen());
            $this->assertGreaterThanOrEqual(1, $e->resumen()['users']);
            $this->assertSame(array_keys($e->resumen()), $e->con_datos());
            $this->assertStringNotContainsString($base, $e->getMessage(), 'El mensaje viaja en la respuesta: sin el nombre de la base.');
        }

        // Autorizada: no tira (y no toca nada: solo mira).
        BorradoTotalDeBaseHelper::exigir_base_sin_datos_o_autorizacion([
            BorradoTotalDeBaseHelper::FLAG         => true,
            BorradoTotalDeBaseHelper::CONFIRMACION => $base,
        ]);

        $this->assert_base_intacta();
    }

    /**
     * Una tabla que no existe (base sin migrar) cuenta como vacía y no tira error; y con una tabla
     * de negocio que sí existe y tiene una fila, solo esa aparece. Se prueba contra una base SQLite
     * en memoria, descartable, puesta como conexión por defecto solo mientras dura el caso.
     *
     * @test
     */
    public function una_tabla_que_no_existe_cuenta_como_vacia()
    {
        $original = DB::getDefaultConnection();

        config(['database.connections.blindaje_vacia' => [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => false,
        ]]);

        try {
            DB::setDefaultConnection('blindaje_vacia');

            // Base sin migrar: ninguna tabla existe.
            $this->assertSame([], BorradoTotalDeBaseHelper::resumen_de_datos());

            // Y entonces la guarda deja pasar (es el "instalar de cero" de siempre).
            BorradoTotalDeBaseHelper::exigir_base_sin_datos_o_autorizacion([]);

            // Con una sola tabla de negocio creada y con una fila: solo esa aparece, las demás se saltean.
            $conexion = DB::connection('blindaje_vacia');
            $conexion->getSchemaBuilder()->create('users', function ($tabla) {
                $tabla->increments('id');
            });

            $this->assertSame([], BorradoTotalDeBaseHelper::resumen_de_datos(), 'Una tabla que existe pero está vacía no cuenta.');

            $conexion->table('users')->insert(['id' => 1]);

            $this->assertSame(['users' => 1], BorradoTotalDeBaseHelper::resumen_de_datos());
        } finally {
            // Antes de que corra el rollback de DatabaseTransactions: si no, rollbackearía la conexión equivocada.
            DB::setDefaultConnection($original);
            DB::purge('blindaje_vacia');
        }

        $this->assertSame($original, DB::getDefaultConnection());
    }

    /**
     * Un error real de conexión NO se traga: se propaga. Tragárselo y contestar "base vacía" sería
     * el falso permiso que la guarda existe para evitar. (Conexión a un puerto cerrado de esta
     * misma máquina: falla al toque, no sale a ninguna red.)
     *
     * @test
     */
    public function un_error_de_conexion_no_se_traga()
    {
        $original = DB::getDefaultConnection();

        config(['database.connections.blindaje_rota' => [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => '1',
            'database' => 'una_base_que_no_existe',
            'username' => 'nadie',
            'password' => '',
            'charset'  => 'utf8mb4',
            'prefix'   => '',
            'options'  => [\PDO::ATTR_TIMEOUT => 2],
        ]]);

        $se_propago = false;

        try {
            DB::setDefaultConnection('blindaje_rota');

            try {
                BorradoTotalDeBaseHelper::resumen_de_datos();
            } catch (\Throwable $e) {
                $se_propago = true;
            }
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('blindaje_rota');
        }

        $this->assertTrue($se_propago, 'Un error de conexión se tragó y la guarda habría contestado "base vacía".');
        $this->assertSame($original, DB::getDefaultConnection());
    }

    /**
     * Toda tabla de TABLAS_DE_NEGOCIO existe en la base de testing. Es la red contra una errata o un
     * renombre: un nombre mal escrito sería una tabla ausente, que la guarda trata como "vacía" en
     * silencio, y dejaría de ver esos datos sin avisar. (`current_acounts`, con una sola "c", es el
     * nombre real del esquema.) Y que no haya repetidas.
     *
     * @test
     */
    public function todas_las_tablas_de_negocio_existen_en_la_base()
    {
        $schema = DB::connection()->getSchemaBuilder();

        foreach (BorradoTotalDeBaseHelper::TABLAS_DE_NEGOCIO as $tabla) {
            $this->assertTrue($schema->hasTable($tabla), 'La tabla "' . $tabla . '" de TABLAS_DE_NEGOCIO no existe: ¿errata o renombre?');
        }

        $this->assertSame(
            array_values(array_unique(BorradoTotalDeBaseHelper::TABLAS_DE_NEGOCIO)),
            BorradoTotalDeBaseHelper::TABLAS_DE_NEGOCIO,
            'TABLAS_DE_NEGOCIO tiene una tabla repetida.'
        );
    }

    /**
     * 🔴 CADA TABLA CUENTA POR SÍ SOLA, no solo `users`: por cada tabla de la lista que tiene filas
     * en la base de testing, se vacían TODAS LAS DEMÁS (DELETE dentro de un savepoint, que se
     * deshace en cada vuelta; jamás truncate) y se comprueba que el resumen es exactamente esa tabla
     * y que el POST da 409 con solo esa tabla en `con_datos`. Si alguien saca una tabla de la lista
     * o la deja de contar, la vuelta de esa tabla da "base vacía" y este test cae.
     *
     * (Las tablas de la lista sin filas en la base de testing quedan cubiertas por el test de que
     * existen; probarlas una por una pediría armar una fila válida de cada una.)
     *
     * @test
     */
    public function cada_tabla_de_negocio_alcanza_por_si_sola_para_rechazar_el_setup()
    {
        $this->sin_migrate_fresh_jamas();

        $con_filas = [];

        foreach (BorradoTotalDeBaseHelper::TABLAS_DE_NEGOCIO as $tabla) {
            $filas = (int) DB::table($tabla)->count();

            if ($filas > 0) {
                $con_filas[$tabla] = $filas;
            }
        }

        $this->assertArrayHasKey('users', $con_filas, 'La base de testing tiene que tener usuarios.');
        $this->assertGreaterThanOrEqual(2, count($con_filas), 'Con una sola tabla con datos esta prueba no distingue nada: hay que sembrar la base de testing.');

        foreach ($con_filas as $tabla => $filas) {
            // Un savepoint dentro de la transacción del test: lo que se borra acá se deshace al salir.
            DB::beginTransaction();

            try {
                $this->vaciar_las_tablas_de_negocio([$tabla]);

                $this->assertSame(
                    [$tabla => $filas],
                    BorradoTotalDeBaseHelper::resumen_de_datos(),
                    'Con solo "' . $tabla . '" con datos, el resumen tenía que ser exactamente esa tabla.'
                );

                $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload());

                $this->assertSame(409, $respuesta->getStatusCode(), 'Con solo "' . $tabla . '" con datos, el setup tenía que rebotar.');
                $this->assert_409_por_base_con_datos($respuesta, $tabla);
            } finally {
                DB::rollBack();
            }
        }

        $this->assert_base_intacta();
    }

    // ------------------------------------------------------------------------------------------
    // Forense: quién pegó (IP y user agent en el log)
    // ------------------------------------------------------------------------------------------

    /**
     * El rechazo deja en el log la IP y el user agent (recortado a 120) del request, y NADA más del
     * request: ni la clave del header X-Admin-Api-Key ni un solo dato del payload.
     *
     * @test
     */
    public function el_rechazo_deja_en_el_log_la_ip_y_el_user_agent_y_nada_mas_del_request()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        $agente_largo = 'Forense/1.0 ' . str_repeat('x', 200);
        $clave_del_header = 'clave-secreta-del-header-forense-0123456789';
        $dato_del_payload = 'Nombre-Distintivo-Del-Payload-Forense';

        $respuesta = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->postJson(
            '/api/admin-sync/user-setup',
            $this->payload(['user_name' => $dato_del_payload]),
            ['User-Agent' => $agente_largo, 'X-Admin-Api-Key' => $clave_del_header]
        );

        $this->assert_409_por_base_con_datos($respuesta);

        $rechazos = $this->logs_que_contienen(['se rechazó un setup']);

        $this->assertCount(1, $rechazos);
        $this->assertStringContainsString('"ip":"203.0.113.7"', $rechazos[0]['texto']);
        $this->assertStringContainsString('"user_agent":"' . substr($agente_largo, 0, 120) . '"', $rechazos[0]['texto']);
        // Recortado a 120: el carácter 121 del agente no está.
        $this->assertStringNotContainsString(substr($agente_largo, 0, 121), $rechazos[0]['texto']);

        // Nada más del request, en ninguna línea de log de ningún nivel.
        foreach ($this->logs as $log) {
            $this->assertStringNotContainsString($clave_del_header, $log['texto'], 'La clave del header salió en el log.');
            $this->assertStringNotContainsString($dato_del_payload, $log['texto'], 'Un dato del payload salió en el log.');
        }
    }

    /**
     * El borrado total autorizado también deja la IP y el user agent.
     *
     * @test
     */
    public function el_borrado_autorizado_deja_en_el_log_la_ip_y_el_user_agent()
    {
        $this->exigir_base_con_datos();
        $this->migrate_fresh_cortado();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->postJson(
            '/api/admin-sync/user-setup',
            $this->payload([
                BorradoTotalDeBaseHelper::FLAG         => true,
                BorradoTotalDeBaseHelper::CONFIRMACION => BorradoTotalDeBaseHelper::nombre_de_la_base(),
            ]),
            ['User-Agent' => 'Forense-Autorizado/2.0']
        );

        $autorizados = $this->logs_que_contienen(['AUTORIZADO']);

        $this->assertCount(1, $autorizados);
        $this->assertStringContainsString('"ip":"198.51.100.9"', $autorizados[0]['texto']);
        $this->assertStringContainsString('"user_agent":"Forense-Autorizado/2.0"', $autorizados[0]['texto']);

        $this->assert_base_intacta();
    }

    /**
     * Sin request HTTP (consola, un job) la IP y el user agent quedan en null: no se inventan.
     *
     * @test
     */
    public function sin_request_http_la_ip_y_el_user_agent_quedan_en_null()
    {
        $this->exigir_base_con_datos();

        // Se saca el request del contenedor para simular "no hay request HTTP".
        app()->forgetInstance('request');
        $this->assertFalse(app()->bound('request'), 'El request tenía que dejar de estar en el contenedor.');

        try {
            BorradoTotalDeBaseHelper::exigir_base_sin_datos_o_autorizacion([]);
            $this->fail('Una base con datos y sin autorización tenía que tirar BaseConDatosException.');
        } catch (BaseConDatosException $e) {
            $rechazos = $this->logs_que_contienen(['se rechazó un setup']);

            $this->assertCount(1, $rechazos);
            $this->assertStringContainsString('"ip":null', $rechazos[0]['texto']);
            $this->assertStringContainsString('"user_agent":null', $rechazos[0]['texto']);
        }

        $this->assert_base_intacta();
    }

    // ------------------------------------------------------------------------------------------
    // 10: el orden dentro de run()
    // ------------------------------------------------------------------------------------------

    /**
     * 🔴 EL ORDEN DENTRO DE `run()` ES TODO EL ARREGLO y ninguna otra aserción lo mira: si alguien
     * mueve la guarda después del `migrate:fresh` (o la saca), los demás tests siguen en verde
     * porque mockean Artisan. Se lee el fuente de `run()` por reflexión (mismo recurso que
     * DemoSetupZipnovaTest): la guarda es la PRIMERA sentencia, y viene antes de cualquier
     * `Artisan::call`, en particular del `migrate:fresh`.
     *
     * @test
     */
    public function la_guarda_es_la_primera_sentencia_de_run_y_viene_antes_del_migrate_fresh()
    {
        $run    = new ReflectionMethod(UserSetupHelper::class, 'run');
        $lineas = file($run->getFileName());
        $fuente = implode('', array_slice($lineas, $run->getStartLine() - 1, $run->getEndLine() - $run->getStartLine() + 1));

        $guarda = strpos($fuente, 'BorradoTotalDeBaseHelper::exigir_base_sin_datos_o_autorizacion(');
        $fresh  = strpos($fuente, "Artisan::call('migrate:fresh'");
        $primer_artisan = strpos($fuente, 'Artisan::call(');

        $this->assertNotFalse($guarda, 'run() ya no llama a la guarda de borrado total.');
        $this->assertNotFalse($fresh, 'run() ya no tiene el migrate:fresh: este test tiene que revisarse.');
        $this->assertLessThan($fresh, $guarda, 'La guarda tiene que estar ANTES del migrate:fresh.');
        $this->assertLessThan($primer_artisan, $guarda, 'La guarda tiene que estar antes de cualquier Artisan::call.');

        // Y es la primera sentencia: el primer token con significado después de la llave del método.
        $cuerpo  = substr($fuente, strpos($fuente, '{') + 1);
        $tokens  = token_get_all('<?php ' . $cuerpo);
        $primero = null;

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $primero = $token;
            break;
        }

        $this->assertTrue(is_array($primero) && $primero[1] === 'BorradoTotalDeBaseHelper', 'La guarda tiene que ser la primera sentencia de run().');
    }

    // ------------------------------------------------------------------------------------------
    // 11: la clave de admin
    // ------------------------------------------------------------------------------------------

    /**
     * Deja la configuración de la clave en un estado conocido (las dos variables y la clave de la
     * instancia), sin depender de lo que traiga el .env.testing.
     *
     * @param  bool        $de_setup  services.admin_api.require_key_for_setup
     * @param  bool        $global    services.admin_api.require_api_key
     * @param  string|null $clave     services.admin_api.api_key (la de la instancia)
     * @return void
     */
    protected function configurar_la_clave($de_setup, $global, $clave)
    {
        config([
            'services.admin_api.require_key_for_setup' => $de_setup,
            'services.admin_api.require_api_key'       => $global,
            'services.admin_api.api_key'               => $clave,
        ]);
    }

    /**
     * Lo que valdría `services.admin_api.require_key_for_setup` si la variable de entorno
     * ADMIN_SYNC_SETUP_REQUIRE_API_KEY tuviera ese valor: se evalúa el config/services.php de verdad
     * con la variable puesta (putenv, que `env()` lee) y se restaura la que había. Con `null` la
     * variable no existe (el caso "sin tocar nada").
     *
     * No toca la configuración global de la app ni el .env: solo el entorno del proceso, y lo deja
     * como estaba aunque algo reviente.
     *
     * @param  string|null $valor Texto de la variable, o null para "sin definir".
     * @return mixed Lo que dejó el config/services.php en esa clave.
     */
    protected function clave_de_setup_segun_la_variable($valor)
    {
        $nombre   = 'ADMIN_SYNC_SETUP_REQUIRE_API_KEY';
        $anterior = getenv($nombre);

        try {
            if ($valor === null) {
                putenv($nombre);
            } else {
                putenv($nombre . '=' . $valor);
            }

            $services = require config_path('services.php');

            return $services['admin_api']['require_key_for_setup'];
        } finally {
            if ($anterior === false) {
                putenv($nombre);
            } else {
                putenv($nombre . '=' . $anterior);
            }
        }
    }

    /**
     * La variable nueva nace apagada: es lo que garantiza que esta misión no rompe el alta de clientes.
     *
     * @test
     */
    public function la_variable_de_la_clave_nace_apagada_por_defecto()
    {
        $this->assertSame(
            false,
            $this->clave_de_setup_segun_la_variable(null),
            'ADMIN_SYNC_SETUP_REQUIRE_API_KEY tiene que estar apagada por defecto: admin-api todavía no manda el header.'
        );
    }

    /**
     * La variable de entorno se lee como un booleano REAL: `env()` de Laravel solo convierte
     * 'true'/'false'/'null'/'empty' y devuelve cualquier otro texto tal cual, así que un 'off' o un
     * 'no' (string no vacío, truthy) dejaría la clave PRENDIDA y rompería el alta de clientes.
     * Por eso config/services.php la pasa por filter_var con FILTER_VALIDATE_BOOLEAN.
     *
     * @test
     */
    public function la_variable_de_entorno_se_lee_como_booleano_real()
    {
        $apagadas = ['false', 'FALSE', '0', 'off', 'OFF', 'no', 'No', '', 'cualquier cosa', '2'];
        $prendidas = ['true', 'TRUE', '1', 'on', 'On', 'yes', 'Yes'];

        foreach ($apagadas as $valor) {
            $this->assertSame(
                false,
                $this->clave_de_setup_segun_la_variable($valor),
                'ADMIN_SYNC_SETUP_REQUIRE_API_KEY=' . var_export($valor, true) . ' tenía que dejarla APAGADA.'
            );
        }

        foreach ($prendidas as $valor) {
            $this->assertSame(
                true,
                $this->clave_de_setup_segun_la_variable($valor),
                'ADMIN_SYNC_SETUP_REQUIRE_API_KEY=' . var_export($valor, true) . ' tenía que dejarla PRENDIDA.'
            );
        }

        // Y el entorno del proceso quedó como estaba: la variable sigue sin existir.
        $this->assertFalse(getenv('ADMIN_SYNC_SETUP_REQUIRE_API_KEY'));
    }

    /**
     * Variable apagada y sin header: la clave no se pide. Llega a la guarda de datos (409 por datos,
     * no 401), igual que hoy.
     *
     * @test
     */
    public function con_la_variable_apagada_y_sin_header_la_clave_no_se_pide()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();
        $this->configurar_la_clave(false, false, self::CLAVE_DE_ADMIN);

        $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload());

        $this->assert_409_por_base_con_datos($respuesta);
        $this->assert_base_intacta();
    }

    /**
     * Variable prendida: sin header 401, con header incorrecto 401 (y no llega a la guarda ni al
     * candado), con el header correcto pasa (llega a la guarda: 409 por datos).
     *
     * @test
     */
    public function con_la_variable_prendida_se_exige_el_header_de_admin()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();
        $this->configurar_la_clave(true, false, self::CLAVE_DE_ADMIN);

        $sin_header = $this->postJson('/api/admin-sync/user-setup', $this->payload());
        $sin_header->assertStatus(401);
        $sin_header->assertJson(['error' => 'unauthorized']);
        $sin_header->assertJsonMissing(['base_con_datos' => true]);

        $incorrecto = $this->postJson('/api/admin-sync/user-setup', $this->payload(), ['X-Admin-Api-Key' => self::CLAVE_DE_ADMIN . 'x']);
        $incorrecto->assertStatus(401);
        $incorrecto->assertJson(['error' => 'unauthorized']);

        $vacio = $this->postJson('/api/admin-sync/user-setup', $this->payload(), ['X-Admin-Api-Key' => '']);
        $vacio->assertStatus(401);

        // Rebotado por la clave: el candado no se tomó (se puede tomar ahora mismo).
        $libre = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($libre, 'Un 401 por clave no tiene que dejar el candado tomado.');
        DemoSetupLockHelper::soltar($libre);

        // Con la clave correcta, pasa el middleware y rebota la guarda de datos (no el 401).
        $correcto = $this->postJson('/api/admin-sync/user-setup', $this->payload(), ['X-Admin-Api-Key' => self::CLAVE_DE_ADMIN]);
        $this->assert_409_por_base_con_datos($correcto);

        $this->assert_base_intacta();
    }

    /**
     * Variable prendida y la instancia SIN clave cargada: falla cerrado (401), con cualquier header,
     * incluido el vacío. "Prendida" significa "exijo clave"; sin clave no hay con qué validar.
     *
     * @test
     */
    public function con_la_variable_prendida_y_sin_clave_en_la_instancia_falla_cerrado()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();

        foreach ([null, ''] as $clave_de_la_instancia) {
            $this->configurar_la_clave(true, false, $clave_de_la_instancia);

            foreach ([[], ['X-Admin-Api-Key' => ''], ['X-Admin-Api-Key' => self::CLAVE_DE_ADMIN]] as $headers) {
                $respuesta = $this->postJson('/api/admin-sync/user-setup', $this->payload(), $headers);

                $respuesta->assertStatus(401);
                $respuesta->assertJson(['error' => 'unauthorized']);
            }
        }

        $this->assert_base_intacta();
    }

    /**
     * 🔴 El flag GLOBAL (ADMIN_SYNC_REQUIRE_API_KEY) NO exige la clave en user-setup: la ruta solo
     * mira su variable propia. Es una decisión de diseño por compatibilidad hacia atrás (cambió
     * respecto de la primera versión de esta misión, que sí lo contemplaba): si un cliente del VPS
     * tuviera el global en true, user-setup pasaría a dar 401 porque admin-api no manda la clave a
     * esa ruta, y se rompería el alta de clientes. Con el global prendido y sin header, el request
     * llega a la guarda de datos (409 por datos, no 401).
     *
     * @test
     */
    public function el_flag_global_no_exige_la_clave_en_user_setup()
    {
        $this->exigir_base_con_datos();
        $this->sin_migrate_fresh_jamas();
        $this->configurar_la_clave(false, true, self::CLAVE_DE_ADMIN);

        $sin_header = $this->postJson('/api/admin-sync/user-setup', $this->payload());
        $this->assert_409_por_base_con_datos($sin_header);

        // Tampoco con un header equivocado: con la variable propia apagada, la clave no se mira.
        $equivocado = $this->postJson('/api/admin-sync/user-setup', $this->payload(), ['X-Admin-Api-Key' => 'otra-cosa']);
        $this->assert_409_por_base_con_datos($equivocado);

        $this->assert_base_intacta();
    }

    /**
     * La clave es SOLO de user-setup: demo-setup sigue sin pedirla (esta misión no lo toca, y
     * un cambio ahí rompería el alta de demos del admin).
     *
     * @test
     */
    public function la_clave_de_setup_no_se_le_pide_a_demo_setup()
    {
        $this->configurar_la_clave(true, false, self::CLAVE_DE_ADMIN);

        $middleware = [];

        foreach (app('router')->getRoutes() as $ruta) {
            if ($ruta->uri() === 'api/admin-sync/demo-setup') {
                $middleware = $ruta->gatherMiddleware();
            }
        }

        $this->assertNotEmpty($middleware, 'No se encontró la ruta de demo-setup.');
        $this->assertNotContains('admin.setup.key', $middleware);
        $this->assertNotContains(\App\Http\Middleware\ClaveDeAdminEnSetup::class, $middleware);
    }
}
