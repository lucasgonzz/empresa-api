<?php

namespace Tests\Feature\Tienda;

use App\Console\Commands\LimpiarImagenPorDefectoDeDesarrollo;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Models\OnlineConfiguration;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Misión "default-article-image-url-de-desarrollo" (15/10/2026): ningún comercio nace con una URL
 * de desarrollo como imagen por defecto de su tienda, y los que ya nacieron con una se limpian.
 *
 * EL CASO REAL. `UserSetupHelper::tienda()` escribía `http://empresa.local:8000/storage/...` en
 * `online_configurations.default_article_image_url` de CADA comercio nuevo. `tienda-spa` usa esa
 * columna como foto de todo artículo sin imagen: la tienda de Masquito (cliente 19) mostraba
 * íconos rotos, "Imagen no encontrada" y contenido mixto (http dentro de https).
 *
 * Qué fija este archivo:
 *
 * 1. El alta de un usuario escribe NULL (y no una URL), y el alta de una demo escribe una https
 *    sin marcas de desarrollo.
 * 2. 🔴 La GUARDA DE LA CLASE: ningún literal de texto de `UserSetupHelper.php` ni de
 *    `DemoSetupHelper.php` nombra un host de desarrollo. Es lo que impide que la clase "URL de
 *    desarrollo en el alta de un comercio" vuelva por otro costado (otro campo, otra función),
 *    no solo por la misma línea. Se escanea con `token_get_all()` y no con `grep`: ambos archivos
 *    tienen comentarios que nombran `empresa.local` justamente para explicar el problema.
 * 3. El comando `tienda:limpiar-imagen-por-defecto-de-desarrollo`: en modo reporte no escribe;
 *    con `--aplicar` limpia las URL de desarrollo (sin distinguir mayúsculas) y SOLO esas; solo
 *    recorre dueños; es idempotente; no pisa un valor que cambió mientras corría; y `--user_id`
 *    acota el barrido.
 *
 * 🔴 NO se llama a `UserSetupHelper::run()` ni a `DemoSetupHelper::run()`: las dos arrancan con
 * `migrate:fresh` y vaciarían la base de testing del slot. Se invocan por reflexión los métodos
 * privados `tienda()`, que son los que escriben la fila.
 *
 * Todo lo que se escribe queda dentro de la transacción de `DatabaseTransactions` (vía
 * `EmpresaTestCase`, que además aborta si la base no es de testing o no es InnoDB, o sea si el
 * rollback fuera un no-op). Los dueños, empleados y configuraciones del test se crean acá con
 * `DB::table()`: sin pasar por eventos de modelo ni por observers.
 *
 * Ningún test sale a internet ni deja un servidor levantado.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group tienda
 * @group imagen_por_defecto_de_desarrollo
 */
class ImagenPorDefectoDeDesarrolloTest extends EmpresaTestCase
{
    /** Firma del comando bajo prueba. */
    const COMANDO = 'tienda:limpiar-imagen-por-defecto-de-desarrollo';

    /** La URL exacta con la que nacieron los comercios afectados (la del caso Masquito). */
    const URL_DEL_CASO = 'http://empresa.local:8000/storage/169705209718205.jpg';

    /** Una imagen real de un cliente: https, en su propio dominio. */
    const URL_REAL = 'https://api.cliente.com.ar/public/storage/logo-del-negocio.jpg';

    /** @var mixed Lo que valía `app.USER_ID` antes del test, para dejarlo como estaba. */
    protected $user_id_original;

    /**
     * Guarda `app.USER_ID`: dos tests lo cambian para que `tienda()` escriba la fila del dueño
     * creado por el test y no una segunda fila del usuario 500 del fixture.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id_original = config('app.USER_ID');
    }

    /**
     * Devuelve `app.USER_ID` a su valor original.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        config(['app.USER_ID' => $this->user_id_original]);

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    //  Helpers de fixture
    // ---------------------------------------------------------------------------------------------

    /**
     * Crea un dueño (`owner_id` nulo) directo en la base, sin observers.
     *
     * @return int  Id del usuario creado.
     */
    protected function crear_dueno()
    {
        return $this->insertar_usuario(null);
    }

    /**
     * Crea un empleado del dueño indicado (`owner_id` no nulo).
     *
     * @param  int  $dueno_id
     * @return int  Id del usuario creado.
     */
    protected function crear_empleado($dueno_id)
    {
        return $this->insertar_usuario($dueno_id);
    }

    /**
     * Inserta un usuario con las únicas columnas NOT NULL sin default de `users` (password y
     * status) y un correo propio del test.
     *
     * @param  int|null  $owner_id
     * @return int
     */
    protected function insertar_usuario($owner_id)
    {
        return DB::table('users')->insertGetId([
            'name'     => 'ZZ Test imagen por defecto',
            'email'    => 'zz-imagen-defecto-' . uniqid('', true) . '@example.test',
            'password' => 'no-se-usa',
            'status'   => 'commerce',
            'owner_id' => $owner_id,
        ]);
    }

    /**
     * Crea la configuración de tienda de un usuario con el valor indicado en la columna.
     *
     * @param  int          $user_id
     * @param  string|null  $url
     * @return int  Id de la `online_configurations` creada.
     */
    protected function crear_configuracion($user_id, $url)
    {
        return DB::table('online_configurations')->insertGetId([
            'user_id'                   => $user_id,
            'default_article_image_url' => $url,
        ]);
    }

    /**
     * Lo que hay hoy en la columna, leído crudo de la base.
     *
     * @param  int  $configuracion_id
     * @return string|null
     */
    protected function url_de($configuracion_id)
    {
        return DB::table('online_configurations')->where('id', $configuracion_id)->value('default_article_image_url');
    }

    /**
     * Corre el comando y devuelve [código de salida, salida].
     *
     * @param  array  $opciones  Por ejemplo ['--aplicar' => true, '--user_id' => 5].
     * @return array
     */
    protected function correr(array $opciones = [])
    {
        $codigo = Artisan::call(self::COMANDO, $opciones);

        return [$codigo, Artisan::output()];
    }

    /**
     * Un contador del resumen ("clave: N") de la salida del comando.
     *
     * @param  string  $salida
     * @param  string  $clave
     * @return int
     */
    protected function contador($salida, $clave)
    {
        $this->assertSame(
            1,
            preg_match('/^' . preg_quote($clave, '/') . ': (\d+)\s*$/m', $salida, $coincidencia),
            'El resumen no trae "' . $clave . ': N". Salida: ' . $salida
        );

        return (int) $coincidencia[1];
    }

    /**
     * Cuántas configuraciones de DUEÑOS tienen hoy una URL de desarrollo en la base, contadas con
     * SQL (LIKE) y no con el predicado del comando: es el oráculo independiente para los tests que
     * barren a todos los dueños, por si la base de testing trajera alguna de antes.
     *
     * @return int
     */
    protected function configuraciones_de_duenos_con_url_de_desarrollo()
    {
        $duenos = DB::table('users')->whereNull('owner_id')->pluck('id')->all();

        return DB::table('online_configurations')
            ->whereIn('user_id', $duenos)
            ->where(function ($query) {
                $query->where('default_article_image_url', 'like', '%empresa.local%')
                      ->orWhere('default_article_image_url', 'like', '%localhost%');
            })
            ->count();
    }

    /**
     * Invoca por reflexión el método privado y estático `tienda()` de un helper de alta.
     *
     * @param  string  $clase
     * @param  array   $argumentos
     * @return void
     */
    protected function invocar_tienda($clase, array $argumentos)
    {
        $metodo = new ReflectionMethod($clase, 'tienda');
        $metodo->setAccessible(true);
        $metodo->invokeArgs(null, $argumentos);
    }

    // ---------------------------------------------------------------------------------------------
    //  1. El alta ya no escribe la URL de desarrollo
    // ---------------------------------------------------------------------------------------------

    /**
     * El alta de un usuario nuevo crea la configuración de la tienda con la imagen por defecto en
     * NULL, no con la URL de la máquina de desarrollo.
     *
     * Se comprueba también que la fila exista y que traiga lo demás que escribe `tienda()`
     * (`register_to_buy`): sin eso, un NULL por "no se creó nada" pasaría como verde.
     *
     * @return void
     */
    public function test_el_alta_de_un_usuario_nuevo_escribe_la_imagen_por_defecto_en_null()
    {
        $dueno = $this->crear_dueno();
        config(['app.USER_ID' => $dueno]);

        $this->invocar_tienda(UserSetupHelper::class, [['facebook' => 'fb.com/negocio', 'instagram' => 'ig.com/negocio']]);

        $filas = OnlineConfiguration::where('user_id', $dueno)->get();

        $this->assertCount(1, $filas, 'tienda() tiene que crear exactamente una configuración para el dueño.');
        $this->assertEquals(1, $filas[0]->register_to_buy, 'La fila tiene que ser la que arma tienda().');
        $this->assertNull($filas[0]->default_article_image_url, 'El alta no puede escribir ninguna URL como imagen por defecto.');
    }

    /**
     * El alta de una demo escribe una imagen por defecto https y sin marcas de desarrollo (es el
     * logo de ComercioCity, a propósito; ver el comentario de `DemoSetupHelper::tienda()`). Se
     * prueba con las dos formas de armar una demo: instancia de demostración o corrida común.
     *
     * @return void
     */
    public function test_el_alta_de_una_demo_escribe_una_https_sin_marcas_de_desarrollo()
    {
        foreach ([true, false] as $es_instancia_de_demostracion) {

            $dueno = $this->crear_dueno();
            config(['app.USER_ID' => $dueno]);

            $this->invocar_tienda(DemoSetupHelper::class, [$es_instancia_de_demostracion]);

            $url = OnlineConfiguration::where('user_id', $dueno)->value('default_article_image_url');

            $this->assertNotEmpty($url, 'La demo nace con una imagen por defecto.');
            $this->assertSame(0, strpos($url, 'https://'), 'La imagen de la demo tiene que ser https: ' . $url);

            foreach (LimpiarImagenPorDefectoDeDesarrollo::MARCAS_DE_DESARROLLO as $marca) {
                $this->assertFalse(stripos($url, $marca), 'La imagen de la demo no puede nombrar "' . $marca . '": ' . $url);
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    //  2. La guarda de la clase
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 La guarda de la clase: ningún literal de texto de los dos helpers de alta nombra un host
     * de desarrollo.
     *
     * El defecto no era "una línea con la URL equivocada" sino "el alta de un comercio puede
     * escribir una URL de la máquina de desarrollo". Mirar solo la línea de `default_article_image_url`
     * dejaría pasar la misma URL en otro campo o en otra función. Por eso se revisan TODOS los
     * literales de los dos archivos.
     *
     * Los comentarios no cuentan (ambos archivos nombran `empresa.local` para explicar el
     * problema); los literales sí. Un NULL no es un literal de texto, así que la línea nueva no
     * dispara la guarda.
     *
     * @return void
     */
    public function test_ningun_literal_de_los_helpers_de_alta_nombra_un_host_de_desarrollo()
    {
        $archivos = [
            'app/Http/Controllers/Helpers/UserSetupHelper.php',
            'app/Http/Controllers/Helpers/DemoSetupHelper.php',
        ];

        foreach ($archivos as $archivo) {

            $codigo = file_get_contents(base_path($archivo));

            $this->assertGreaterThan(
                50,
                count($this->literales_de_texto($codigo)),
                'El escáner casi no encontró literales en ' . $archivo . ': la guarda estaría mirando en el vacío.'
            );

            $this->assertSame(
                [],
                $this->marcas_en_literales($codigo),
                $archivo . ' tiene un literal que nombra un host de desarrollo (línea, marca, literal). '
                . 'El alta de un comercio no puede escribir URL de la máquina de desarrollo.'
            );
        }
    }

    /**
     * La guarda de arriba no pasa en vacío: sobre un código de prueba detecta los literales con
     * marca (en cualquier combinación de mayúsculas, con comillas simples o dobles) e ignora las
     * mismas palabras dentro de comentarios.
     *
     * @return void
     */
    public function test_el_escaner_de_la_guarda_ve_los_literales_y_no_los_comentarios()
    {
        $codigo = implode("\n", [
            '<?php',
            '// un comentario que nombra empresa.local no cuenta',
            '$a = \'http://empresa.local:8000/x\';',
            '$b = "http://LOCALHOST/$z";',
            '/* localhost tampoco cuenta */',
            '$c = \'https://api.cliente.com.ar/x\';',
        ]);

        $encontradas = $this->marcas_en_literales($codigo);

        $this->assertCount(2, $encontradas);
        $this->assertSame([3, 'empresa.local'], array_slice($encontradas[0], 0, 2));
        $this->assertSame([4, 'localhost'], array_slice($encontradas[1], 0, 2));
    }

    /**
     * Los literales de texto de un código PHP: strings entre comillas, y los tramos de texto de
     * los strings con interpolación y de los heredoc. Los comentarios no son literales.
     *
     * @param  string  $codigo
     * @return array  Cada ítem: [línea, texto].
     */
    protected function literales_de_texto($codigo)
    {
        $literales = [];

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE)) {
                $literales[] = [$token[2], $token[1]];
            }
        }

        return $literales;
    }

    /**
     * Los literales de texto que contienen alguna marca de desarrollo (sin distinguir mayúsculas).
     * Las marcas son las del propio comando, para que haya una sola definición de "desarrollo".
     *
     * @param  string  $codigo
     * @return array  Cada ítem: [línea, marca, literal].
     */
    protected function marcas_en_literales($codigo)
    {
        $encontradas = [];

        foreach ($this->literales_de_texto($codigo) as $literal) {
            foreach (LimpiarImagenPorDefectoDeDesarrollo::MARCAS_DE_DESARROLLO as $marca) {
                if (stripos($literal[1], $marca) !== false) {
                    $encontradas[] = [$literal[0], $marca, $literal[1]];
                }
            }
        }

        return $encontradas;
    }

    // ---------------------------------------------------------------------------------------------
    //  3. El predicado
    // ---------------------------------------------------------------------------------------------

    /**
     * Qué cuenta como URL de desarrollo y qué no.
     *
     * @dataProvider casos_del_predicado
     * @param  mixed  $url
     * @param  bool   $esperado
     * @return void
     */
    public function test_es_url_de_desarrollo($url, $esperado)
    {
        $this->assertSame($esperado, LimpiarImagenPorDefectoDeDesarrollo::es_url_de_desarrollo($url));
    }

    /**
     * Tabla de casos del predicado: [valor, es de desarrollo].
     *
     * @return array
     */
    public function casos_del_predicado()
    {
        return [
            'la del caso Masquito'                  => [self::URL_DEL_CASO, true],
            'localhost con puerto'                  => ['http://localhost:8000/storage/foto.jpg', true],
            'localhost sin puerto'                  => ['http://localhost/foto.jpg', true],
            'empresa.local en mayúsculas'           => ['HTTP://EMPRESA.LOCAL/Storage/Foto.JPG', true],
            'localhost en mayúsculas y minúsculas'  => ['http://LocalHost:8000/foto.jpg', true],
            'con espacios alrededor'                => ['  http://empresa.local:8000/foto.jpg  ', true],
            'https real de un cliente'              => [self::URL_REAL, false],
            'el logo https de las demos'            => ['https://api.comerciocity.com/public/storage/comerciocity-logo-cuadrado.webp', false],
            'https cuyo path no tiene la marca'     => ['https://cdn.ejemplo.com/empresa/local/foto.jpg', false],
            'https con guion en vez de punto'       => ['https://cdn.ejemplo.com/empresa-local/foto.jpg', false],
            'null'                                  => [null, false],
            'vacío'                                 => ['', false],
            'solo espacios'                         => ['   ', false],
            'un entero'                             => [123, false],
            'un arreglo'                            => [['empresa.local'], false],
        ];
    }

    /**
     * Las marcas son exactamente las dos del pedido. Si se agrega un host de desarrollo nuevo, este
     * test obliga a decidirlo a propósito (y a sumarle su caso a la tabla de arriba).
     *
     * @return void
     */
    public function test_las_marcas_de_desarrollo_son_empresa_local_y_localhost()
    {
        $this->assertSame(['empresa.local', 'localhost'], LimpiarImagenPorDefectoDeDesarrollo::MARCAS_DE_DESARROLLO);
    }

    // ---------------------------------------------------------------------------------------------
    //  4. El comando
    // ---------------------------------------------------------------------------------------------

    /**
     * Sin --aplicar el comando solo reporta: la fila queda igual, la salida trae el dueño, la
     * configuración y el valor viejo, y avisa que no escribió nada.
     *
     * @return void
     */
    public function test_en_modo_reporte_no_escribe_y_avisa()
    {
        $dueno = $this->crear_dueno();
        $configuracion = $this->crear_configuracion($dueno, self::URL_DEL_CASO);

        list($codigo, $salida) = $this->correr(['--user_id' => $dueno]);

        $this->assertSame(0, $codigo);
        $this->assertSame(self::URL_DEL_CASO, $this->url_de($configuracion), 'El modo reporte no puede escribir.');

        $this->assertSame(1, $this->contador($salida, 'con_url_de_desarrollo'));
        $this->assertSame(0, $this->contador($salida, 'limpiadas'));
        $this->assertStringContainsString(
            '[para_limpiar] user_id=' . $dueno . ' | online_configuration_id=' . $configuracion . ' | valor_viejo=' . self::URL_DEL_CASO,
            $salida,
            'La salida tiene que dejar registro del dueño, la configuración y el valor viejo.'
        );
        $this->assertStringContainsString('no se escribio nada', $salida);
        $this->assertStringNotContainsString('[limpiada]', $salida);
    }

    /**
     * Con --aplicar limpia las tres formas de URL de desarrollo: la del caso, `localhost` y una en
     * mayúsculas. Barre a TODOS los dueños (sin --user_id), así que los contadores del resumen se
     * comparan contra la base y no contra números fijos.
     *
     * @return void
     */
    public function test_con_aplicar_limpia_empresa_local_localhost_y_mayusculas()
    {
        $previas = $this->configuraciones_de_duenos_con_url_de_desarrollo();

        $urls = [
            self::URL_DEL_CASO,
            'http://localhost:8000/storage/foto.jpg',
            'HTTP://EMPRESA.LOCAL/Storage/Foto.JPG',
        ];

        $configuraciones = [];
        foreach ($urls as $url) {
            $dueno = $this->crear_dueno();
            $configuraciones[$dueno] = [$this->crear_configuracion($dueno, $url), $url];
        }

        $duenos_en_total = DB::table('users')->whereNull('owner_id')->count();
        $configuraciones_en_total = DB::table('online_configurations')
            ->whereIn('user_id', DB::table('users')->whereNull('owner_id')->pluck('id')->all())
            ->count();

        list($codigo, $salida) = $this->correr(['--aplicar' => true]);

        $this->assertSame(0, $codigo);

        foreach ($configuraciones as $dueno => $par) {
            $this->assertNull($this->url_de($par[0]), 'No se limpió: ' . $par[1]);
            $this->assertStringContainsString(
                '[limpiada] user_id=' . $dueno . ' | online_configuration_id=' . $par[0] . ' | valor_viejo=' . $par[1],
                $salida
            );
        }

        $this->assertSame($duenos_en_total, $this->contador($salida, 'duenos_revisados'));
        $this->assertSame($configuraciones_en_total, $this->contador($salida, 'configuraciones_revisadas'));
        $this->assertSame($previas + 3, $this->contador($salida, 'con_url_de_desarrollo'));
        $this->assertSame($previas + 3, $this->contador($salida, 'limpiadas'));
        $this->assertSame(0, $this->configuraciones_de_duenos_con_url_de_desarrollo(), 'No tiene que quedar ninguna.');
    }

    /**
     * Lo que NO es una URL de desarrollo queda exactamente como estaba: una https real, una https
     * cuyo path se parece a la marca, un texto vacío y un NULL. Es la mitad del comando que más
     * importa: limpiar de más es borrarle al dueño la imagen que él cargó.
     *
     * @return void
     */
    public function test_no_toca_una_https_real_ni_un_vacio_ni_un_null()
    {
        $intactas = [
            'https real'                => self::URL_REAL,
            'https con path parecido'   => 'https://cdn.ejemplo.com/empresa/local/foto.jpg',
            'texto vacío'               => '',
            'null'                      => null,
        ];

        $configuraciones = [];
        foreach ($intactas as $nombre => $url) {
            $configuraciones[$nombre] = $this->crear_configuracion($this->crear_dueno(), $url);
        }

        list($codigo, $salida) = $this->correr(['--aplicar' => true]);

        $this->assertSame(0, $codigo);

        foreach ($intactas as $nombre => $url) {
            $this->assertSame($url, $this->url_de($configuraciones[$nombre]), 'Se tocó "' . $nombre . '" y no correspondía.');
        }

        foreach ($configuraciones as $id) {
            $this->assertStringNotContainsString('online_configuration_id=' . $id . ' ', $salida);
        }
    }

    /**
     * La configuración de un empleado (`owner_id` no nulo) no se toca, aunque tenga una URL de
     * desarrollo: el comando recorre solo dueños. Ni en el barrido general ni acotando con
     * `--user_id` al id del empleado.
     *
     * @return void
     */
    public function test_no_toca_la_configuracion_de_un_empleado()
    {
        $dueno = $this->crear_dueno();
        $empleado = $this->crear_empleado($dueno);
        $configuracion = $this->crear_configuracion($empleado, self::URL_DEL_CASO);

        $this->correr(['--aplicar' => true]);
        $this->assertSame(self::URL_DEL_CASO, $this->url_de($configuracion), 'El barrido general tocó a un empleado.');

        list($codigo, $salida) = $this->correr(['--aplicar' => true, '--user_id' => $empleado]);

        $this->assertSame(0, $codigo);
        $this->assertSame(self::URL_DEL_CASO, $this->url_de($configuracion), '--user_id con el id de un empleado lo tocó.');
        $this->assertSame(0, $this->contador($salida, 'duenos_revisados'));
        $this->assertSame(0, $this->contador($salida, 'limpiadas'));
    }

    /**
     * Es idempotente: la segunda corrida no encuentra nada, lo dice, y el valor sigue en NULL.
     *
     * @return void
     */
    public function test_es_idempotente()
    {
        $dueno = $this->crear_dueno();
        $configuracion = $this->crear_configuracion($dueno, self::URL_DEL_CASO);

        list(, $primera) = $this->correr(['--aplicar' => true, '--user_id' => $dueno]);

        $this->assertSame(1, $this->contador($primera, 'limpiadas'));
        $this->assertNull($this->url_de($configuracion));

        list($codigo, $segunda) = $this->correr(['--aplicar' => true, '--user_id' => $dueno]);

        $this->assertSame(0, $codigo);
        $this->assertSame(1, $this->contador($segunda, 'configuraciones_revisadas'));
        $this->assertSame(0, $this->contador($segunda, 'con_url_de_desarrollo'));
        $this->assertSame(0, $this->contador($segunda, 'limpiadas'));
        $this->assertStringContainsString('Nada para hacer', $segunda);
        $this->assertNull($this->url_de($configuracion));
    }

    /**
     * 🔴 Compare-and-set: si el dueño cambia la imagen DESPUÉS de que el comando la leyó y ANTES de
     * que la escribiera, el comando no la pisa con NULL.
     *
     * Se provoca el intercalado de verdad, sin tocar el código bajo prueba: un listener de consultas
     * cambia la fila apenas termina el SELECT de `online_configurations` del comando. Cuando el
     * comando llega a su UPDATE, la columna ya no vale lo que leyó y el `WHERE` no encuentra fila.
     * Se verifica que el intercalado ocurrió (`$cambio_hecho`), que la imagen nueva sobrevive y que
     * el comando lo cuenta como no limpiada en vez de decir que limpió.
     *
     * @return void
     */
    public function test_el_compare_and_set_no_pisa_un_valor_que_cambio()
    {
        $dueno = $this->crear_dueno();
        $configuracion = $this->crear_configuracion($dueno, self::URL_DEL_CASO);

        $cambio_hecho = false;

        DB::listen(function ($consulta) use (&$cambio_hecho, $configuracion) {
            if ($cambio_hecho) {
                return;
            }

            $es_la_lectura_del_comando = stripos(ltrim($consulta->sql), 'select') === 0
                && strpos($consulta->sql, 'online_configurations') !== false;

            if (!$es_la_lectura_del_comando) {
                return;
            }

            /* Se marca ANTES de escribir: el UPDATE de abajo también pasa por este listener. */
            $cambio_hecho = true;

            DB::table('online_configurations')
                ->where('id', $configuracion)
                ->update(['default_article_image_url' => self::URL_REAL]);
        });

        list($codigo, $salida) = $this->correr(['--aplicar' => true, '--user_id' => $dueno]);

        $this->assertTrue($cambio_hecho, 'El test no logró intercalar el cambio entre la lectura y la escritura.');
        $this->assertSame(0, $codigo);
        $this->assertSame(self::URL_REAL, $this->url_de($configuracion), 'El comando pisó una imagen que el dueño acababa de cargar.');
        $this->assertSame(1, $this->contador($salida, 'con_url_de_desarrollo'));
        $this->assertSame(0, $this->contador($salida, 'limpiadas'));
        $this->assertStringContainsString('[cambio_mientras_corria]', $salida);
        $this->assertStringNotContainsString('[limpiada]', $salida);
    }

    /**
     * `--user_id` acota el barrido a un solo dueño: el otro, con la misma URL, no se toca.
     *
     * @return void
     */
    public function test_user_id_acota_el_barrido_a_un_solo_dueno()
    {
        $dueno_a = $this->crear_dueno();
        $dueno_b = $this->crear_dueno();
        $configuracion_a = $this->crear_configuracion($dueno_a, self::URL_DEL_CASO);
        $configuracion_b = $this->crear_configuracion($dueno_b, self::URL_DEL_CASO);

        list($codigo, $salida) = $this->correr(['--aplicar' => true, '--user_id' => $dueno_a]);

        $this->assertSame(0, $codigo);
        $this->assertNull($this->url_de($configuracion_a), 'El dueño pedido no se limpió.');
        $this->assertSame(self::URL_DEL_CASO, $this->url_de($configuracion_b), '--user_id no acotó: se limpió otro dueño.');

        $this->assertSame(1, $this->contador($salida, 'duenos_revisados'));
        $this->assertSame(1, $this->contador($salida, 'configuraciones_revisadas'));
        $this->assertSame(1, $this->contador($salida, 'limpiadas'));
    }

    /**
     * Un `--user_id` que no es un entero se rechaza antes de leer nada, con código 1 y sin tocar
     * la base. `(int) "12ab"` daría 12 y con --aplicar el comando escribiría sobre un dueño que
     * nadie pidió.
     *
     * @return void
     */
    public function test_un_user_id_que_no_es_un_entero_corta_sin_tocar_nada()
    {
        $dueno = $this->crear_dueno();
        $configuracion = $this->crear_configuracion($dueno, self::URL_DEL_CASO);

        foreach (['abc', $dueno . 'x', '-' . $dueno, '1.5'] as $invalido) {

            list($codigo, $salida) = $this->correr(['--aplicar' => true, '--user_id' => $invalido]);

            $this->assertSame(1, $codigo, '--user_id=' . $invalido . ' tendría que cortar con código 1.');
            $this->assertStringContainsString('No se hizo nada', $salida);
            $this->assertSame(self::URL_DEL_CASO, $this->url_de($configuracion));
        }
    }

    /**
     * El comando está registrado (lo carga `Kernel::commands()` al escanear la carpeta) y NO está
     * agendado en el scheduler: se corre a mano, por cliente, con Lucas adelante.
     *
     * La comprobación de "no agendado" exige que el scheduler tenga eventos: si estuviera vacío en
     * el entorno de testing, la ausencia no probaría nada.
     *
     * @return void
     */
    public function test_esta_registrado_y_no_esta_agendado()
    {
        $this->assertArrayHasKey(self::COMANDO, Artisan::all());

        $eventos = app(Schedule::class)->events();

        $this->assertNotEmpty($eventos, 'El scheduler no tiene eventos en testing: este chequeo sería en vacío.');

        foreach ($eventos as $evento) {
            $this->assertStringNotContainsString('limpiar-imagen-por-defecto', (string) $evento->command);
        }
    }
}
