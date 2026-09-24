<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Misión redireccion-version-antes-del-login (24/9/2026): `GET /api/version-activa`, el endpoint
 * PÚBLICO que el SPA consulta en la pantalla de login, ANTES de que nadie inicie sesión, para saber
 * cuál es la dirección del sistema activo y mandar al negocio a la versión actual sin esperar a que
 * escriba su documento y su clave en el frente viejo. Ver VersionActivaHelper y
 * VersionActivaController.
 *
 * LO QUE ESTE ARCHIVO PROTEGE, caso por caso:
 *
 *  a. Con UN solo dueño con `default_version`: 200 con ese valor, SIN estar autenticado, con una
 *     única clave en el JSON (nada del negocio), `Cache-Control: no-store` y SIN `Set-Cookie`.
 *  b. Con DOS dueños: null. En una base compartida por varios comercios no se sabe a cuál le habla
 *     el que entra, y mandarlo al frente de otro antes de que escriba su documento es el error
 *     caro. Se prueba con el mismo `default_version` en los dos y con uno distinto en cada uno.
 *  c. Un solo dueño SIN `default_version` (null, cadena vacía, solo espacios): null.
 *  d. El `default_version` de un EMPLEADO (owner_id no nulo) no cuenta: ni reemplaza al del dueño
 *     ni se usa cuando el dueño no tiene el suyo.
 *  e. `config('app.USER_ID')` NO participa. Con dos dueños sigue siendo null aunque USER_ID apunte
 *     a uno de ellos, y con un solo dueño el valor se devuelve aunque USER_ID apunte a cualquier
 *     otro lado. Es la decisión que separa a este endpoint de AsistenteCanalHelper::dueno(): en la
 *     base compartida los frentes genéricos atienden a decenas de dueños con el mismo código y el
 *     USER_ID de la carpeta no dice a quién le habla el visitante anónimo.
 *  f. Un valor con espacios alrededor se devuelve recortado.
 *  g. Sin ningún dueño (una base con solo empleados): null, sin explotar.
 *  h. Un control del propio test (el último): una ruta cualquiera del grupo `api`, SIN la
 *     exclusión de la ruta nueva, sí emite la cookie de sesión cuando el pedido viene del frente.
 *     Sin ese control, el "no trae Set-Cookie" del caso a podría dar verde por una razón
 *     equivocada (un entorno donde el pedido nunca se considera "del frente", o donde la sesión
 *     no emite cookie) y la exclusión `withoutMiddleware` quedaría sin cubrir.
 *
 * SOBRE `Set-Cookie`: la ruta se excluye de EnsureFrontendRequestsAreStateful (de Sanctum), que
 * arranca la sesión y emite la cookie cada vez que el pedido trae un Origin o un Referer de un
 * dominio "stateful". Un visitante anónimo la llama en CADA carga del login, así que sin la
 * exclusión cada visita crearía una sesión en el servidor y le plantaría una cookie al navegador.
 * Por eso el pedido de estos tests viene con el Origin y el Referer del frente (el caso difícil):
 * un pedido sin esos headers ni siquiera activaría el middleware y el caso no probaría nada.
 *
 * SOBRE LOS USUARIOS: los tests NO dependen de qué usuarios trae sembrada cada base de slot (la de
 * s22 trae el 500 y el 900; otra podría traer otros). Cada test arranca creando sus DOS dueños
 * propios con `DB::table` (sin pasar por el modelo, para no disparar sus observers) y convierte a
 * cualquier usuario que ya existiera en empleado del primero: UPDATE, jamás DELETE. Todo ocurre
 * dentro de la transacción de DatabaseTransactions, que se revierte al terminar.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class VersionActivaAntesDelLoginTest extends TestCase
{
    use DatabaseTransactions;

    /** Ruta pública que consulta el SPA en la pantalla de login (el grupo `api` le pone el prefijo). */
    const RUTA = '/api/version-activa';

    /**
     * Host (con puerto) que estos tests declaran como dominio del frontend "stateful" de Sanctum.
     * Es inventado a propósito: el test fija `sanctum.stateful` a este valor y no depende del
     * SANCTUM_STATEFUL_DOMAINS del .env.testing de cada slot.
     */
    const HOST_DEL_FRENTE = 'frente-viejo.local:8202';

    /** Una dirección de sistema activo cualquiera, la que el admin escribiría en `default_version`. */
    const DIRECCION_ACTIVA = 'https://cliente-activo.comerciocity.com';

    /** Otra dirección distinta, para los casos donde no tiene que haber confusión entre dos valores. */
    const OTRA_DIRECCION = 'https://otro-cliente.comerciocity.com';

    /**
     * Id del primer dueño propio de estos tests (es el que queda como dueño único cuando el
     * escenario pide uno solo).
     *
     * @var int
     */
    protected $dueno_a_id;

    /**
     * Id del segundo usuario propio: dueño cuando el escenario pide dos, empleado de A cuando pide
     * uno solo.
     *
     * @var int
     */
    protected $dueno_b_id;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Se fija a mano qué dominio cuenta como "el frente" para Sanctum, en vez de depender del
         * SANCTUM_STATEFUL_DOMAINS del .env.testing de este slot: así el pedido de estos tests es
         * "del frente" en cualquier slot y en cualquier máquina.
         */
        config(['sanctum.stateful' => [self::HOST_DEL_FRENTE]]);

        // Los dos dueños propios de estos tests, sin dirección todavía.
        $this->dueno_a_id = $this->insertar_usuario(null, null);
        $this->dueno_b_id = $this->insertar_usuario(null, null);

        /*
         * Cualquier usuario que ya trajera la base sembrada pasa a ser EMPLEADO del dueño A y sin
         * dirección, para que la cantidad de dueños dependa solo de lo que arma cada test. Es un
         * UPDATE dentro de la transacción: no se borra nada y todo se revierte al terminar.
         */
        DB::table('users')
            ->whereNotIn('id', [$this->dueno_a_id, $this->dueno_b_id])
            ->update([
                'owner_id'        => $this->dueno_a_id,
                'default_version' => null,
            ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Armado de los escenarios
    // ---------------------------------------------------------------------------------------------

    /**
     * Inserta un usuario mínimo directo en `users`. Va por `DB::table` y no por el modelo User para
     * no disparar sus observers (UserEtiquetaMedidaObserver crea datos derivados que acá sobran).
     *
     * `password` y `status` son las dos únicas columnas de `users` sin valor por defecto.
     *
     * @param  int|null     $owner_id         Null si es dueño; el id del dueño si es empleado.
     * @param  string|null  $default_version  La dirección del sistema activo que tiene cargada.
     * @return int  El id del usuario insertado.
     */
    protected function insertar_usuario($owner_id, $default_version)
    {
        return (int) DB::table('users')->insertGetId([
            'name'            => 'zz-version-activa',
            'password'        => 'zz-sin-login',
            'status'          => 'commerce',
            'owner_id'        => $owner_id,
            'default_version' => $default_version,
        ]);
    }

    /**
     * Cuántos dueños (`owner_id` null) hay ahora mismo en `users`. Los escenarios lo usan para
     * comprobar que quedaron armados como dicen, antes de llamar al endpoint.
     *
     * @return int
     */
    protected function cantidad_de_duenos()
    {
        return DB::table('users')->whereNull('owner_id')->count();
    }

    /**
     * Deja UN solo dueño en `users`: A, con la dirección que se pida. B pasa a ser EMPLEADO de A
     * (UPDATE, no se borra), y la dirección de empleado no tiene que contar para nada.
     *
     * 🔴 TODOS los empleados de A llevan esa misma dirección de empleado: B y también los usuarios
     * que ya traía sembrados la base, que setUp() dejó como empleados de A. No alcanza con cargarla
     * solo en B, y se comprobó con una sonda: una implementación que "tomara prestado" el valor de
     * un empleado cuando el dueño no tiene el suyo pasaba el test igual, porque su consulta caía en
     * un empleado sembrado (id más bajo, sin dirección) y nunca llegaba a B. Con todos los empleados
     * cargados da lo mismo cuál lea la implementación: si usa a alguno, se nota.
     *
     * @param  string|null  $default_version_del_dueno
     * @param  string|null  $default_version_del_empleado  La que llevan TODOS los empleados de A.
     * @return void
     */
    protected function armar_un_solo_dueno($default_version_del_dueno, $default_version_del_empleado = null)
    {
        DB::table('users')->where('id', $this->dueno_a_id)->update([
            'default_version' => $default_version_del_dueno,
        ]);

        // B deja de ser dueño: pasa a ser empleado de A.
        DB::table('users')->where('id', $this->dueno_b_id)->update([
            'owner_id' => $this->dueno_a_id,
        ]);

        // Todos los empleados de A (B y los ya sembrados) con la misma dirección de empleado.
        DB::table('users')->where('owner_id', $this->dueno_a_id)->update([
            'default_version' => $default_version_del_empleado,
        ]);

        $this->assertSame(1, $this->cantidad_de_duenos(), 'El escenario tiene que dejar exactamente UN dueño.');
    }

    /**
     * Deja DOS dueños en `users`: A y B, cada uno con la dirección que se pida.
     *
     * @param  string|null  $default_version_de_a
     * @param  string|null  $default_version_de_b
     * @return void
     */
    protected function armar_dos_duenos($default_version_de_a, $default_version_de_b)
    {
        DB::table('users')->where('id', $this->dueno_a_id)->update([
            'default_version' => $default_version_de_a,
        ]);

        DB::table('users')->where('id', $this->dueno_b_id)->update([
            'default_version' => $default_version_de_b,
        ]);

        $this->assertSame(2, $this->cantidad_de_duenos(), 'El escenario tiene que dejar exactamente DOS dueños.');
    }

    /**
     * Hace el pedido como lo hace el SPA del frente viejo en la pantalla de login: anónimo (sin
     * `actingAs`, sin token) y con el Origin y el Referer del frente. Ese es el caso difícil para
     * la cookie: son justo los headers que hacen que Sanctum arranque la sesión.
     *
     * @param  string  $ruta  La ruta a llamar (por defecto, la del endpoint).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_desde_el_frente($ruta = self::RUTA)
    {
        return $this->withHeaders([
            'Origin'  => 'http://' . self::HOST_DEL_FRENTE,
            'Referer' => 'http://' . self::HOST_DEL_FRENTE . '/login',
        ])->getJson($ruta);
    }

    /**
     * Comprueba que la respuesta no plantó ninguna cookie. Se mira de las dos maneras: el header
     * `Set-Cookie` y la lista de cookies del objeto respuesta (que es donde Laravel las acumula
     * antes de serializarlas).
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return void
     */
    protected function assert_sin_cookies($respuesta)
    {
        $respuesta->assertHeaderMissing('Set-Cookie');

        $nombres_de_cookies = [];

        foreach ($respuesta->headers->getCookies() as $cookie) {
            $nombres_de_cookies[] = $cookie->getName();
        }

        $this->assertSame(
            [],
            $nombres_de_cookies,
            'La ruta es pública y anónima: no puede arrancar sesión ni plantar ninguna cookie (planta: '
            . implode(', ', $nombres_de_cookies) . ').'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // a. Un solo dueño con default_version
    // ---------------------------------------------------------------------------------------------

    /**
     * EL CASO FELIZ, con el pedido difícil: un solo dueño con dirección cargada. Responde 200 con
     * esa dirección, sin haber iniciado sesión, con una única clave (nada del negocio), sin caché y
     * SIN cookie de sesión aunque el pedido venga del dominio del frente.
     *
     * @return void
     */
    public function test_un_solo_dueno_con_default_version_lo_devuelve_sin_sesion_ni_cookies()
    {
        $this->armar_un_solo_dueno(self::DIRECCION_ACTIVA);

        $respuesta = $this->pedir_desde_el_frente();

        $respuesta->assertStatus(200);
        $respuesta->assertHeader('Content-Type', 'application/json');

        /** La respuesta tiene EXACTAMENTE esa clave: ni nombre, ni id, ni email del negocio. */
        $respuesta->assertExactJson(['default_version' => self::DIRECCION_ACTIVA]);
        $this->assertSame(['default_version'], array_keys($respuesta->json()));

        /** Anónimo: nadie quedó autenticado por haber llamado a esta ruta. */
        $this->assertGuest();

        /** El valor cambia con cada upgrade del admin: nada de cachés intermedias. */
        $cache_control = (string) $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache_control);
        $this->assertStringContainsString('max-age=0', $cache_control);

        /** Y sin sesión: ni Set-Cookie ni ninguna cookie en el objeto respuesta. */
        $this->assert_sin_cookies($respuesta);
    }

    /**
     * El mismo caso feliz para un pedido sin Origin ni Referer (un curl, un monitor, un navegador
     * que los omite): también responde, y tampoco planta cookies.
     *
     * @return void
     */
    public function test_responde_igual_a_un_pedido_sin_origin_ni_referer()
    {
        $this->armar_un_solo_dueno(self::DIRECCION_ACTIVA);

        $respuesta = $this->getJson(self::RUTA);

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['default_version' => self::DIRECCION_ACTIVA]);
        $this->assert_sin_cookies($respuesta);
    }

    // ---------------------------------------------------------------------------------------------
    // b. Dos dueños
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Dos dueños que tienen CARGADA LA MISMA dirección: igual se contesta null. No es una
     * casualidad que se pueda aprovechar ("total, dicen lo mismo"): la regla es la cantidad de
     * dueños, no el contenido, porque el día que uno se actualice y el otro no, el que entra
     * quedaría mandado al frente del otro.
     *
     * @return void
     */
    public function test_con_dos_duenos_con_el_mismo_default_version_responde_null()
    {
        $this->armar_dos_duenos(self::DIRECCION_ACTIVA, self::DIRECCION_ACTIVA);

        $respuesta = $this->pedir_desde_el_frente();

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['default_version' => null]);
    }

    /**
     * 🔴 EL ERROR CARO QUE LA REGLA EVITA: dos comercios en la misma base, cada uno con su frente
     * activo distinto. Contestar cualquiera de las dos direcciones mandaría a un negocio al
     * frente del otro antes de que escriba su documento.
     *
     * @return void
     */
    public function test_con_dos_duenos_con_distinto_default_version_responde_null()
    {
        $this->armar_dos_duenos(self::DIRECCION_ACTIVA, self::OTRA_DIRECCION);

        $respuesta = $this->pedir_desde_el_frente();

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['default_version' => null]);
    }

    // ---------------------------------------------------------------------------------------------
    // c. Un solo dueño sin default_version
    // ---------------------------------------------------------------------------------------------

    /**
     * Las formas en que un `default_version` puede estar "sin cargar" y no servirle al SPA para
     * redirigir a nadie: null, cadena vacía y solo espacios (más una tabulación y un salto de
     * línea, que `trim` también se lleva).
     *
     * @return array<string, array<int, string|null>>
     */
    public function valores_sin_cargar()
    {
        return [
            'null'                          => [null],
            'cadena vacía'                  => [''],
            'solo espacios'                 => ['   '],
            'espacios, tabulación y salto'  => [" \t \n "],
        ];
    }

    /**
     * Un solo dueño, pero sin una dirección utilizable: se contesta null (y no una cadena vacía
     * que el SPA tendría que interpretar).
     *
     * @dataProvider valores_sin_cargar
     * @param  string|null  $valor_sin_cargar
     * @return void
     */
    public function test_un_solo_dueno_sin_default_version_responde_null($valor_sin_cargar)
    {
        $this->armar_un_solo_dueno($valor_sin_cargar);

        $respuesta = $this->pedir_desde_el_frente();

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['default_version' => null]);
    }

    // ---------------------------------------------------------------------------------------------
    // d. Los empleados no cuentan
    // ---------------------------------------------------------------------------------------------

    /**
     * El dueño tiene la dirección A y sus empleados tienen la B: responde A. Sirve además de prueba
     * de que los empleados no cuentan como "otro dueño" (si contaran, daría null).
     *
     * @return void
     */
    public function test_el_default_version_de_un_empleado_no_reemplaza_al_del_dueno()
    {
        $this->armar_un_solo_dueno(self::DIRECCION_ACTIVA, self::OTRA_DIRECCION);

        $respuesta = $this->pedir_desde_el_frente();

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['default_version' => self::DIRECCION_ACTIVA]);
    }

    /**
     * El dueño no tiene dirección pero todos sus empleados sí: se contesta null. La dirección de un
     * empleado no se "presta" al dueño que no la tiene (ver en armar_un_solo_dueno() por qué los
     * empleados van todos cargados).
     *
     * @return void
     */
    public function test_el_default_version_de_un_empleado_no_se_usa_si_el_dueno_no_tiene_el_suyo()
    {
        $this->armar_un_solo_dueno(null, self::OTRA_DIRECCION);

        $respuesta = $this->pedir_desde_el_frente();

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['default_version' => null]);
    }

    // ---------------------------------------------------------------------------------------------
    // e. USER_ID no participa
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 DOCUMENTA LA DECISIÓN DE NO USAR config('app.USER_ID'), en el sentido peligroso: con dos
     * dueños, aunque USER_ID apunte a uno de ellos (que es lo que pasa en una carpeta de una base
     * compartida), NO se desempata a su favor. Se prueba con los dos ids: el resultado tiene que
     * ser el mismo, null, sin importar a cuál apunte.
     *
     * @return void
     */
    public function test_con_dos_duenos_el_user_id_de_la_instancia_no_desempata()
    {
        $this->armar_dos_duenos(self::DIRECCION_ACTIVA, self::OTRA_DIRECCION);

        foreach ([$this->dueno_a_id, $this->dueno_b_id] as $id_al_que_apunta_user_id) {

            config(['app.USER_ID' => $id_al_que_apunta_user_id]);

            $respuesta = $this->pedir_desde_el_frente();

            $respuesta->assertStatus(200);
            $respuesta->assertExactJson(['default_version' => null]);
        }
    }

    /**
     * La otra cara de la misma decisión: con un solo dueño, USER_ID no puede quitarle la
     * respuesta. Apunte a un usuario que no existe, a un empleado o a otro lado, el valor del
     * único dueño se devuelve igual. (Un helper que tomara USER_ID primero, como
     * AsistenteCanalHelper::dueno(), contestaría null acá.)
     *
     * @return void
     */
    public function test_con_un_solo_dueno_el_user_id_de_la_instancia_no_condiciona_la_respuesta()
    {
        $this->armar_un_solo_dueno(self::DIRECCION_ACTIVA, self::OTRA_DIRECCION);

        /** Un id que no existe, y el id de un EMPLEADO (B, que en este escenario ya no es dueño). */
        foreach ([987654321, $this->dueno_b_id] as $id_al_que_apunta_user_id) {

            config(['app.USER_ID' => $id_al_que_apunta_user_id]);

            $respuesta = $this->pedir_desde_el_frente();

            $respuesta->assertStatus(200);
            $respuesta->assertExactJson(['default_version' => self::DIRECCION_ACTIVA]);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // f. Recorte de espacios
    // ---------------------------------------------------------------------------------------------

    /**
     * Valores con espacios alrededor, y lo que tiene que salir: la dirección sin nada pegado.
     *
     * @return array<string, array<int, string>>
     */
    public function valores_con_espacios_alrededor()
    {
        return [
            'espacios a los costados'      => ['  ' . self::DIRECCION_ACTIVA . '  ', self::DIRECCION_ACTIVA],
            'tabulación y salto de línea'  => ["\t" . self::DIRECCION_ACTIVA . "\n", self::DIRECCION_ACTIVA],
        ];
    }

    /**
     * Un valor con espacios alrededor se devuelve recortado: el SPA no tiene que hacer el trim
     * ni arriesgarse a armar una URL con un espacio adentro.
     *
     * @dataProvider valores_con_espacios_alrededor
     * @param  string  $valor_guardado
     * @param  string  $valor_esperado
     * @return void
     */
    public function test_un_valor_con_espacios_alrededor_se_devuelve_recortado($valor_guardado, $valor_esperado)
    {
        $this->armar_un_solo_dueno($valor_guardado);

        $respuesta = $this->pedir_desde_el_frente();

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['default_version' => $valor_esperado]);
    }

    // ---------------------------------------------------------------------------------------------
    // g. Sin ningún dueño
    // ---------------------------------------------------------------------------------------------

    /**
     * Una base donde todos los usuarios son empleados (ninguna fila con `owner_id` null): no hay a
     * quién preguntarle, y tiene que contestar null sin tirar un error (un 500 en la pantalla de
     * login sería justo lo que esta ruta no puede provocar).
     *
     * Se arma con un UPDATE que le pone a TODAS las filas el `owner_id` de A: hasta A queda como
     * "empleado de sí mismo", o sea con `owner_id` no nulo, y no queda ningún dueño.
     *
     * @return void
     */
    public function test_sin_ningun_dueno_responde_null()
    {
        DB::table('users')->update([
            'owner_id'        => $this->dueno_a_id,
            'default_version' => self::DIRECCION_ACTIVA,
        ]);

        $this->assertSame(0, $this->cantidad_de_duenos(), 'El escenario tiene que dejar CERO dueños.');

        $respuesta = $this->pedir_desde_el_frente();

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['default_version' => null]);
    }

    // ---------------------------------------------------------------------------------------------
    // h. Control del propio test
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 CONTROL DE LA EXCLUSIÓN DE LA COOKIE. Prueba que el "sin Set-Cookie" del caso a no es
     * verde por accidente: con las mismas condiciones del test (mismo Origin y Referer del frente,
     * misma configuración de Sanctum y de sesión), una ruta cualquiera del grupo `api` que NO lleva
     * la exclusión SÍ planta la cookie de sesión.
     *
     * Dos pasos:
     *  1. El pedido que arma este archivo es, de verdad, "del frente" para Sanctum. Si no lo fuera,
     *     el middleware nunca arrancaría la sesión y el caso a daría verde sin probar nada.
     *  2. Se registra en caliente una ruta de control en el grupo `api` (el router de cada test es
     *     nuevo: no queda nada registrado después) y se comprueba que emite la cookie de sesión.
     *
     * Si este test se pone rojo el día de mañana, no es un problema de la ruta nueva: es que el
     * entorno de tests dejó de emitir cookies (o de tratar el pedido como del frente), y por lo
     * tanto el caso a perdió los dientes.
     *
     * @return void
     */
    public function test_control_una_ruta_del_grupo_api_sin_la_exclusion_si_planta_la_cookie_de_sesion()
    {
        /** Paso 1: el pedido de estos tests es "del frente" según la propia regla de Sanctum. */
        $pedido_de_prueba = Request::create(self::RUTA, 'GET', [], [], [], [
            'HTTP_ORIGIN'  => 'http://' . self::HOST_DEL_FRENTE,
            'HTTP_REFERER' => 'http://' . self::HOST_DEL_FRENTE . '/login',
        ]);

        $this->assertTrue(
            EnsureFrontendRequestsAreStateful::fromFrontend($pedido_de_prueba),
            'El Origin/Referer de estos tests tiene que contar como "del frente" para Sanctum; si no, el caso a no prueba nada.'
        );

        /** Paso 2: una ruta del grupo `api` sin la exclusión, llamada igual que la nuestra. */
        Route::prefix('api')
            ->middleware('api')
            ->get('zz-control-de-sesion', function () {
                return response()->json(['ok' => true]);
            });

        $respuesta = $this->pedir_desde_el_frente('/api/zz-control-de-sesion');

        $respuesta->assertStatus(200);

        $nombres_de_cookies = [];

        foreach ($respuesta->headers->getCookies() as $cookie) {
            $nombres_de_cookies[] = $cookie->getName();
        }

        $this->assertContains(
            config('session.cookie'),
            $nombres_de_cookies,
            'Una ruta del grupo api sin la exclusión tiene que plantar la cookie de sesión cuando el pedido viene del frente.'
        );
    }
}
