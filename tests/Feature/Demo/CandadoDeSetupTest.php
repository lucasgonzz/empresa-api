<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El candado que serializa las tres puertas al `migrate:fresh` de esta instancia:
 * POST /api/admin-sync/demo-setup, POST /api/admin-sync/user-setup y el form legacy
 * POST /demo-setup (ver DemoSetupLockHelper, que las lista por nombre).
 *
 * Lo que gobierna acá no es el código de respuesta sino la mitad que le sigue: que la
 * corrida rebotada NO llegue al `migrate:fresh` de los helpers, que es lo que el 25/8/2026
 * le vació la base a la corrida que ya estaba sembrando (`SQLSTATE[42S02]` adentro de
 * `semilla:datos`).
 *
 * CÓMO SE PRUEBA QUE LA BASE NO SE TOCÓ, Y POR QUÉ NO ALCANZA CONTAR TABLAS
 * ------------------------------------------------------------------------
 * La primera versión de este test contaba tablas antes y después. No servía para nada:
 * `migrate:fresh` dropea y vuelve a crear exactamente las mismas 396 tablas, así que la
 * aserción pasaba igual con la base entera vaciada — el test decía una cosa y verificaba
 * otra. Lo que un wipe SÍ rompe son las FILAS. Por eso acá se planta un marcador en
 * `migrations` (la tabla que `migrate:fresh` dropea primero y repuebla desde cero, así que
 * un renglón inventado por el test no sobrevive de ninguna manera) y además se controla el
 * conteo de filas de `users`. Si cualquiera de las dos cosas cambia, el request rebotado
 * llegó al helper.
 *
 * 🔴 NINGÚN test de esta clase llama a DemoSetupHelper::run() ni a UserSetupHelper::run().
 * Arrancan con `Artisan::call('migrate:fresh')` y vaciarían empresa_testing_s1 en medio de
 * la suite, dejando en rojo todo lo que corra después.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de
 * antes y un refresh la vaciaría, que es justo el accidente que este test vigila. El
 * marcador se va solo con el rollback de cada caso.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class CandadoDeSetupTest extends TestCase
{
    use DatabaseTransactions;

    /** Renglón inventado en `migrations` que un `migrate:fresh` no puede dejar en pie. */
    const MARCADOR = 'marcador_del_test_del_candado_de_setup';

    /** Id de User que la base de testing no usa, para las creaciones por reflexion. */
    const ID_DE_USUARIO_LIBRE = 987654;

    /**
     * Handle del candado que toma el test para simular "ya hay una corrida". Se suelta en
     * tearDown pase lo que pase: un candado que queda tomado por un test traba los que siguen.
     *
     * @var resource|false
     */
    protected $candado = false;

    /** @var int */
    protected $filasDeUsuariosAntes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('migrations')->insert([
            'migration' => self::MARCADOR,
            'batch' => 9999,
        ]);

        $this->filasDeUsuariosAntes = (int) DB::table('users')->count();
    }

    protected function tearDown(): void
    {
        DemoSetupLockHelper::soltar($this->candado);
        $this->candado = false;

        parent::tearDown();
    }

    /** @test */
    public function con_el_candado_tomado_demo_setup_responde_409_y_no_toca_la_base()
    {
        $this->tomarElCandado();

        $respuesta = $this->postJson('/api/admin-sync/demo-setup', [
            'business_type' => 'kiosco',
            'doc_number' => '30111222',
            'name' => 'Demo del test',
            'company_name' => 'Demo del test',
        ]);

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['en_curso' => true]);
        $this->assertNotEmpty($respuesta->json('error'), 'El 409 tiene que traer un mensaje para mostrarle a quien disparó el setup.');

        $this->assertBaseIntacta();
    }

    /** @test */
    public function con_el_candado_tomado_user_setup_tambien_responde_409_y_no_toca_la_base()
    {
        /**
         * La tercera puerta. UserSetupHelper::run() arranca con el MISMO `migrate:fresh` que
         * el de demo, y el caso realista es la conversión de Lead a Cliente disparada mientras
         * la demo de ese lead todavía está sembrando. Un candado que cerrara solo las dos
         * puertas de demo dejaría la carrera viva por acá.
         */
        $this->tomarElCandado();

        $respuesta = $this->postJson('/api/admin-sync/user-setup', [
            'business_type' => 'kiosco',
            'user_id' => 987654,
            'doc_number' => '30111222',
            'user_name' => 'Cliente del test',
        ]);

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['en_curso' => true]);
        $this->assertNotEmpty($respuesta->json('error'));

        $this->assertBaseIntacta();
    }

    /** @test */
    public function un_payload_sin_doc_number_no_revienta_y_no_deja_la_cuenta_con_contrasena_vacia()
    {
        /**
         * NO se valida `doc_number` como requerido, y este test fija esa decision: admin-api
         * manda `doc_number = ''` a proposito cuando el formulario de implementacion no cargo
         * documento (`ImplementationUserSetupService`), y un lead de demo puede llegar con la
         * clave en null. Las dos puntas no salen a produccion juntas, asi que rechazar ese
         * payload dejaria sin armar instancias que hoy se arman.
         *
         * Lo que si cambia es la contrasena: antes ese caso quedaba en `bcrypt('')`, o sea una
         * cuenta que abre con la contrasena VACIA en un dominio publico. Ahora sale de un
         * Str::random(40): igual de inservible, pero ya no adivinable.
         *
         * Se ejercita create_demo_user() por reflexion y NO run(): run() arranca con
         * `migrate:fresh` y vaciaria empresa_testing_s1. Este metodo es un unico User::create(),
         * sin otros efectos, y el id se apunta a uno libre para no chocar con el usuario
         * sembrado. DatabaseTransactions se lleva la fila al terminar el caso.
         */
        config(['app.USER_ID' => self::ID_DE_USUARIO_LIBRE]);

        $create_demo_user = new \ReflectionMethod(DemoSetupHelper::class, 'create_demo_user');
        $create_demo_user->setAccessible(true);

        $sin_doc = $create_demo_user->invoke(null, array('company_name' => 'Demo del test'));

        $this->assertNotNull($sin_doc->id, 'Un payload sin doc_number tiene que crear el usuario igual, no reventar.');
        $this->assertNull($sin_doc->doc_number);
        $this->assertFalse(
            Hash::check('', $sin_doc->password),
            'La cuenta abre con la contrasena vacia: volvio el bcrypt del string vacio.'
        );
    }

    /** @test */
    public function un_payload_con_doc_number_sigue_teniendo_el_documento_de_contrasena()
    {
        /**
         * La otra mitad del contrato, y la que importa para no romper nada: cuando doc_number
         * viene con valor, la contrasena es el documento, exactamente como siempre.
         */
        config(['app.USER_ID' => self::ID_DE_USUARIO_LIBRE]);

        $create_demo_user = new \ReflectionMethod(DemoSetupHelper::class, 'create_demo_user');
        $create_demo_user->setAccessible(true);

        $con_doc = $create_demo_user->invoke(null, array('doc_number' => '30111222'));

        $this->assertSame('30111222', $con_doc->doc_number);
        $this->assertTrue(
            Hash::check('30111222', $con_doc->password),
            'Con doc_number cargado la contrasena tiene que seguir siendo el documento.'
        );
    }

    /** @test */
    public function el_string_vacio_que_manda_admin_api_tampoco_deja_la_contrasena_vacia()
    {
        /** El caso exacto de ImplementationUserSetupService: la clave llega, en cadena vacia. */
        config(['app.USER_ID' => self::ID_DE_USUARIO_LIBRE]);

        $create_demo_user = new \ReflectionMethod(DemoSetupHelper::class, 'create_demo_user');
        $create_demo_user->setAccessible(true);

        $vacio = $create_demo_user->invoke(null, array('doc_number' => ''));

        $this->assertFalse(Hash::check('', $vacio->password));
    }

    /** @test */
    public function el_candado_queda_libre_despues_de_soltarlo()
    {
        $primero = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($primero, 'El candado tenía que estar libre al empezar el test.');

        $this->assertFalse(
            DemoSetupLockHelper::tomar(),
            'Con el candado tomado, un segundo tomar() tiene que devolver false y no colgarse esperando.'
        );

        DemoSetupLockHelper::soltar($primero);

        $segundo = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($segundo, 'Después de soltar, el candado tiene que volver a estar disponible.');

        DemoSetupLockHelper::soltar($segundo);
    }

    /** @test */
    public function el_archivo_lock_que_queda_en_disco_no_bloquea_por_si_solo()
    {
        /**
         * El archivo .lock NO se borra al soltar (borrarlo abre una carrera entre el fopen de
         * uno y el unlink del otro). Este caso fija esa decisión: que el archivo exista de
         * corridas anteriores no puede alcanzar para trabar la instancia — lo que traba es el
         * flock vivo de un proceso vivo.
         */
        $handle = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($handle);
        DemoSetupLockHelper::soltar($handle);

        $this->assertFileExists(DemoSetupLockHelper::ruta(), 'El archivo de candado queda en disco a propósito.');

        $otro = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($otro, 'Un archivo .lock huérfano no puede trabar el setup.');
        DemoSetupLockHelper::soltar($otro);
    }

    /**
     * @return void
     */
    protected function tomarElCandado()
    {
        $this->candado = DemoSetupLockHelper::tomar();

        $this->assertNotFalse(
            $this->candado,
            'El candado tenía que estar libre al empezar el test. Si acá da false, quedó tomado por otra corrida.'
        );
    }

    /**
     * Un `migrate:fresh` dropea `migrations` y `users` y las repuebla desde cero: el marcador
     * inventado por el test no puede sobrevivirlo, y el conteo de usuarios tampoco.
     *
     * @return void
     */
    protected function assertBaseIntacta()
    {
        $this->assertSame(
            1,
            (int) DB::table('migrations')->where('migration', self::MARCADOR)->count(),
            'Desapareció el marcador de migrations: el request llegó igual al migrate:fresh del helper y vació la base.'
        );

        $this->assertSame(
            $this->filasDeUsuariosAntes,
            (int) DB::table('users')->count(),
            'Cambió la cantidad de usuarios: el request rebotado terminó tocando la base.'
        );
    }

    /**
     * @param string $mensaje
     *
     * @return void
     */
    protected function assertCandadoLibre($mensaje)
    {
        $handle = DemoSetupLockHelper::tomar();
        $this->assertNotFalse($handle, $mensaje);
        DemoSetupLockHelper::soltar($handle);
    }
}
