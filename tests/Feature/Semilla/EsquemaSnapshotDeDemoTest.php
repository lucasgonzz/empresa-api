<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\EsquemaDemoSnapshotHelper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión M9 (demo-setup-mas-rapido, 13/10/2026): `DemoSetupHelper::run()` restaura un volcado del
 * esquema en vez de correr `migrate:fresh`, y cae al `migrate:fresh` de siempre si algo no cierra.
 *
 * 🔴 POR QUÉ ESTE ARCHIVO NO RESTAURA NADA DE VERDAD, antes de que a alguien le parezca una
 * cobardía: `EsquemaDemoSnapshotHelper::restaurar()` tira las 435 tablas y las vuelve a crear. Hecho
 * contra la base de testing del slot se lleva puesto el fixture de `TestingFerreteriaSeeder` y deja
 * al resto de la suite corriendo contra una base vacía — el mismo motivo por el que
 * `AlineacionLocalDemoTest` no corre `DemoSetupHelper::run()`. Y es DDL, que en MySQL hace commit
 * implícito: ni `DatabaseTransactions` lo revertiría.
 *
 * Lo que sí se prueba acá, que es donde está el riesgo real:
 *
 * 1. **El volcado se genera bien.** `generar()` solo LEE la estructura (`SHOW CREATE TABLE` +
 *    `select migration from migrations`), así que se puede correr contra la base de testing sin
 *    tocarle un dato, y el archivo que deja se puede revisar renglón por renglón.
 * 2. **Los tres caminos de rechazo cortan ANTES de ejecutar una sola sentencia.** Es la propiedad
 *    que hace que el respaldo sea seguro: si `restaurar()` rechazara después de empezar a tirar
 *    tablas, el "respaldo automático" dejaría la base peor que como la encontró. Cada caso verifica
 *    que la cantidad de tablas no se movió.
 * 3. **`verificar()` mira el contenido de `migrations`, no su largo.** Dos conjuntos distintos
 *    pueden tener la misma cantidad de filas.
 * 4. **El comando se niega sobre una base con datos**, que es la única forma de generar un volcado
 *    con filas adentro.
 *
 * El invariante de punta a punta (restaurar y que quede idéntico a un `migrate:fresh`) se verificó a
 * mano contra la base `empresa_demo_m9` el 13/10/2026 y está en el informe de la misión: 435 tablas,
 * 873 filas en `migrations`, todas en `batch` 1, `AUTO_INCREMENT` de `users` en 1 y cero filas de
 * datos.
 *
 * @group semilla
 */
class EsquemaSnapshotDeDemoTest extends EmpresaTestCase
{
    /** @var string[] Archivos temporales a borrar al terminar cada test. */
    private $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }

        $this->temporales = [];

        parent::tearDown();
    }

    /**
     * Ruta temporal propia de este test, con el pid adentro para que dos corridas en paralelo (dos
     * slots, dos agentes) no se pisen.
     *
     * @param string $sufijo
     * @return string
     */
    private function temporal($sufijo)
    {
        $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'esquema-demo-test-' . getmypid() . '-' . $sufijo . '.sql';

        $this->temporales[] = $ruta;

        return $ruta;
    }

    /**
     * Test 1 — dos conjuntos distintos de migraciones dan versiones distintas, y el mismo conjunto
     * da siempre la misma, sin importar en qué orden venga.
     *
     * Es la propiedad de la que depende todo el mecanismo: si dos conjuntos distintos colisionaran,
     * una instancia restauraría el esquema de otra versión y el respaldo nunca se dispararía. Y si
     * el mismo conjunto diera versiones distintas según el orden en que `glob()` devolvió los
     * archivos, el volcado no se encontraría nunca y la demo pagaría el `migrate:fresh` siempre.
     *
     * @return void
     */
    public function test_la_version_distingue_dos_conjuntos_de_migraciones_y_no_depende_del_orden()
    {
        $una = ['2026_01_01_000000_crear_cosas', '2026_02_02_000000_agregar_columna'];
        $otra = array_merge($una, ['2026_03_03_000000_una_mas']);

        $this->assertNotSame(
            EsquemaDemoSnapshotHelper::version_de($una),
            EsquemaDemoSnapshotHelper::version_de($otra),
            'Agregar una migración TIENE que cambiar la versión: es lo único que hace que un volcado '
                .'viejo deje de usarse y el setup caiga a migrate:fresh.'
        );

        $this->assertSame(
            EsquemaDemoSnapshotHelper::version_de($una),
            EsquemaDemoSnapshotHelper::version_de(array_reverse($una)),
            'La versión no puede depender del orden en que vengan los nombres.'
        );

        $version = EsquemaDemoSnapshotHelper::version();

        $this->assertSame(
            12,
            strlen($version),
            'La versión entra en el nombre de un archivo: 12 caracteres, no un sha1 completo.'
        );

        $this->assertSame(
            1,
            preg_match('/^[0-9a-f]{12}$/', $version),
            'La versión tiene que ser hexadecimal: va en un nombre de archivo en Windows y en Linux.'
        );

        $this->assertStringContainsString(
            'esquema-' . $version . '.sql',
            EsquemaDemoSnapshotHelper::ruta(),
            'El nombre del archivo es lo que ata un volcado a su versión.'
        );
    }

    /**
     * Test 2 — el volcado que genera `generar()` tiene la forma que `restaurar()` espera, trae todas
     * las tablas y todas las migraciones, y NO arrastra el `AUTO_INCREMENT`.
     *
     * Lo del `AUTO_INCREMENT` no es cosmético y es la razón por la que este test lo mira: media
     * docena de seeders y toda la aritmética de `semilla:datos` dan por sentado que los ids arrancan
     * en 1 después de un `migrate:fresh`. `AlineacionLocalDemoTest` fija los conceptos de
     * compensación de caja en los ids 7 a 10, por ejemplo. Un volcado tomado de una base que tuvo
     * filas arrastra el contador y corre todos esos ids sin que nada falle ruidoso.
     *
     * @return void
     */
    public function test_el_volcado_trae_todas_las_tablas_las_migraciones_y_sin_auto_increment()
    {
        $ruta = $this->temporal('generado');

        /*
            Lista de tablas con datos VACÍA a propósito: la base del slot tiene el fixture, y sin la
            lista explícita `generar()` se niega (bien) porque `users` tiene filas. Acá se prueba el
            esquema; las filas se prueban en el test 7.
        */
        $resultado = EsquemaDemoSnapshotHelper::generar($ruta, []);

        $this->assertFileExists($ruta);

        $tablas_reales = EsquemaDemoSnapshotHelper::tablas_base();
        $migraciones_en_disco = EsquemaDemoSnapshotHelper::migraciones_en_disco();

        $this->assertSame(count($tablas_reales), $resultado['tablas']);
        $this->assertSame(
            count(EsquemaDemoSnapshotHelper::migraciones_de_la_base()),
            $resultado['migraciones']
        );
        $this->assertSame(EsquemaDemoSnapshotHelper::version(), $resultado['version']);

        $contenido = file_get_contents($ruta);

        $this->assertStringStartsWith(
            EsquemaDemoSnapshotHelper::MARCA,
            $contenido,
            'Sin la marca en la primera línea, restaurar() rechaza el archivo.'
        );

        $this->assertStringContainsString('-- meta: {', $contenido);

        $this->assertStringNotContainsString(
            'AUTO_INCREMENT=',
            $contenido,
            'El volcado no puede arrastrar el contador de autoincremento: los seeders dan por '
                .'sentado que los ids arrancan en 1 después de un migrate:fresh.'
        );

        foreach (['CREATE TABLE `migrations`', 'INSERT INTO `migrations`'] as $esperado) {
            $this->assertStringContainsString(
                $esperado,
                $contenido,
                'El volcado tiene que llevarse la tabla migrations Y su contenido: es la que decide '
                    .'si un `php artisan migrate` posterior vuelve a aplicar algo.'
            );
        }

        /* Una tabla cualquiera del dominio, para que no alcance con llevarse solo `migrations`. */
        $this->assertStringContainsString('CREATE TABLE `articles`', $contenido);

        $cuantos_create = substr_count($contenido, 'CREATE TABLE `');
        $this->assertSame(
            count($tablas_reales),
            $cuantos_create,
            'Tiene que haber un CREATE TABLE por tabla de la base.'
        );

        /*
            Todas las migraciones nombradas en los INSERT, no solo la cantidad. Un volcado al que le
            falta una fila hace que un `migrate` posterior -- el que corre `DeploymentService` en
            cada despliegue -- vuelva a aplicar esa migración sobre tablas que ya existen.
        */
        $faltantes = [];

        foreach ($migraciones_en_disco as $nombre) {
            if (strpos($contenido, "'" . $nombre . "'") === false) {
                $faltantes[] = $nombre;
            }
        }

        $this->assertSame(
            [],
            array_slice($faltantes, 0, 5),
            'Al volcado le faltan migraciones por nombre. Si la lista que falta es la de una '
                .'migración reciente, lo que está atrasada es la base del slot y hay que correr '
                .'`APP_ENV=testing php artisan migrate --force` (NO `migrate:fresh`, que se lleva el '
                .'fixture). Si no, es el volcado el que está mal armado.'
        );

        $this->assertStringContainsString(
            '`batch`) VALUES',
            $contenido,
            'El batch se escribe explícito y normalizado a 1, que es lo que deja un migrate:fresh.'
        );
    }

    /**
     * Test 3 — un volcado que no existe, uno sin la marca y uno con otra cantidad de migraciones se
     * rechazan SIN tocar la base.
     *
     * 🔴 Es el test que sostiene la promesa de "respaldo automático". `DemoSetupHelper` corre
     * `migrate:fresh` cuando esto devuelve `false`, así que un rechazo tiene que ser barato y
     * limpio; lo que no puede pasar es que rechace a mitad de camino, porque entonces el estado
     * intermedio sería una base a medio tirar. Por eso las tres aserciones de "la base no se movió".
     *
     * @return void
     */
    public function test_los_volcados_invalidos_se_rechazan_sin_tocar_la_base()
    {
        $tablas_antes = count(EsquemaDemoSnapshotHelper::tablas_base());
        $migraciones_antes = count(EsquemaDemoSnapshotHelper::migraciones_de_la_base());

        $casos = [];

        /* a) No existe. */
        $casos['inexistente'] = $this->temporal('no-existe-nunca');

        /* b) Existe, pero no es un volcado nuestro. */
        $sin_marca = $this->temporal('sin-marca');
        file_put_contents($sin_marca, "DROP TABLE `articles`\n-- //\n");
        $casos['sin marca'] = $sin_marca;

        /*
            c) Tiene la marca y una meta válida, pero declara otra cantidad de migraciones. Es el
            caso real: un volcado copiado de otra instancia, o pisado a mano, con el nombre de
            archivo de esta versión. El cuerpo trae un DROP a propósito -- si el rechazo no cortara
            antes, la tabla desaparecería y el test lo vería.
        */
        $desalineado = $this->temporal('desalineado');
        file_put_contents(
            $desalineado,
            EsquemaDemoSnapshotHelper::MARCA . "\n"
                . '-- meta: ' . json_encode([
                    'version'     => EsquemaDemoSnapshotHelper::version(),
                    'generado'    => '2026-01-01T00:00:00+00:00',
                    'tablas'      => $tablas_antes,
                    'migraciones' => 1,
                ]) . "\n"
                . EsquemaDemoSnapshotHelper::SEPARADOR . "\n"
                . "DROP TABLE IF EXISTS `articles`\n"
                . EsquemaDemoSnapshotHelper::SEPARADOR . "\n"
        );
        $casos['con otra cantidad de migraciones'] = $desalineado;

        foreach ($casos as $descripcion => $ruta) {
            $resultado = EsquemaDemoSnapshotHelper::restaurar($ruta);

            $this->assertFalse(
                $resultado['ok'],
                'Un volcado ' . $descripcion . ' tiene que rechazarse para que el setup caiga a '
                    . 'migrate:fresh.'
            );

            $this->assertNotSame('', $resultado['motivo'], 'El rechazo tiene que decir por qué.');

            $this->assertSame(
                $tablas_antes,
                count(EsquemaDemoSnapshotHelper::tablas_base()),
                'Rechazar un volcado ' . $descripcion . ' no puede haber tocado la base: el rechazo '
                    . 'tiene que cortar ANTES de ejecutar una sola sentencia.'
            );

            $this->assertSame(
                $migraciones_antes,
                count(EsquemaDemoSnapshotHelper::migraciones_de_la_base()),
                'La tabla migrations tampoco se puede haber movido.'
            );
        }
    }

    /**
     * Test 4 — `verificar()` compara el CONTENIDO de `migrations`, no su largo, y la cantidad de
     * tablas.
     *
     * Dos conjuntos de migraciones del mismo largo pero distintos pasan cualquier chequeo de
     * cantidad y dejan la base mintiendo: una migración que nunca se aplicó queda marcada como
     * hecha, y la columna que agrega no existe nunca.
     *
     * @return void
     */
    public function test_verificar_compara_el_contenido_de_migrations_y_no_solo_la_cantidad()
    {
        $tablas = count(EsquemaDemoSnapshotHelper::tablas_base());
        $en_base = EsquemaDemoSnapshotHelper::migraciones_de_la_base();

        $meta_buena = [
            'version'     => EsquemaDemoSnapshotHelper::version(),
            'tablas'      => $tablas,
            'migraciones' => count($en_base),
        ];

        $ok = EsquemaDemoSnapshotHelper::verificar($meta_buena, $en_base);

        $this->assertTrue(
            $ok['ok'],
            'Con la base tal como está y la lista que tiene adentro, verificar() tiene que pasar. '
                . 'Motivo: ' . $ok['motivo']
        );
        $this->assertSame($tablas, $ok['tablas']);

        /* Mismo largo, un nombre cambiado. */
        $mismo_largo = $en_base;
        $mismo_largo[0] = 'zzzz_una_migracion_que_no_existe';

        $cambiada = EsquemaDemoSnapshotHelper::verificar($meta_buena, $mismo_largo);

        $this->assertFalse(
            $cambiada['ok'],
            'Un conjunto del mismo largo pero distinto tiene que fallar: comparar la cantidad no '
                . 'alcanza.'
        );
        $this->assertStringContainsString('migrations no quedó idéntica', $cambiada['motivo']);

        /* La cantidad de tablas declarada no coincide con la que hay. */
        $meta_corta = $meta_buena;
        $meta_corta['tablas'] = $tablas - 1;

        $trunca = EsquemaDemoSnapshotHelper::verificar($meta_corta, $en_base);

        $this->assertFalse(
            $trunca['ok'],
            'Una restauración que dejó otra cantidad de tablas que la declarada es una restauración '
                . 'cortada por la mitad.'
        );
    }

    /**
     * Test 5 — el comando se niega sobre una base con datos y no escribe nada.
     *
     * La base de testing tiene el fixture de `TestingFerreteriaSeeder`, así que este test corre
     * justamente contra el caso que hay que impedir: un volcado generado desde una base con filas
     * llevaría el `AUTO_INCREMENT` corrido de cada tabla que tuvo datos.
     *
     * @return void
     */
    public function test_el_comando_se_niega_si_la_base_tiene_datos()
    {
        $ruta = $this->temporal('desde-base-con-datos');

        $codigo = Artisan::call('demo:snapshot-esquema', ['--salida' => $ruta]);

        $this->assertSame(
            1,
            $codigo,
            'La base de testing tiene el fixture sembrado: el comando tiene que negarse.'
        );

        $this->assertFileDoesNotExist(
            $ruta,
            'Al negarse no puede haber dejado un archivo a medias con nombre de volcado válido.'
        );

        $this->assertStringContainsStringIgnoringCase('recién migrada', Artisan::output());
    }

    /**
     * Test 6 — `generar()` se niega si la tabla `migrations` de la base no es la lista que hay en
     * disco.
     *
     * 🔴 Lo encontró este mismo archivo el 13/10/2026, con la base del slot atrasada una migración:
     * el nombre del archivo sale del DISCO y el contenido de la BASE, así que un volcado hecho sobre
     * una base atrasada queda etiquetado como el de la versión nueva llevando adentro el esquema de
     * la vieja. La verificación de `restaurar()` lo agarra y cae a `migrate:fresh`, o sea que no
     * rompe nada — pero ese archivo no sirve nunca y cada setup paga una restauración al vacío antes
     * de migrar igual.
     *
     * Se simula borrando una fila de `migrations` adentro de la transacción del test, que
     * `DatabaseTransactions` revierte al terminar. No hay DDL de por medio.
     *
     * @return void
     */
    public function test_generar_se_niega_si_la_base_no_esta_en_el_punto_del_codigo()
    {
        $en_disco = EsquemaDemoSnapshotHelper::migraciones_en_disco();

        $this->assertNotEmpty($en_disco, 'Sin migraciones en disco este test no prueba nada.');

        $una = $en_disco[count($en_disco) - 1];

        $borradas = \Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration', $una)
            ->delete();

        $this->assertSame(
            1,
            $borradas,
            'La base del slot no tiene la migración ' . $una . ': corré '
                . '`APP_ENV=testing php artisan migrate --force` antes de la suite.'
        );

        $ruta = $this->temporal('base-atrasada');

        try {
            EsquemaDemoSnapshotHelper::generar($ruta);

            $this->fail('generar() tenía que negarse con la base atrasada.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($una, $e->getMessage());
            $this->assertStringContainsString('migrate --force', $e->getMessage());
        }

        $this->assertFileDoesNotExist(
            $ruta,
            'Al negarse no puede haber dejado el archivo: se chequea ANTES de abrirlo.'
        );
    }

    /**
     * Test 7 — el volcado se lleva las filas que dejan las migraciones, y se reponen idénticas.
     *
     * 🔴 Lo encontró el retome de la misión el 15/10/2026: hay migraciones que INSERTAN filas
     * (`platforms` 4, `meli_order_statuses` 2, `payment_method_types` 1, `online_templates` 1,
     * medidas sobre `empresa_demo_m9` recién migrada). Un volcado de solo DDL las perdía y la base
     * restaurada no era la de un `migrate:fresh`: `meli_order_statuses` no la repone ningún seeder y
     * las otras quedaban con otros ids.
     *
     * Sin DDL de por medio (que haría commit implícito): las filas se siembran, se borran y se
     * reponen con `DELETE`/`INSERT` adentro de la transacción del test.
     *
     * @return void
     */
    public function test_el_volcado_se_lleva_las_filas_de_las_migraciones_y_las_repone_identicas()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            DB::table('platforms')->delete();

            DB::table('platforms')->insert([
                [
                    'id'            => 7,
                    'slug'          => 'prueba-uno',
                    'name'          => "O'Reilly \\ con barra y comilla",
                    'client_id'     => null,
                    'client_secret' => null,
                    'extra_config'  => json_encode(['a' => "x'y", 'b' => [1, 2]]),
                    'created_at'    => '2026-10-15 10:00:00',
                    'updated_at'    => null,
                ],
                [
                    'id'            => 9,
                    'slug'          => 'prueba-dos',
                    'name'          => 'Ñandú con acento',
                    'client_id'     => 'abc',
                    'client_secret' => null,
                    'extra_config'  => null,
                    'created_at'    => null,
                    'updated_at'    => null,
                ],
            ]);

            $antes = $this->filas_de('platforms');

            $ruta = $this->temporal('con-filas');
            $resultado = EsquemaDemoSnapshotHelper::generar($ruta, ['platforms']);

            $this->assertSame(['platforms' => 2], $resultado['filas']);

            $contenido = file_get_contents($ruta);
            $this->assertStringContainsString('INSERT INTO `platforms`', $contenido);
            $this->assertStringContainsString('"filas":{"platforms":2}', $contenido);

            DB::table('platforms')->delete();

            $volcado = EsquemaDemoSnapshotHelper::leer_volcado($ruta);
            $this->assertSame(['platforms'], array_keys($volcado['datos']));

            foreach ($volcado['datos']['platforms'] as $sentencia) {
                DB::unprepared($sentencia);
            }

            $this->assertSame(
                $antes,
                $this->filas_de('platforms'),
                'Las filas repuestas tienen que ser idénticas, ids incluidos: los seeders y la tienda '
                    . 'buscan estas filas por id o por slug.'
            );
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Test 8 — sin lista explícita, `generar()` se niega sobre una base que ya tiene usuarios: sus
     * filas son del negocio, no de las migraciones.
     *
     * @return void
     */
    public function test_generar_se_niega_si_la_base_ya_tiene_usuarios()
    {
        $this->assertGreaterThan(0, DB::table('users')->count(), 'El fixture del slot trae usuarios.');

        $ruta = $this->temporal('base-usada');

        try {
            EsquemaDemoSnapshotHelper::generar($ruta);

            $this->fail('generar() tenía que negarse con usuarios en la base.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('recién migrada', $e->getMessage());
        }

        $this->assertFileDoesNotExist($ruta);
    }

    /**
     * Test 9 — `verificar()` cuenta las filas por tabla y rechaza filas en tablas que el volcado no
     * trae (lo que dejaría el camino rápido si no vaciara algo del lead anterior).
     *
     * @return void
     */
    public function test_verificar_cuenta_las_filas_de_las_migraciones()
    {
        $tablas = count(EsquemaDemoSnapshotHelper::tablas_base());
        $en_base = EsquemaDemoSnapshotHelper::migraciones_de_la_base();

        $meta = [
            'version'     => EsquemaDemoSnapshotHelper::version(),
            'tablas'      => $tablas,
            'migraciones' => count($en_base),
        ];

        $con_filas = array_values(array_diff(
            EsquemaDemoSnapshotHelper::tablas_con_filas(EsquemaDemoSnapshotHelper::tablas_base()),
            ['migrations']
        ));

        $todas = [];

        foreach ($con_filas as $tabla) {
            $todas[$tabla] = DB::table($tabla)->count();
        }

        $meta['filas'] = $todas;
        $ok = EsquemaDemoSnapshotHelper::verificar($meta, $en_base);
        $this->assertTrue($ok['ok'], 'Con las cuentas reales tiene que pasar. Motivo: ' . $ok['motivo']);

        $una = $con_filas[0];
        $meta['filas'][$una] = $todas[$una] + 1;
        $mal_contada = EsquemaDemoSnapshotHelper::verificar($meta, $en_base);
        $this->assertFalse($mal_contada['ok']);
        $this->assertStringContainsString('`' . $una . '`', $mal_contada['motivo']);

        $meta['filas'] = [];
        $sobran = EsquemaDemoSnapshotHelper::verificar($meta, $en_base);
        $this->assertFalse($sobran['ok']);
        $this->assertStringContainsString('quedaron filas en tablas que el volcado no trae', $sobran['motivo']);
    }

    /**
     * Test 10 — en la suite, el volcado POR DEFECTO no se restaura nunca.
     *
     * 🔴 Lo encontró el retome del 15/10/2026: los tests del setup protegen la base del slot
     * mockeando `migrate:fresh`, y la restauración no pasa por Artisan. Con un volcado válido en
     * `storage/demo` la suite vaciaba el fixture (82 rojos en `tests/Feature/Semilla`).
     *
     * @return void
     */
    public function test_en_la_suite_no_se_restaura_el_volcado_por_defecto()
    {
        $tablas_antes = count(EsquemaDemoSnapshotHelper::tablas_base());
        $usuarios_antes = DB::table('users')->count();

        $resultado = EsquemaDemoSnapshotHelper::restaurar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('suite', $resultado['motivo']);
        $this->assertSame($tablas_antes, count(EsquemaDemoSnapshotHelper::tablas_base()));
        $this->assertSame($usuarios_antes, DB::table('users')->count());
    }

    /**
     * Las filas de una tabla, ordenadas por id y como arrays, para comparar antes y después.
     *
     * @param string $tabla
     * @return array<int,array<string,mixed>>
     */
    private function filas_de($tabla)
    {
        return array_map(function ($fila) {
            return (array) $fila;
        }, DB::table($tabla)->orderBy('id')->get()->all());
    }
}
