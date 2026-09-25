<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Misión redireccion-version-antes-del-login (24/9/2026): `GET /api/version-activa`, el endpoint
 * PÚBLICO que el SPA consulta apenas carga la aplicación (con o sin sesión iniciada, y también en
 * /demo/ingreso y /informe/{token}) para saber cuál es la dirección del sistema activo y poder
 * mandar al negocio a la versión actual ANTES de que inicie sesión, sin esperar a que escriba su
 * documento y su clave en el frente viejo. Ver VersionActivaHelper y VersionActivaController.
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
 *  h. 🔴 LA REGLA ES LA CANTIDAD DE DUEÑOS, NO EL CONTENIDO: dos dueños donde solo uno tiene la
 *     dirección cargada (null, cadena vacía o solo espacios en el otro, y en los dos órdenes)
 *     también contestan null. Un helper que contara solo a los dueños CON dirección vería "un
 *     único dueño" y mandaría al que entra al frente equivocado, y pasaba los casos a a g. Son los
 *     casos reales de la base `fenix` (Fenix y Galván, dos dueños) y de Golonorte (dos dueños: el
 *     800, activo, y el 801, sin ventas).
 *  i. 🔴 UNA BASE A LA QUE LE FALTA LA COLUMNA `default_version`: 200 con null y NO 500. La columna
 *     entró editando la migración base de `users` (commit 2cd7a3c9, 27/11/2024) sin una migración
 *     propia, así que una base creada antes puede no tenerla. Sin el catch de QueryException del
 *     helper, cada carga de la aplicación de ese cliente daría 500, con un Log::error por carga. Se
 *     simula sin tocar la MySQL de testing (ver con_una_base_a_la_que_le_falta_la_columna(), y por
 *     qué NO se usa una sqlite en memoria).
 *  j. Un control del propio test (el último): una ruta cualquiera del grupo `api`, SIN la
 *     exclusión de la ruta nueva, sí emite la cookie de sesión cuando el pedido viene del frente.
 *     Sin ese control, el "no trae Set-Cookie" del caso a podría dar verde por una razón
 *     equivocada (un entorno donde el pedido nunca se considera "del frente", o donde la sesión
 *     no emite cookie) y la exclusión `withoutMiddleware` quedaría sin cubrir.
 *
 * SOBRE `Set-Cookie`: la ruta se excluye de EnsureFrontendRequestsAreStateful (de Sanctum), que
 * arranca la sesión y emite la cookie cada vez que el pedido trae un Origin o un Referer de un
 * dominio "stateful". La llama cualquier visitante en CADA carga de la aplicación, con o sin
 * sesión, así que sin la exclusión cada carga anónima crearía una sesión en el servidor y le
 * plantaría una cookie al navegador. Por eso el pedido de estos tests viene con el Origin y el
 * Referer del frente (el caso difícil): un pedido sin esos headers ni siquiera activaría el
 * middleware y el caso no probaría nada.
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

    /** Ruta pública que consulta el SPA apenas carga la aplicación (el grupo `api` le pone el prefijo). */
    const RUTA = '/api/version-activa';

    /** Nombre de la conexión que simula "una base a la que le falta la columna default_version". */
    const CONEXION_SIN_COLUMNA = 'zz_base_sin_default_version';

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
     * Hace el pedido como lo hace el SPA del frente viejo al cargar la aplicación: anónimo (sin
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

    /**
     * Corre `$prueba` con una conexión por defecto que se comporta como una base a la que le FALTA
     * la columna `users.default_version`, y restaura la conexión original al terminar (try/finally).
     *
     * CÓMO: se registra en el DatabaseManager una conexión de simulación que es un MySqlConnection
     * montado sobre el MISMO PDO que la conexión real (mismas tablas, misma transacción de
     * DatabaseTransactions: todo se lee igual) y que cambia una sola cosa. La consulta que NOMBRA
     * `default_version` falla como fallaría en MySQL sin esa columna: mismo SQLSTATE (42S22), mismo
     * mensaje y por el mismo camino de Laravel, porque Connection::run() envuelve el PDOException en
     * una QueryException igual que en producción. Todo lo demás pasa derecho a MySQL. Falla si y solo
     * si el SQL nombra la columna, que es lo que le pasaría a una base sin ella.
     *
     * 🔴 POR QUÉ NO UNA SQLITE EN MEMORIA, que sería lo natural: el SQLite de este PHP (3.31.1) tiene
     * activado el "DQS", así que un identificador entre comillas dobles que no existe como columna
     * se toma como un literal de texto en vez de fallar, y Laravel envuelve las columnas de SQLite
     * con comillas dobles. Medido: `select "id", "default_version" from "users"` sobre una tabla sin
     * esa columna NO tira "no such column", devuelve el texto "default_version". Un test armado así
     * daba verde con o sin el catch del helper.
     *
     * Y no hay DDL: ni la MySQL de testing ni ningún archivo se tocan. La conexión de simulación se
     * descarta al terminar, y `database.default` vuelve a su valor ANTES de que termine el test:
     * DatabaseTransactions hace el rollback contra la conexión por defecto del momento, así que si
     * quedara puesta la de simulación la transacción real no se revertiría.
     *
     * @param  callable  $prueba  Lo que se corre con la simulación puesta.
     * @return mixed  Lo que devuelva $prueba.
     */
    protected function con_una_base_a_la_que_le_falta_la_columna(callable $prueba)
    {
        // La conexión real (la MySQL de testing, con la transacción abierta) y su nombre.
        $nombre_de_la_conexion_real = config('database.default');
        $conexion_real = DB::connection();

        // La simulación se resuelve la primera vez que alguien pide esa conexión (DB::extend).
        DB::extend(self::CONEXION_SIN_COLUMNA, function ($config, $nombre) use ($conexion_real) {

            return new class(
                $conexion_real->getPdo(),
                $conexion_real->getDatabaseName(),
                $conexion_real->getTablePrefix(),
                $conexion_real->getConfig()
            ) extends MySqlConnection {

                /**
                 * Falla como MySQL sin la columna si el SQL nombra `default_version`; si no, va
                 * derecho a la MySQL real.
                 *
                 * @param  string  $query
                 * @param  array  $bindings
                 * @param  bool  $useReadPdo
                 * @return array
                 */
                public function select($query, $bindings = [], $useReadPdo = true)
                {
                    if (strpos($query, 'default_version') === false) {

                        return parent::select($query, $bindings, $useReadPdo);
                    }

                    return $this->run($query, $bindings, function () {

                        // El error que da MySQL por una columna que no existe.
                        $error = new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'default_version' in 'field list'");
                        $error->errorInfo = ['42S22', 1054, "Unknown column 'default_version' in 'field list'"];

                        throw $error;
                    });
                }
            };
        });

        // La simulación pasa a ser la conexión por defecto: la que usan el modelo User y el endpoint.
        config([
            'database.connections.' . self::CONEXION_SIN_COLUMNA => ['driver' => 'mysql'],
            'database.default'                                   => self::CONEXION_SIN_COLUMNA,
        ]);

        try {

            return $prueba();

        } finally {

            // Vuelve la conexión real, y se descarta la simulación junto con su configuración.
            config(['database.default' => $nombre_de_la_conexion_real]);
            DB::purge(self::CONEXION_SIN_COLUMNA);

            $conexiones = config('database.connections');
            unset($conexiones[self::CONEXION_SIN_COLUMNA]);
            config(['database.connections' => $conexiones]);
        }
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
    // h. La regla es la cantidad de dueños, no el contenido
    // ---------------------------------------------------------------------------------------------

    /**
     * Dos dueños donde SOLO UNO tiene una dirección utilizable, en los dos órdenes (el que la tiene
     * puede ser el de id más bajo o el de id más alto) y con las tres formas de "sin cargar" del
     * otro: null, cadena vacía y solo espacios.
     *
     * @return array<string, array<int, string|null>>
     */
    public function duenos_donde_solo_uno_tiene_direccion()
    {
        return [
            'el primero con dirección, el segundo sin cargar (null)'  => [self::DIRECCION_ACTIVA, null],
            'el primero sin cargar (null), el segundo con dirección'  => [null, self::DIRECCION_ACTIVA],
            'el primero con dirección, el segundo con cadena vacía'   => [self::DIRECCION_ACTIVA, ''],
            'el primero con cadena vacía, el segundo con dirección'   => ['', self::DIRECCION_ACTIVA],
            'el primero con dirección, el segundo solo con espacios'  => [self::DIRECCION_ACTIVA, '   '],
            'el primero solo con espacios, el segundo con dirección'  => ['   ', self::DIRECCION_ACTIVA],
        ];
    }

    /**
     * 🔴 LA REGLA ES LA CANTIDAD DE DUEÑOS, NO EL CONTENIDO. Con dos dueños se contesta null aunque
     * solo uno de los dos tenga una dirección cargada. Un helper que contara únicamente a los
     * dueños CON dirección vería "un único dueño" y devolvería esa dirección: mandaría al que entra
     * al frente de ese negocio sin saber si es el suyo. Con dos dueños en la base no hay forma de
     * saberlo antes de que escriba su documento, y esa es toda la regla.
     *
     * No es un caso de laboratorio, son los casos reales del parque: la base `fenix` (Fenix y
     * Galván, dos dueños) y la de Golonorte (dos dueños: el 800, activo, y el 801, sin ventas, que
     * nadie cargó pero que existe y podría estar entrando).
     *
     * @dataProvider duenos_donde_solo_uno_tiene_direccion
     * @param  string|null  $default_version_de_a  El dueño de id más bajo.
     * @param  string|null  $default_version_de_b  El dueño de id más alto.
     * @return void
     */
    public function test_con_dos_duenos_donde_solo_uno_tiene_direccion_responde_null($default_version_de_a, $default_version_de_b)
    {
        $this->armar_dos_duenos($default_version_de_a, $default_version_de_b);

        $respuesta = $this->pedir_desde_el_frente();

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['default_version' => null]);
    }

    // ---------------------------------------------------------------------------------------------
    // i. Una base a la que le falta la columna default_version
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 UNA BASE CREADA ANTES DE QUE `users.default_version` EXISTIERA (la columna entró editando la
     * migración base, sin una migración propia): el endpoint responde 200 con null y NO 500.
     *
     * Sin el catch de QueryException del helper, cada carga de la aplicación de ese cliente
     * terminaría en un 500 con su Log::error (y, en producción, una escritura de
     * error_throttle.json) por cada persona que abre el sistema.
     *
     * El escenario de datos es el del caso a (UN dueño con una dirección cargada), a propósito: en
     * una base sana este mismo escenario devuelve la dirección, así que el null no lo puede explicar
     * ninguna regla de dueños. Lo explica solamente que la consulta falla y el helper la absorbe.
     *
     * Antes de llamar al endpoint se comprueban tres precondiciones, para que el verde no sea
     * accidental: (1) sin la simulación el escenario SÍ devuelve la dirección; (2) con la
     * simulación, la consulta del helper falla de verdad con "Unknown column 'default_version'";
     * (3) con la simulación, lo que no nombra la columna sigue andando.
     *
     * `withoutExceptionHandling()`: si el helper dejara escapar la excepción, el test se corta con
     * ella y con su mensaje real, en vez de pasar por el handler (que la escribiría en el
     * laravel.log y, en producción, la mandaría al reporte de errores). Además se comprueba con un
     * espía del Log que el helper tampoco loguea el problema por su cuenta.
     *
     * @return void
     */
    public function test_una_base_sin_la_columna_default_version_responde_null_y_no_500()
    {
        $this->armar_un_solo_dueno(self::DIRECCION_ACTIVA);

        /** Precondición 1: sin la simulación, este escenario devuelve la dirección. */
        $this->pedir_desde_el_frente()->assertExactJson(['default_version' => self::DIRECCION_ACTIVA]);

        $this->con_una_base_a_la_que_le_falta_la_columna(function () {

            /** Precondición 2: con la simulación, la consulta del helper falla como en MySQL sin la columna. */
            $this->assertSame(self::CONEXION_SIN_COLUMNA, DB::getDefaultConnection());

            try {
                User::whereNull('owner_id')->orderBy('id')->limit(2)->get(['id', 'default_version']);

                $this->fail('La simulación tiene que hacer fallar la consulta que nombra la columna default_version.');
            } catch (QueryException $e) {
                $this->assertStringContainsString("Unknown column 'default_version'", $e->getMessage());
                $this->assertSame('42S22', $e->errorInfo[0]);
            }

            /** Precondición 3: lo que no nombra la columna sigue funcionando, o sea que la falla es solo esa. */
            $this->assertSame(1, (int) DB::selectOne('select 1 as uno')->uno);

            /** El endpoint, sin handler que lo tape y con un espía del Log. */
            $this->withoutExceptionHandling();
            Log::spy();

            $respuesta = $this->pedir_desde_el_frente();

            $respuesta->assertStatus(200);
            $respuesta->assertExactJson(['default_version' => null]);

            /** Sin loguear: ni el helper ni nadie escribe un problema por esta falta de columna. */
            foreach (['emergency', 'alert', 'critical', 'error', 'warning'] as $nivel_de_problema) {
                Log::shouldNotHaveReceived($nivel_de_problema);
            }
        });

        /** La conexión original volvió: lo que sigue del test (y el rollback) corre contra la MySQL real. */
        $this->assertNotSame(self::CONEXION_SIN_COLUMNA, DB::getDefaultConnection());
        $this->assertSame(1, $this->cantidad_de_duenos());
    }

    // ---------------------------------------------------------------------------------------------
    // j. Control del propio test
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
