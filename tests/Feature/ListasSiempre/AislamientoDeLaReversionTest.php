<?php

namespace Tests\Feature\ListasSiempre;

require_once __DIR__.'/RollbackHistorialMigracionTest.php';

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\SkippedTestError;
use Tests\Fakes\EntornoSinCredenciales;

/**
 * Regresiones de seguridad del arnés de las reversiones RR (Historial, Exacta y Trazable).
 *
 * 🔴 POR QUÉ EXISTE (20/11/2026). Los tres archivos de reversión se escribieron para correr UNA vez en un contenedor
 * privado (lane147): saltaban salvo con `ROLLBACK_SQL_GATE=lane147-liberado` y una base de nombre fijo, armaban el DSN por
 * un socket que `cc-db` no tiene, y lanzaban procesos hijos con el entorno heredado (sin las credenciales de mentira). El
 * gate del candidato 4.3.69 los vio como 22 casos saltados y un rojo en `SalidaAInternetDeLosTestsTest`.
 *
 * Qué fija este archivo, sin tocar ninguna aserción de los 22 casos:
 *  - la guarda de "no producción" es PRECISA (rechaza todo origen que no sea una base de testing del slot) y nunca saltea;
 *  - el arnés corre cada clase sobre una base temporal propia `<origen>_test_rev_<8 hex>` y la borra al terminar, y sólo
 *    puede borrar la que creó él;
 *  - un proceso hijo recibe las credenciales de mentira (aunque el padre tenga una "real" o una vacía) y la base temporal.
 *
 * Esta clase NO hereda del arnés a propósito: si lo hiciera, mientras el arnés saltea (el defecto que se corrige) estos
 * casos también saltearían y no serían rojos. Las pruebas que necesitan el arnés real lo ejecutan con las sondas de abajo
 * (`runBare()`), y un salto de la sonda cuenta como FALLA de esta prueba.
 */
class AislamientoDeLaReversionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Orígenes que la guarda tiene que rechazar: producción, bases de otro propósito y cualquier intento de colar SQL.
     *
     * @return array<string, array{0: string}>
     */
    public function origenes_rechazados()
    {
        return [
            'base de la aplicación' => ['empresa'],
            'producción' => ['empresa_produccion'],
            'otro producto' => ['comerciocity'],
            'la base fija de lane147' => ['empresa_testing_rollback_20261119'],
            'inyección con backtick' => ['empresa_testing_s67`; DROP'],
            'mayúsculas' => ['EMPRESA_TESTING_S67'],
            'guiones' => ['empresa-testing-s67'],
            'vacío' => [''],
            'salto de línea al final' => ["empresa_testing_s67\n"],
            'espacio al final' => ['empresa_testing_s67 '],
            'slot de otro repo' => ['admin_testing_s67'],
            'slot de tienda' => ['tienda_testing_s67'],
            'ya es una temporal' => ['empresa_testing_s67_test_rev_0a1b2c3d'],
            'partición sin número' => ['empresa_testing_s67_test_'],
            'slot sin número' => ['empresa_testing_s'],
        ];
    }

    /**
     * La guarda de origen rechaza todo lo que no sea una base de testing de empresa.
     *
     * @dataProvider origenes_rechazados
     * @param string $origen Nombre de base que NO debe servir de origen.
     * @return void
     */
    public function test_la_guarda_rechaza_bases_que_no_son_de_testing_del_slot($origen)
    {
        $this->expectException(\InvalidArgumentException::class);

        RollbackIntegracionTestCase::nombre_base_temporal($origen, '0a1b2c3d');
    }

    /**
     * Sufijos que no son 8 hexadecimales en minúscula: la guarda los rechaza aunque el origen sea válido.
     *
     * @return array<string, array{0: string}>
     */
    public function sufijos_rechazados()
    {
        return [
            'mayúsculas' => ['0A1B2C3D'],
            'siete' => ['0a1b2c3'],
            'nueve' => ['0a1b2c3d4'],
            'no hexadecimal' => ['zzzzzzzz'],
            'vacío' => [''],
            'con backtick' => ['0a1b2c`d'],
            'salto de línea' => ["0a1b2c3\n"],
        ];
    }

    /**
     * @dataProvider sufijos_rechazados
     * @param string $hex Sufijo inválido.
     * @return void
     */
    public function test_la_guarda_rechaza_sufijos_que_no_son_ocho_hexadecimales($hex)
    {
        $this->expectException(\InvalidArgumentException::class);

        RollbackIntegracionTestCase::nombre_base_temporal('empresa_testing_s67', $hex);
    }

    /**
     * Orígenes válidos (legado, slot, partición, token de ParaTest) y la temporal que corresponde a cada uno.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function origenes_aceptados()
    {
        return [
            'legado sin slot' => ['empresa_testing', 'empresa_testing_test_rev_0a1b2c3d'],
            'slot' => ['empresa_testing_s67', 'empresa_testing_s67_test_rev_0a1b2c3d'],
            'partición' => ['empresa_testing_s67_test_2', 'empresa_testing_s67_test_2_test_rev_0a1b2c3d'],
            'partición de dos cifras' => ['empresa_testing_s67_test_12', 'empresa_testing_s67_test_12_test_rev_0a1b2c3d'],
        ];
    }

    /**
     * La guarda no es un "no" a todo: acepta los orígenes de testing y arma la temporal dentro del GRANT `<slot>\_test\_%`.
     *
     * @dataProvider origenes_aceptados
     * @param string $origen   Base de origen válida.
     * @param string $esperada Nombre temporal esperado para el hex fijo `0a1b2c3d`.
     * @return void
     */
    public function test_la_guarda_acepta_los_origenes_de_testing_y_arma_la_temporal($origen, $esperada)
    {
        $this->assertSame($esperada, RollbackIntegracionTestCase::nombre_base_temporal($origen, '0a1b2c3d'));
        $this->assertLessThanOrEqual(64, strlen($esperada));
    }

    /**
     * Casos de la guarda de borrado: [base a borrar, bases que creó este proceso, ¿se puede borrar?].
     *
     * @return array<string, array{0: string, 1: array, 2: bool}>
     */
    public function casos_de_borrado()
    {
        $temporal = 'empresa_testing_s67_test_rev_0a1b2c3d';

        return [
            'la que creó este proceso' => [$temporal, [$temporal], true],
            'la de una partición, creada por el proceso' => ['empresa_testing_s67_test_2_test_rev_0a1b2c3d', ['empresa_testing_s67_test_2_test_rev_0a1b2c3d'], true],
            'el origen' => ['empresa_testing_s67', [$temporal], false],
            'el origen aunque figure como creado' => ['empresa_testing_s67', ['empresa_testing_s67'], false],
            'la partición del slot' => ['empresa_testing_s67_test_2', [$temporal], false],
            'la partición aunque figure como creada' => ['empresa_testing_s67_test_2', ['empresa_testing_s67_test_2'], false],
            'una temporal que no creó este proceso' => ['empresa_testing_s67_test_rev_ffffffff', [$temporal], false],
            'una temporal con registro vacío' => [$temporal, [], false],
            'sufijo corto aunque figure como creado' => ['empresa_testing_s67_test_rev_0a1b2c3', ['empresa_testing_s67_test_rev_0a1b2c3'], false],
            'sufijo en mayúsculas aunque figure como creado' => ['empresa_testing_s67_test_rev_0A1B2C3D', ['empresa_testing_s67_test_rev_0A1B2C3D'], false],
            'una base del sistema' => ['mysql', ['mysql'], false],
            'la base de otro slot' => ['empresa_testing_s44', ['empresa_testing_s44'], false],
            'una de producción con sufijo temporal' => ['empresa_produccion_test_rev_0a1b2c3d', ['empresa_produccion_test_rev_0a1b2c3d'], false],
        ];
    }

    /**
     * El arnés sólo borra la base temporal que creó él y que cumple el patrón; nunca el origen, ni una partición, ni la ajena.
     *
     * @dataProvider casos_de_borrado
     * @param string $nombre  Base que se quiere borrar.
     * @param array  $creadas Bases que creó este proceso.
     * @param bool   $esperado Resultado esperado.
     * @return void
     */
    public function test_solo_borra_la_base_temporal_que_creo($nombre, array $creadas, $esperado)
    {
        $this->assertSame($esperado, RollbackIntegracionTestCase::puede_borrar_base($nombre, $creadas));
    }

    /**
     * Cada clase de reversión corre sobre su base temporal propia: no escribe en la del slot y no deja rastro al terminar.
     *
     * @return void
     */
    public function test_corre_sobre_su_base_temporal_y_no_sobre_la_del_slot()
    {
        SondaDeBaseTemporal::$visto = [];

        try {
            $this->correr_sonda(new SondaDeBaseTemporal('test_sonda'));
            $visto = SondaDeBaseTemporal::$visto;
        } finally {
            SondaDeBaseTemporal::tearDownAfterClass();
        }

        $this->assertMatchesRegularExpression('/^empresa_testing(_s[0-9]+)?(_test_[0-9]+)?$/', $visto['origen']);
        $this->assertMatchesRegularExpression('/^'.preg_quote($visto['origen'], '/').'_test_rev_[a-f0-9]{8}$/', $visto['temporal']);
        $this->assertNotSame($visto['origen'], $visto['temporal']);
        $this->assertSame($visto['temporal'], $visto['actual'], 'La conexión por defecto tiene que apuntar a la temporal.');
        $this->assertSame(1, $visto['en_temporal'], 'El dueño de la sonda existe en la temporal.');
        $this->assertSame(0, $visto['en_origen'], 'El dueño de la sonda NO existe en la base del slot.');
        $this->assertTrue($visto['copia_integra'], 'La temporal es una copia íntegra: mismas tablas y mismas filas que el origen.');
        $this->assertFalse(RollbackIntegracionTestCase::base_existe($visto['temporal']), 'La temporal se borra al terminar la clase.');
    }

    /**
     * Un proceso hijo ve, para CADA credencial de `EntornoSinCredenciales`, el valor de mentira (nunca el real simulado del padre ni un vacío),
     * apunta a la base temporal y conecta a ella.
     *
     * @return void
     */
    public function test_el_hijo_recibe_credenciales_de_mentira_y_la_base_temporal()
    {
        SondaDeProcesoHijo::$visto = [];

        try {
            $this->correr_sonda(new SondaDeProcesoHijo('test_sonda'));
            $visto = SondaDeProcesoHijo::$visto;
        } finally {
            SondaDeProcesoHijo::tearDownAfterClass();
        }

        $esperadas = EntornoSinCredenciales::para_procesos_hijos();
        $this->assertNotEmpty($esperadas);
        $this->assertSame(array_keys($esperadas), array_keys($visto['entorno']), 'El hijo debe ver todas las credenciales neutralizadas.');

        foreach ($esperadas as $nombre => $valor) {
            $this->assertSame($valor, $visto['entorno'][$nombre], 'Credencial '.$nombre.' en el hijo.');
            $this->assertNotSame('', $visto['entorno'][$nombre], $nombre.' no puede llegar vacía al hijo.');
            $this->assertNotSame('clave-real-simulada', $visto['entorno'][$nombre], $nombre.' no puede llegar con el valor del padre.');
        }

        $this->assertSame($visto['temporal'], $visto['db_del_entorno'], 'DB_DATABASE del hijo es la temporal.');
        $this->assertStringContainsString('dbname='.$visto['temporal'], $visto['dsn']);
        $this->assertSame($visto['temporal'], $visto['conectada'], 'El hijo conecta a la temporal y lo comprueba con SELECT DATABASE().');
        $this->assertFalse(RollbackIntegracionTestCase::base_existe($visto['temporal']), 'La temporal se borra al terminar la clase.');
    }

    /**
     * Mecánico: el único `proc_open` de los tres archivos vive dentro de `abrir_hijo()`, que le pasa el entorno armado con las credenciales de
     * mentira como quinto argumento. Los tests de reversión ya no lo llaman directamente.
     *
     * @return void
     */
    public function test_el_arnes_lanza_hijos_solo_por_abrir_hijo()
    {
        $patron = '/\b'.'proc_open'.'\s*\(/';
        $raiz = dirname(__DIR__, 2);
        $archivos = [
            'Feature/ListasSiempre/RollbackHistorialMigracionTest.php' => 1,
            'Feature/SucursalSiempre/RollbackMigracionExactaTest.php' => 0,
            'Feature/AuditoriaDeCambios/ReversionMigracionTrazableTest.php' => 0,
        ];

        foreach ($archivos as $relativo => $esperadas) {
            $this->assertSame($esperadas, preg_match_all($patron, file_get_contents($raiz.'/'.$relativo)), $relativo);
        }

        $metodo = new \ReflectionMethod(RollbackIntegracionTestCase::class, 'abrir_hijo');
        $lineas = file($metodo->getFileName());
        $cuerpo = implode('', array_slice($lineas, $metodo->getStartLine() - 1, $metodo->getEndLine() - $metodo->getStartLine() + 1));
        $this->assertSame(1, preg_match_all($patron, $cuerpo), 'El proc_open del arnés está dentro de abrir_hijo().');
        $this->assertStringContainsString('$this->entorno_hijo()', $cuerpo, 'abrir_hijo() pasa el entorno armado como quinto argumento.');
        $this->assertStringContainsString('para_procesos_hijos', file_get_contents($metodo->getFileName()));
    }

    /**
     * Ejecuta una sonda con el ciclo completo del arnés (setUp, cuerpo, tearDown). Un salto cuenta como falla: el defecto que se corrige es,
     * justamente, que el arnés salteaba sus casos.
     *
     * @param RollbackIntegracionTestCase $sonda Sonda con un único método `test_sonda`.
     * @return void
     */
    private function correr_sonda(RollbackIntegracionTestCase $sonda)
    {
        try {
            $sonda->runBare();
        } catch (SkippedTestError $salto) {
            $this->fail('El arnés saltó el caso en vez de correrlo: '.$salto->getMessage());
        }
    }
}

/**
 * Sonda: registra sobre qué base corrió el arnés y si lo que escribe queda fuera de la base del slot.
 * No es un test del conjunto (la clase no se llama como el archivo): la dispara `AislamientoDeLaReversionTest`.
 */
class SondaDeBaseTemporal extends RollbackIntegracionTestCase
{
    /**
     * Lo que vio la sonda; lo lee la prueba.
     *
     * @var array
     */
    public static $visto = [];

    /**
     * Crea un dueño con el fixture del arnés y mide dónde quedó.
     *
     * @return void
     */
    public function test_sonda()
    {
        $dueno = $this->nuevo_dueno();
        $origen = $this->base_origen();

        $tablas_origen = DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$origen, 'BASE TABLE'])->c;
        $tablas_temporal = DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE'])->c;
        $articulos_origen = DB::selectOne('SELECT COUNT(*) AS c FROM `'.$origen.'`.articles')->c;
        $articulos_temporal = DB::selectOne('SELECT COUNT(*) AS c FROM articles')->c;

        self::$visto = [
            'origen' => $origen,
            'temporal' => $this->base_temporal(),
            'actual' => DB::connection()->getDatabaseName(),
            'en_temporal' => (int) DB::table('users')->where('email', $dueno->email)->count(),
            'en_origen' => (int) DB::selectOne('SELECT COUNT(*) AS c FROM `'.$origen.'`.users WHERE email = ?', [$dueno->email])->c,
            // El dueño se creó recién en la temporal: la copia tenía exactamente las filas del origen (sin contar lo que escribió esta sonda).
            'copia_integra' => (int) $tablas_origen === (int) $tablas_temporal && (int) $articulos_origen === (int) $articulos_temporal,
        ];
    }
}

/**
 * Sonda: lanza un hijo real con `abrir_hijo()` mientras el padre tiene una credencial "real" simulada y otra vacía, y guarda lo que el hijo vio.
 */
class SondaDeProcesoHijo extends RollbackIntegracionTestCase
{
    /**
     * Lo que vio el hijo; lo lee la prueba.
     *
     * @var array
     */
    public static $visto = [];

    /**
     * Lanza el hijo y recoge su reporte.
     *
     * @return void
     */
    public function test_sonda()
    {
        $claves = array_keys(EntornoSinCredenciales::valores());
        $script = $this->salida.'/entorno-del-hijo.php';
        $salida = $this->salida.'/entorno-del-hijo.json';
        $errores = $this->salida.'/entorno-del-hijo.err';
        mkdir($this->salida, 0777, true);
        file_put_contents($script, '<?php
$reporte = ["entorno" => [], "db_del_entorno" => getenv("DB_DATABASE"), "dsn" => getenv("ROLLBACK_PDO_DSN")];
foreach (json_decode($argv[1], true) as $clave) { $reporte["entorno"][$clave] = getenv($clave); }
$p = new PDO(getenv("ROLLBACK_PDO_DSN"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$reporte["conectada"] = $p->query("SELECT DATABASE()")->fetchColumn();
echo json_encode($reporte);');

        // El padre tiene una credencial "real" y otra vacía: el hijo no debe ver ninguna de las dos.
        putenv('ANTHROPIC_API_KEY=clave-real-simulada');
        putenv('SERPER_API_KEY=');

        try {
            $proceso = $this->abrir_hijo([PHP_BINARY, $script, json_encode($claves)],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $salida, 'w'], 2 => ['file', $errores, 'w']], $pipes);
            $this->assertIsResource($proceso);
            $this->assertSame(0, proc_close($proceso), file_get_contents($errores));
        } finally {
            EntornoSinCredenciales::aplicar();
        }

        $reporte = json_decode(file_get_contents($salida), true);
        $this->assertIsArray($reporte, 'El hijo imprimió JSON: '.file_get_contents($salida));
        $reporte['temporal'] = $this->base_temporal();
        self::$visto = $reporte;
    }
}
