<?php

namespace Tests\Feature\ListasSiempre;

use App\Models\Article;
use App\Models\User;
use App\Models\PriceType;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Services\AuditLog\AuditContext;
use App\Services\HistorialPreciosService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\EntornoSinCredenciales;
use Tests\TestCase;

/**
 * Base compartida solamente por las tres regresiones nuevas; sin DatabaseTransactions.
 *
 * 🔴 Aislamiento (20/11/2026). Las tres clases escriben datos CONFIRMADOS (hay procesos hijos y candados reales, no se puede envolver
 * en una transacción) y leen `audit_logs` sin filtro, así que necesitan una base EXCLUSIVA. Antes la exigían por un semáforo de entorno
 * y un nombre de base fijo del contenedor donde se escribieron, y SALTABAN en cualquier otro lado: el gate del candidato 4.3.69 los contó
 * como 22 casos saltados. Ahora cada clase clona la base de testing del slot en una temporal `<origen>_test_rev_<8 hex>` (cae dentro del
 * GRANT `<slot>\_test\_%` de `cc-db`), corre ahí y la borra al terminar. La guarda de "no producción" sigue, pero precisa: FALLA (nunca
 * saltea) si el origen no es una base de testing de empresa, y sólo borra la temporal que creó este mismo proceso.
 *
 * El observador de esperas InnoDB (`INNODB_TRX`, `INNODB_LOCK_WAITS`) pide el privilegio global PROCESS: sin él el caso RR falla con el 1227
 * del motor (no se saltea). Lo recomendable es otorgárselo SOLO al usuario descartable del slot que corre estas clases.
 */
abstract class RollbackIntegracionTestCase extends TestCase
{
    /** Origen válido: la base de testing de empresa, de un slot y/o de una partición (`_test_N`, también el token de ParaTest). */
    const PATRON_ORIGEN = '/^empresa_testing(_s[0-9]+)?(_test_[0-9]+)?\z/';

    /** Sufijo de la temporal: exactamente 8 hexadecimales en minúscula. */
    const PATRON_SUFIJO = '/^[a-f0-9]{8}\z/';

    /** Una temporal ya armada: origen válido + `_test_rev_` + sufijo. Es lo único que el arnés puede borrar. */
    const PATRON_TEMPORAL = '/^empresa_testing(_s[0-9]+)?(_test_[0-9]+)?_test_rev_[a-f0-9]{8}\z/';

    /** Largo máximo de un nombre de base en MariaDB. */
    const LARGO_MAXIMO_BASE = 64;

    /**
     * Bases temporales vivas de ESTE proceso, por clase concreta (las estáticas de una clase abstracta se comparten entre las hijas).
     * Cada entrada: ['origen' => ..., 'temporal' => ..., 'conexion' => <nombre>, 'config' => <config de la conexión de origen>].
     *
     * @var array<string, array>
     */
    private static $bases = [];

    /**
     * Nombres de las temporales que creó ESTE proceso: la única lista contra la que se autoriza un DROP.
     *
     * @var string[]
     */
    private static $creadas = [];

    /**
     * Config (host, puerto, usuario, clave) con la que se habla con el servidor para crear/borrar/consultar temporales sin depender de la app.
     *
     * @var array|null
     */
    private static $config_servidor = null;

    protected $salida;

    /**
     * Arma el nombre de la base temporal de un origen. Es la guarda de "no producción": lanza si el origen no es una base de testing de
     * empresa (slot, partición o legado `empresa_testing`) o si el sufijo no son 8 hexadecimales en minúscula.
     *
     * @param  string $origen Nombre de la base de origen (la del slot o partición).
     * @param  string $hex    Sufijo aleatorio de 8 hexadecimales.
     * @return string `<origen>_test_rev_<hex>`.
     * @throws \InvalidArgumentException Si el origen o el sufijo no son válidos, o el nombre pasa de 64 caracteres.
     */
    public static function nombre_base_temporal($origen, $hex)
    {
        if (!is_string($origen) || preg_match(self::PATRON_ORIGEN, $origen) !== 1) {
            throw new \InvalidArgumentException('La base de origen no es una base de testing de empresa: '.json_encode($origen));
        }
        if (!is_string($hex) || preg_match(self::PATRON_SUFIJO, $hex) !== 1) {
            throw new \InvalidArgumentException('El sufijo de la base temporal debe ser de 8 hexadecimales en minúscula.');
        }
        $nombre = $origen.'_test_rev_'.$hex;
        if (strlen($nombre) > self::LARGO_MAXIMO_BASE) {
            throw new \InvalidArgumentException('El nombre de la base temporal pasa de '.self::LARGO_MAXIMO_BASE.' caracteres.');
        }
        return $nombre;
    }

    /**
     * ¿Se puede borrar esta base? Sólo si la creó este proceso (figura en `$creadas`) Y cumple el patrón de una temporal.
     * Nunca el origen, ni una partición, ni una temporal ajena, ni una de producción.
     *
     * @param  string $nombre  Base que se quiere borrar.
     * @param  array  $creadas Nombres de las temporales creadas por este proceso.
     * @return bool
     */
    public static function puede_borrar_base($nombre, array $creadas)
    {
        return is_string($nombre)
            && strlen($nombre) <= self::LARGO_MAXIMO_BASE
            && preg_match(self::PATRON_TEMPORAL, $nombre) === 1
            && in_array($nombre, $creadas, true);
    }

    /**
     * ¿Existe la base en el servidor? Consulta `information_schema.SCHEMATA` con la config guardada de la última corrida.
     *
     * @param  string $nombre Nombre de la base.
     * @return bool
     */
    public static function base_existe($nombre)
    {
        if (self::$config_servidor === null) {
            throw new \RuntimeException('Todavía no se conectó al servidor: no hay config guardada.');
        }
        $consulta = self::pdo_del_servidor(self::$config_servidor)->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $consulta->execute([(string) $nombre]);
        return (int) $consulta->fetchColumn() === 1;
    }

    /**
     * Arma el DSN de PDO de una conexión de Laravel: por socket si lo tiene, si no por host y puerto (es lo que usa `cc-db`).
     *
     * @param  array  $config Config de la conexión (`config('database.connections.x')`).
     * @param  string $base   Base a la que apunta el DSN.
     * @return string
     */
    protected static function dsn_desde_config(array $config, $base)
    {
        $juego = isset($config['charset']) && $config['charset'] !== '' ? ';charset='.$config['charset'] : '';
        return 'mysql:'.self::destino_desde_config($config).';dbname='.$base.$juego;
    }

    /**
     * Destino del servidor para un DSN: `unix_socket=...` si la conexión lo tiene, si no `host=...;port=...`.
     *
     * @param  array $config Config de la conexión.
     * @return string
     */
    protected static function destino_desde_config(array $config)
    {
        $socket = isset($config['unix_socket']) ? (string) $config['unix_socket'] : '';
        return $socket !== ''
            ? 'unix_socket='.$socket
            : 'host='.(isset($config['host']) ? $config['host'] : '127.0.0.1').';port='.(isset($config['port']) ? $config['port'] : '3306');
    }

    /**
     * Abre un PDO contra el servidor con la config de una conexión, sin elegir base (las consultas califican sus tablas).
     *
     * @param  array $config Config de la conexión de origen.
     * @return \PDO
     */
    private static function pdo_del_servidor(array $config)
    {
        return new \PDO('mysql:'.self::destino_desde_config($config).';charset=utf8mb4', (string) $config['username'], (string) $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * Clona la base de testing de origen en una temporal propia: mismas tablas (`CREATE TABLE … LIKE`) y mismas filas. Es el mismo fixture
     * sintético que dejó `migrate:fresh` + `TestingFerreteriaSeeder` en el slot. Si algo falla a mitad, borra lo que creó y relanza.
     *
     * @param  string $origen   Base de origen ya validada por `nombre_base_temporal()`.
     * @param  string $conexion Nombre de la conexión de Laravel.
     * @param  array  $config   Config de esa conexión.
     * @return array  Entrada del registro `$bases`.
     */
    private static function clonar_base_de_testing($origen, $conexion, array $config)
    {
        $temporal = self::nombre_base_temporal($origen, bin2hex(random_bytes(4)));
        self::$config_servidor = $config;
        $pdo = self::pdo_del_servidor($config);

        $juego = $pdo->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $juego->execute([$origen]);
        $fila = $juego->fetch(\PDO::FETCH_NUM);
        if ($fila === false) {
            throw new \RuntimeException('La base de origen no existe en el servidor: '.$origen);
        }

        $tablas = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME');
        $tablas->execute([$origen, 'BASE TABLE']);
        $nombres = $tablas->fetchAll(\PDO::FETCH_COLUMN);

        $pdo->exec('CREATE DATABASE `'.$temporal.'` CHARACTER SET '.preg_replace('/[^a-z0-9_]/i', '', $fila[0]).' COLLATE '.preg_replace('/[^a-z0-9_]/i', '', $fila[1]));
        self::$creadas[] = $temporal;
        try {
            // La copia no valida nada: son las mismas filas que ya aceptó el origen.
            $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', FOREIGN_KEY_CHECKS = 0, UNIQUE_CHECKS = 0");
            foreach ($nombres as $tabla) {
                $pdo->exec('CREATE TABLE `'.$temporal.'`.`'.$tabla.'` LIKE `'.$origen.'`.`'.$tabla.'`');
                $pdo->exec('INSERT INTO `'.$temporal.'`.`'.$tabla.'` SELECT * FROM `'.$origen.'`.`'.$tabla.'`');
            }
        } catch (\Throwable $error) {
            self::borrar_base_temporal($temporal);
            throw $error;
        }

        return ['origen' => $origen, 'temporal' => $temporal, 'conexion' => $conexion, 'config' => $config];
    }

    /**
     * Borra una temporal. Sólo si la creó este proceso y cumple el patrón (`puede_borrar_base()`); si no, lanza y no toca nada.
     *
     * @param  string $temporal Nombre de la temporal.
     * @return void
     * @throws \RuntimeException Si la guarda no autoriza el borrado.
     */
    private static function borrar_base_temporal($temporal)
    {
        if (!self::puede_borrar_base($temporal, self::$creadas)) {
            throw new \RuntimeException('Se rechazó borrar una base que no es una temporal creada por este proceso: '.json_encode($temporal));
        }
        self::pdo_del_servidor(self::$config_servidor)->exec('DROP DATABASE IF EXISTS `'.$temporal.'`');
        self::$creadas = array_values(array_diff(self::$creadas, [$temporal]));
    }

    /**
     * Al terminar la clase se borra la temporal que creó.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        $clase = static::class;
        if (isset(self::$bases[$clase])) {
            $entrada = self::$bases[$clase];
            unset(self::$bases[$clase]);
            self::borrar_base_temporal($entrada['temporal']);
        }
        parent::tearDownAfterClass();
    }

    /**
     * Base de testing del slot/partición de la que se clonó la temporal de esta clase.
     *
     * @return string
     */
    protected function base_origen()
    {
        return self::$bases[static::class]['origen'];
    }

    /**
     * Base temporal propia de esta clase.
     *
     * @return string
     */
    protected function base_temporal()
    {
        return self::$bases[static::class]['temporal'];
    }

    /**
     * DSN de PDO a la base temporal con el mismo destino (socket o host/puerto) que la conexión de Laravel.
     *
     * @return string
     */
    protected function dsn_pdo()
    {
        return self::dsn_desde_config(self::$bases[static::class]['config'], $this->base_temporal());
    }

    /**
     * Entorno de un proceso hijo: el del proceso actual (sólo las variables de texto) + las credenciales de mentira NO vacías
     * (`proc_open` descarta los valores vacíos de un entorno armado a mano y el hijo volvería a leer la clave real del `.env.testing`),
     * puestas DESPUÉS para que ganen sobre lo heredado, + la base temporal y su DSN.
     *
     * @return array<string, string>
     */
    protected function entorno_hijo()
    {
        $config = self::$bases[static::class]['config'];
        return array_merge(array_filter(getenv(), 'is_string'), EntornoSinCredenciales::para_procesos_hijos(), [
            'ROLLBACK_PDO_DSN' => $this->dsn_pdo(),
            'DB_DATABASE' => $this->base_temporal(),
            'DB_USERNAME' => (string) $config['username'],
            'DB_PASSWORD' => (string) $config['password'],
        ]);
    }

    /**
     * Lanza un proceso hijo con el entorno armado por `entorno_hijo()`. Es el ÚNICO `proc_open` de las tres clases de reversión.
     *
     * @param  array    $comando     Comando y argumentos (sin shell).
     * @param  array    $descriptores Descriptores de archivo del hijo.
     * @param  array|null $pipes     Recibe las tuberías abiertas.
     * @return resource|false Proceso, para `proc_close()`.
     */
    protected function abrir_hijo(array $comando, array $descriptores, &$pipes)
    {
        return proc_open($comando, $descriptores, $pipes, base_path(), $this->entorno_hijo());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'), 'Las reversiones sólo corren con APP_ENV=testing.');
        $conexion = config('database.default');
        $clase = static::class;
        if (!isset(self::$bases[$clase])) {
            // La guarda de origen vive en nombre_base_temporal(): un origen que no sea de testing lanza acá, no saltea.
            self::$bases[$clase] = self::clonar_base_de_testing(DB::connection()->getDatabaseName(), $conexion, config('database.connections.'.$conexion));
        }
        // Laravel recrea la app (y vuelve a la base del .env.testing) en cada test: se apunta de nuevo a la temporal antes de escribir.
        config(['database.connections.'.$conexion.'.database' => $this->base_temporal()]);
        DB::purge($conexion);
        $this->assertSame($this->base_temporal(), DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::connection()->transactionLevel());
        // Precondición real del comando; fixture local, sin semillas ni datos externos.
        DB::table('concepto_stock_movements')->updateOrInsert(
            ['name' => 'Creacion de deposito'], []
        );
        AuditContext::reiniciar();
        PriceTypeHelper::olvidar_precio_final();
        $this->salida = storage_path('framework/testing/rollback-'.bin2hex(random_bytes(8)));
        // Los datos confirmados permanecen en la DB descartable del ejecutor: no borrar auditoría.
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            AuditContext::reiniciar();
            PriceTypeHelper::olvidar_precio_final();
            parent::tearDown();
        }
    }

    protected function nuevo_dueno()
    {
        return User::create([
            'name' => 'Regresión rollback', 'company_name' => 'Regresión rollback',
            'email' => 'rollback-'.bin2hex(random_bytes(8)).'@test.local',
            'password' => 'fixture-sin-login', 'status' => 'commerce',
            'listas_de_precio' => 0, 'dollar' => 1000,
        ])->fresh();
    }

    protected function nuevo_articulo($dueno, array $datos = [])
    {
        // Releer defaults persistidos ANTES del cálculo, nunca cambiar la expectativa comercial.
        return Article::create(array_merge([
            'name' => 'Regresión rollback', 'user_id' => $dueno->id,
            'status' => 'active', 'cost' => 100, 'price' => 123.45,
        ], $datos))->fresh();
    }

    protected function foto($tabla, $columna, array $ids)
    {
        return json_decode(json_encode(DB::table($tabla)->whereIn($columna, $ids)->orderBy('id')->get()), true);
    }

    protected function migrar_y_leer($comando, $dueno)
    {
        $opciones = ['user_id' => $dueno->id, '--aplicar' => true, '--salida' => $this->salida];
        if ($comando === 'listas:migrar-a-precio-final') {
            // El fixture conserva una lista histórica: autorización explícita sin tocar aserciones.
            $opciones['--incluir-listas-existentes'] = true;
        }
        $codigo = Artisan::call($comando, $opciones);
        $this->assertSame(0, $codigo, Artisan::output());
        $archivos = glob($this->salida.'/*/reversion.sql');
        $this->assertCount(1, $archivos);
        return file_get_contents($archivos[0]);
    }

    // Contrato nuevo propuesto: UUID declarado por el respaldo, no deducido de los logs.
    protected function uuid_respaldo($sql)
    {
        $cantidad = preg_match_all('/^-- Respaldo UUID: ([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\r?$/mi', $sql, $m);
        $this->assertSame(1, $cantidad, 'PENDIENTE contrato nuevo: encabezado Respaldo UUID único.');
        return $m[1][0];
    }

    protected function bloques_reversion($sql, $exigir_nuevo = false)
    {
        $partes = preg_split('/\n(?=-- Artículo |-- Cuenta|-- Marca de sucursal principal|-- Sucursal principal creada)/', $sql);
        $encabezado = array_shift($partes);
        $this->assertSame('', trim(preg_replace('/^--[^\n]*$/m', '', $encabezado)), 'SQL fuera de bloques reconocidos.');
        $legacy = strpos($encabezado, '-- Orden del archivo: primero el bloque de ') !== false
            && strpos($encabezado, '-- Para revertir: correr los bloques de artículo y RECIÉN DESPUÉS el de ') !== false
            && strpos($encabezado, '-- Respaldo UUID:') === false;
        if ($exigir_nuevo || !$legacy) {
            $this->uuid_respaldo($encabezado);
            $legacy = false;
        }
        $articulos = []; $cuenta = []; $orden = [];
        foreach ($partes as $parte) {
            if (strpos($parte, '-- Artículo ') === 0) {
                $this->assertSame([], $legacy ? [] : $cuenta, 'Formato nuevo: artículos ANTES de cuenta/sucursal en el archivo físico.');
                $articulos[] = $parte;
            } else {
                $this->assertSame(1, preg_match('/^-- (Cuenta|Marca de sucursal principal|Sucursal principal creada)/', $parte));
                $cuenta[] = $parte;
            }
            if (!$legacy) {
                $limpio = trim(preg_replace('/^--[^\n]*$/m', '', $parte));
                $this->assertSame(1, preg_match('/^(?:SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;\s*)?START TRANSACTION\s*;[\s\S]*\bCOMMIT\s*;$/i', $limpio), 'Cada bloque NUEVO debe traer su transacción completa.');
                $this->assertSame(1, preg_match_all('/\bSTART\s+TRANSACTION\b/i', $limpio));
                $this->assertSame(1, preg_match_all('/\bCOMMIT\b/i', $limpio));
                $this->assertSame(0, preg_match('/^\s*(BEGIN|ROLLBACK|SAVEPOINT|SET\s+AUTOCOMMIT|CREATE|ALTER|DROP|TRUNCATE)\b/im', $limpio), 'Sin commits implícitos ni transacciones alternativas.');
            }
            $orden[] = $parte;
        }
        $this->assertNotEmpty($articulos);
        $this->assertCount(1, $cuenta);
        // Sólo el legado explícitamente identificado se ejecuta siguiendo SU encabezado.
        // El formato nuevo se ejecuta en orden físico, sin arreglarle el respaldo al producto.
        return $legacy ? array_merge($articulos, $cuenta) : $orden;
    }

    protected function ejecutar_reversion($sql, $exigir_nuevo = false)
    {
        $bloques = $this->bloques_reversion($sql, $exigir_nuevo);
        $this->assertSame(0, DB::connection()->transactionLevel());
        $pdo = DB::connection()->getPdo();
        $this->assertFalse($pdo->inTransaction(), 'No envolver el respaldo en una transacción del fixture.');
        try {
            foreach ($bloques as $bloque) {
                $pdo->exec($bloque);
                $this->assertFalse($pdo->inTransaction(), 'Cada bloque entregado debe terminar confirmado.');
                $this->assertSame(0, (int) $pdo->query('SELECT @@in_transaction')->fetchColumn(), 'Servidor sin transacción residual.');
            }
        } catch (\Throwable $error) {
            try {
                $this->assertFalse($pdo->inTransaction(), 'El SQL entregado debe cerrar la transacción también al fallar.');
                $this->assertSame(0, (int) $pdo->query('SELECT @@in_transaction')->fetchColumn(), 'El servidor debe confirmar el ROLLBACK real.');
            } finally {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
            }
            throw $error;
        }
    }

    protected function foto_comercial($dueno_id, array $ids, array $variantes = [])
    {
        // Recalcular también IDs nuevos: un insert residual no puede quedar fuera de la foto.
        $ids = array_values(array_unique(array_merge($ids, DB::table('articles')->where('user_id', $dueno_id)->pluck('id')->all())));
        $variantes = array_values(array_unique(array_merge($variantes, DB::table('article_variants')->whereIn('article_id', $ids)->pluck('id')->all())));
        $usuarios = DB::table('users')->where('id', $dueno_id)->orWhere('owner_id', $dueno_id)->pluck('id')->all();
        return [
            'usuarios' => $this->foto('users', 'id', $usuarios),
            'direcciones' => $this->foto('addresses', 'user_id', [$dueno_id]),
            'articulos' => $this->foto('articles', 'id', $ids),
            'variantes' => $this->foto('article_variants', 'id', $variantes),
            'filas' => $this->foto('address_article', 'article_id', $ids),
            'filas_variantes' => $this->foto('address_article_variant', 'article_variant_id', $variantes),
            'listas' => $this->foto('price_types', 'user_id', [$dueno_id]),
            'extensiones' => $this->foto('extencion_empresa_user', 'user_id', [$dueno_id]),
            'precios' => $this->foto('article_price_type', 'article_id', $ids),
            'monedas' => $this->foto('article_price_type_monedas', 'article_id', $ids),
            'historial' => $this->foto('price_changes', 'article_id', $ids),
            'detalles_historial' => $this->foto('price_change_price_type', 'price_change_id', DB::table('price_changes')->whereIn('article_id', $ids)->pluck('id')->all()),
            'movimientos' => $this->foto('stock_movements', 'article_id', $ids),
        ];
    }

}

/** Falla la segunda escritura: después de la foto general, DENTRO del bloque cuenta. */
class FalloSegundoRespaldoFilter extends \php_user_filter
{
    private $escrituras = 0;
    public function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if (++$this->escrituras >= 2) { return PSFS_ERR_FATAL; }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}

class RollbackHistorialMigracionTest extends RollbackIntegracionTestCase
{
    public function test_respaldo_nuevo_entrega_orden_fisico_y_transacciones_completas()
    {
        $dueno = $this->nuevo_dueno();
        $this->nuevo_articulo($dueno, ['stock' => 12]);
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        // El legado sirve para diagnosticar P1/P2, nunca acredita el contrato nuevo.
        $this->bloques_reversion($sql, true);
    }

    public function test_lista_nueva_restaura_foto_completa_y_segundo_intento_no_duplica_auditoria()
    {
        $dueno = $this->nuevo_dueno();
        $a = $this->nuevo_articulo($dueno);
        ArticleHelper::setFinalPrice($a, null, $dueno);
        $antes = $this->foto_comercial($dueno->id, [$a->id]);
        $listas = $this->foto('price_types', 'user_id', [$dueno->id]);
        $previos = $this->foto('audit_logs', 'id', DB::table('audit_logs')->pluck('id')->all());
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        $aplicado = $this->foto_comercial($dueno->id, [$a->id]);
        $uuid = $this->uuid_respaldo($sql);
        $originales = $this->foto('audit_logs', 'id', DB::table('audit_logs')->pluck('id')->all());
        $this->ejecutar_reversion($sql, true);
        $this->assertSame($antes, $this->foto_comercial($dueno->id, [$a->id]));
        $this->assertSame($listas, $this->foto('price_types', 'user_id', [$dueno->id]));
        $this->assertSame($originales, $this->foto('audit_logs', 'id', array_column($originales, 'id')));
        $logs = $this->foto('audit_logs', 'batch_uuid', [$uuid]);
        $this->assertCount(2, $logs);
        $migracion = array_values(array_filter($originales, function ($log) use ($previos) { return !in_array($log['id'], array_column($previos, 'id'), true); }));
        $this->assertNotEmpty($migracion);
        foreach ($logs as $log) {
            $this->assertSame('rollback', $log['event']);
            $this->assertSame('console', $log['source']);
            $this->assertNull($log['actor_id']);
            $payload = json_decode($log['new_values'], true);
            $this->assertSame($uuid, $payload['respaldo_uuid']);
            foreach (['bloque', 'logs_originales', 'estado'] as $clave) {
                $this->assertArrayHasKey($clave, $payload);
            }
            $articulo = $log['auditable_type'] === Article::class;
            $this->assertSame($articulo ? Article::class : User::class, $log['auditable_type']);
            $this->assertSame((int) ($articulo ? $a->id : $dueno->id), (int) $log['auditable_id']);
            $this->assertSame((int) $dueno->id, (int) $log['user_id']);
            $this->assertSame('listas:migrar-a-precio-final / reversion', $log['origin']);
            $this->assertSame($uuid, $log['batch_uuid']);
            $this->assertSame('listas:migrar-a-precio-final', $payload['operacion']);
            $this->assertSame(($articulo ? 'articulo:' : 'cuenta:').($articulo ? $a->id : $dueno->id), $payload['bloque']);
            $esperados = [];
            foreach ($migracion as $original) {
                if ($original['auditable_type'] === ($articulo ? Article::class : User::class)
                    && (int) $original['auditable_id'] === (int) ($articulo ? $a->id : $dueno->id)) {
                    $esperados[] = (int) $original['id'];
                }
            }
            $this->assertSame($esperados, $payload['logs_originales']);
            $claves = $articulo ? ['articulos', 'precios', 'monedas', 'historial', 'detalles_historial'] : ['usuarios', 'listas', 'extensiones'];
            $viejos = json_decode($log['old_values'], true);
            $mapa = ['articulos' => 'articles', 'precios' => 'article_price_type', 'monedas' => 'article_price_type_monedas', 'historial' => 'price_changes', 'detalles_historial' => 'price_change_price_type', 'usuarios' => 'users', 'listas' => 'price_types', 'extensiones' => 'extencion_empresa_user'];
            $estado_aplicado = []; $estado_restaurado = [];
            foreach ($claves as $clave) { $estado_aplicado[$mapa[$clave]] = $aplicado[$clave]; $estado_restaurado[$mapa[$clave]] = $antes[$clave]; }
            $this->assertSame($estado_aplicado, $viejos['estado']);
            $this->assertSame($estado_restaurado, $payload['estado']);
        }
        $this->ejecutar_reversion($sql, true);
        $this->assertSame($logs, $this->foto('audit_logs', 'batch_uuid', [$uuid]));
        $this->assertSame($antes, $this->foto_comercial($dueno->id, [$a->id]));
    }

    public function test_rechaza_precio_posterior_sin_pisarlo()
    {
        $dueno = $this->nuevo_dueno();
        $a = $this->nuevo_articulo($dueno);
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        DB::table('articles')->where('id', $a->id)->update(['price' => 999]);
        $aplicado = $this->foto_comercial($dueno->id, [$a->id]);
        $error = null;
        try { $this->ejecutar_reversion($sql, true); } catch (\PDOException $e) { $error = $e; }
        $this->assertInstanceOf(\PDOException::class, $error);
        $this->assertSame($aplicado, $this->foto_comercial($dueno->id, [$a->id]));
    }

    public function test_insert_auditado_fallido_no_confirma_compensacion_de_listas()
    {
        $dueno = $this->nuevo_dueno();
        $a = $this->nuevo_articulo($dueno);
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        $this->bloques_reversion($sql, true);
        $aplicado = $this->foto_comercial($dueno->id, [$a->id]);
        $trigger = 'rollback_listas_'.bin2hex(random_bytes(6));
        $pdo = DB::connection()->getPdo();
        $pdo->exec("CREATE TRIGGER `".$trigger."` BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fallo_auditado_listas'");
        $error = null;
        try {
            try { $this->ejecutar_reversion($sql, true); } catch (\PDOException $e) { $error = $e; }
        } finally { $pdo->exec('DROP TRIGGER `'.$trigger.'`'); }
        $this->assertInstanceOf(\PDOException::class, $error);
        $this->assertStringContainsString('fallo_auditado_listas', $error->getMessage());
        $this->assertSame($aplicado, $this->foto_comercial($dueno->id, [$a->id]));
    }

    public function test_lista_precio_final_preexistente_se_conserva_con_sus_pivotes()
    {
        $dueno = $this->nuevo_dueno();
        $lista = PriceType::create(['name' => 'Precio final', 'user_id' => $dueno->id, 'es_precio_final' => 1, 'position' => 1, 'percentage' => 0]);
        $a = $this->nuevo_articulo($dueno);
        ArticleHelper::setFinalPrice($a, null, $dueno);
        // Una lista ya existente no debe confundirse con una creada por esta operación.
        $antes = $this->foto_comercial($dueno->id, [$a->id]);
        $listas = $this->foto('price_types', 'user_id', [$dueno->id]);
        $pivotes = $this->foto('article_price_type', 'article_id', [$a->id]);
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        $this->ejecutar_reversion($sql, true);
        $this->assertSame($antes, $this->foto_comercial($dueno->id, [$a->id]));
        $this->assertSame($listas, $this->foto('price_types', 'user_id', [$dueno->id]));
        $this->assertSame($pivotes, $this->foto('article_price_type', 'article_id', [$a->id]));
        $this->assertTrue(DB::table('price_types')->where('id', $lista->id)->exists());
    }

    public function test_fallo_de_respaldo_dentro_de_cuenta_aborta_cambios_comerciales()
    {
        $dueno = $this->nuevo_dueno();
        $a = $this->nuevo_articulo($dueno);
        $antes = $this->foto_comercial($dueno->id, [$a->id]);
        $listas = $this->foto('price_types', 'user_id', [$dueno->id]);
        $extensiones = $this->foto('extencion_empresa_user', 'user_id', [$dueno->id]);
        $cmd = new \App\Console\Commands\MigrarAListaPrecioFinal();
        $cmd->setLaravel($this->app);
        $input = new \Symfony\Component\Console\Input\ArrayInput(['user_id' => $dueno->id, '--aplicar' => true, '--salida' => $this->salida], $cmd->getDefinition());
        foreach (['input' => $input, 'output' => new \Illuminate\Console\OutputStyle($input, new \Symfony\Component\Console\Output\BufferedOutput())] as $nombre => $valor) {
            $p = new \ReflectionProperty($cmd, $nombre); $p->setAccessible(true); $p->setValue($cmd, $valor);
        }
        $invocar = function ($nombre, array $args = []) use ($cmd) {
            $m = new \ReflectionMethod($cmd, $nombre); $m->setAccessible(true); return $m->invokeArgs($cmd, $args);
        };
        $m = $invocar('medir', [$dueno]);
        $this->assertTrue($invocar('abrir_respaldo', [$dueno->id]));
        if (!in_array('rollback.fallo_segundo', stream_get_filters(), true)) {
            stream_filter_register('rollback.fallo_segundo', FalloSegundoRespaldoFilter::class);
        }
        $p = new \ReflectionProperty($cmd, 'respaldo'); $p->setAccessible(true);
        $stream = $p->getValue($cmd);
        $filter = stream_filter_append($stream, 'rollback.fallo_segundo', STREAM_FILTER_WRITE);
        $this->assertIsResource($filter);
        $error = null;
        try { $invocar('aplicar', [$dueno, $m]); } catch (\Throwable $e) { $error = $e; }
        finally { stream_filter_remove($filter); $invocar('cerrar_respaldo'); }
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame(9002, $error->getCode());
        $this->assertSame($antes, $this->foto_comercial($dueno->id, [$a->id]));
        $this->assertSame($listas, $this->foto('price_types', 'user_id', [$dueno->id]));
        $this->assertSame($extensiones, $this->foto('extencion_empresa_user', 'user_id', [$dueno->id]));
    }

    public function test_ejecucion_parcial_respalda_solo_articulos_confirmados()
    {
        $dueno = $this->nuevo_dueno();
        $bien = $this->nuevo_articulo($dueno);
        $mal = $this->nuevo_articulo($dueno, ['final_price' => 999]);
        $antes = $this->foto_comercial($dueno->id, [$bien->id, $mal->id]);
        $codigo = Artisan::call('listas:migrar-a-precio-final', ['user_id' => $dueno->id, '--aplicar' => true, '--salida' => $this->salida]);
        $this->assertSame(1, $codigo, Artisan::output());
        $archivo = glob($this->salida.'/*/reversion.sql')[0];
        $sql = file_get_contents($archivo);
        $this->bloques_reversion($sql, true);
        $this->assertStringContainsString('-- Artículo '.$bien->id."\n", $sql);
        $this->assertStringNotContainsString('-- Artículo '.$mal->id."\n", $sql);
        $this->ejecutar_reversion($sql, true);
        $this->assertSame($antes, $this->foto_comercial($dueno->id, [$bien->id, $mal->id]));
    }

    public function comandos_rr()
    {
        return [['listas:migrar-a-precio-final'], ['sucursales:migrar-a-principal']];
    }

    /** @dataProvider comandos_rr */
    public function test_rr_relee_despues_de_esperar_un_escritor_confirmado($comando)
    {
        $dueno = $this->nuevo_dueno();
        $a = $this->nuevo_articulo($dueno, ['stock' => 12]);
        $sql = $this->migrar_y_leer($comando, $dueno);
        $bloque = $this->bloques_reversion($sql, true)[0];
        $archivo = $this->salida.'/rr.sql';
        file_put_contents($archivo, $bloque);
        $script = $this->salida.'/rr.php';
        file_put_contents($script, '<?php
$p = new PDO(getenv("ROLLBACK_PDO_DSN"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$p->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
$p->exec("SET innodb_lock_wait_timeout=30");
file_put_contents($argv[2], $p->query("SELECT CONNECTION_ID()")->fetchColumn());
$codigo = 0;
try { $q = $p->query(file_get_contents($argv[1])); do { $q->fetchAll(); } while ($q->nextRowset()); } catch (PDOException $e) { $codigo = (int) $e->errorInfo[1]; }
echo json_encode([$codigo, (int) $p->query("SELECT @@in_transaction")->fetchColumn(), $p->query("SELECT @@tx_isolation")->fetchColumn()]);');
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $proceso = null;
        $espera = [];
        try {
            // Primero el escritor: el respaldo debe esperar SU fila, no una barrera artificial.
            $pdo->exec('UPDATE articles SET price=999 WHERE id='.(int) $a->id);
            $esperado = $this->foto_comercial($dueno->id, [$a->id]);
            $auditoria = $this->foto('audit_logs', 'user_id', [$dueno->id]);
            $observador = new \PDO($this->dsn_pdo(), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $escritor_id = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
            $proceso = $this->abrir_hijo([PHP_BINARY, $script, $archivo, $this->salida.'/rr.id'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->salida.'/rr.out', 'w'], 2 => ['file', $this->salida.'/rr.err', 'w']], $pipes);
            $this->assertIsResource($proceso);
            $limite = microtime(true) + 20;
            do {
                clearstatcache();
                $conexion = is_file($this->salida.'/rr.id') ? (int) file_get_contents($this->salida.'/rr.id') : 0;
                $espera = $observador->query('SELECT r.trx_mysql_thread_id AS reversor, b.trx_mysql_thread_id AS escritor FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE r.trx_mysql_thread_id='.$conexion.' AND b.trx_mysql_thread_id='.$escritor_id)->fetchAll(\PDO::FETCH_ASSOC);
                if ($espera) break;
                usleep(250000);
            } while (microtime(true) < $limite);
            file_put_contents($this->salida.'/rr-espera.json', json_encode($espera));
            $this->assertNotEmpty($espera, 'Espera InnoDB real del CONNECTION_ID reversor contra este escritor.');
            // Confirmar únicamente después de verificar la espera real.
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_resource($proceso)) {
                $this->assertSame(0, proc_close($proceso), file_get_contents($this->salida.'/rr.err'));
            }
        }
        $this->assertSame(0, (int) $pdo->query('SELECT @@in_transaction')->fetchColumn());
        $this->assertSame([1242, 0, 'REPEATABLE-READ'], json_decode(file_get_contents($this->salida.'/rr.out'), true), 'Rechazo por estado actual distinto y sin transacción residual.');
        $this->assertSame($esperado, $this->foto_comercial($dueno->id, [$a->id]), 'No pisar el cambio comercial confirmado.');
        $this->assertSame($auditoria, $this->foto('audit_logs', 'user_id', [$dueno->id]), 'Sin compensación ni cambios a auditoría.');
    }

    public function test_escritor_comercial_real_no_puede_confirmar_entre_validacion_y_compensacion()
    {
        foreach (['listas:migrar-a-precio-final', 'sucursales:migrar-a-principal'] as $comando) {
            $dueno = $this->nuevo_dueno();
            $a = $this->nuevo_articulo($dueno, ['stock' => 12]);
            $antes = $this->foto_comercial($dueno->id, [$a->id]);
            $this->salida .= '-carrera';
            $sql = $this->migrar_y_leer($comando, $dueno);
            $bloque = $this->bloques_reversion($sql, true)[0];
            $barrera = 'rollback_'.bin2hex(random_bytes(8));
            $pdo = DB::connection()->getPdo();
            $this->assertSame(1, (int) $pdo->query("SELECT GET_LOCK('".$barrera."', 0)")->fetchColumn());
            // Pausa DESPUÉS de las precondiciones reales, ANTES de la primera compensación.
            $bloque = preg_replace('/(?=DELETE FROM |UPDATE articles SET |UPDATE article_variants SET )/',
                "SELECT GET_LOCK('".$barrera."', 8) INTO @barrera; ", $bloque, 1);
            $archivo = $this->salida.'/intercalado.sql'; file_put_contents($archivo, $bloque);
            $script = $this->salida.'/intercalado.php';
            file_put_contents($script, '<?php $p = new PDO(getenv("ROLLBACK_PDO_DSN"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $p->exec("SET innodb_lock_wait_timeout=1"); if ($argv[1] === "rollback") { $p->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED"); $p->exec(file_get_contents($argv[2])); } else { try { $p->beginTransaction(); $p->exec("UPDATE articles SET price=999 WHERE id=".(int)$argv[2]); $p->commit(); echo "confirmado"; } catch (PDOException $e) { if ($p->inTransaction()) $p->rollBack(); echo $e->errorInfo[1].":".$p->query("SELECT @@in_transaction")->fetchColumn(); } }');
            $abrir = function ($modo, $dato) use ($script) {
                return $this->abrir_hijo([PHP_BINARY, $script, $modo, (string) $dato],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->salida.'/'.$modo.'.out', 'w'], 2 => ['file', $this->salida.'/'.$modo.'.err', 'w']], $pipes);
            };
            $reversion = $abrir('rollback', $archivo); $escritor = null;
            try {
                $limite = microtime(true) + 4;
                do {
                    $esperando = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE STATE = 'User lock' AND ID <> CONNECTION_ID() AND DB = DATABASE()")->fetchColumn();
                    if ($esperando) break;
                    usleep(10000);
                } while (microtime(true) < $limite);
                $this->assertSame(1, $esperando, 'La reversión alcanzó la barrera posterior a su validación.');
                $escritor = $abrir('comercial', $a->id);
                $this->assertSame(0, proc_close($escritor), file_get_contents($this->salida.'/comercial.err')); $escritor = null;
                $this->assertSame('1205:0', file_get_contents($this->salida.'/comercial.out'), 'El escritor comercial debe quedar excluido hasta el commit, sin transacción residual.');
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('".$barrera."')")->fetchColumn();
                if (is_resource($escritor)) proc_close($escritor);
                $this->assertSame(0, proc_close($reversion), file_get_contents($this->salida.'/rollback.err'));
            }
            $this->ejecutar_reversion($sql, true);
            $this->assertSame($antes, $this->foto_comercial($dueno->id, [$a->id]));
        }
    }

    public function test_dos_procesos_revierten_sin_duplicar_bloques_bajo_candado()
    {
        $dueno = $this->nuevo_dueno();
        $a = $this->nuevo_articulo($dueno);
        $antes = $this->foto_comercial($dueno->id, [$a->id]);
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        $this->bloques_reversion($sql, true);
        $uuid = $this->uuid_respaldo($sql);
        $archivo = $this->salida.'/concurrente.sql';
        file_put_contents($archivo, $sql);
        $script = $this->salida.'/concurrente.php';
        file_put_contents($script, '<?php $pdo = new PDO(getenv("ROLLBACK_PDO_DSN"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $pdo->exec("SET innodb_lock_wait_timeout=5"); file_put_contents($argv[2], "listo"); $pdo->exec(file_get_contents($argv[1]));');
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        $pdo->query('SELECT id FROM users WHERE id = '.(int) $dueno->id.' FOR UPDATE')->fetchAll();
        $procesos = [];
        try {
            foreach ([1, 2] as $n) {
                $procesos[$n] = $this->abrir_hijo([PHP_BINARY, $script, $archivo, $this->salida.'/listo'.$n],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->salida.'/salida'.$n, 'w'], 2 => ['file', $this->salida.'/error'.$n, 'w']], $pipes);
                $this->assertIsResource($procesos[$n]);
            }
            $limite = microtime(true) + 3;
            while ((!file_exists($this->salida.'/listo1') || !file_exists($this->salida.'/listo2')) && microtime(true) < $limite) { usleep(10000); }
            $this->assertFileExists($this->salida.'/listo1');
            $this->assertFileExists($this->salida.'/listo2');
        } finally {
            $pdo->commit();
            foreach ($procesos as $n => $proceso) {
                $this->assertSame(0, proc_close($proceso), file_get_contents($this->salida.'/error'.$n));
            }
        }
        $this->assertSame($antes, $this->foto_comercial($dueno->id, [$a->id]));
        $this->assertCount(2, $this->foto('audit_logs', 'batch_uuid', [$uuid]));
    }

    public function test_reversion_no_deja_detalles_huerfanos_ni_borra_historial_ajeno()
    {
        $dueno = $this->nuevo_dueno();
        $articulo = $this->nuevo_articulo($dueno);
        ArticleHelper::setFinalPrice($articulo, null, $dueno);
        $lista = PriceType::create(['name' => 'Histórica', 'user_id' => $dueno->id, 'percentage' => 0, 'position' => 1]);
        HistorialPreciosService::ejecutar([$articulo->id], null, 'fixture_historial_previo', function () use ($articulo, $lista) {
            $articulo->price_types()->attach($lista->id, ['final_price' => 123.45]);
        });
        $centinela = $this->nuevo_articulo($this->nuevo_dueno());
        HistorialPreciosService::ejecutar([$centinela->id], null, 'fixture_owner_centinela', function () use ($centinela) {
            DB::table('articles')->where('id', $centinela->id)->update(['final_price' => 777]);
        });
        $antes = $this->foto('price_changes', 'article_id', [$articulo->id, $centinela->id]);
        $this->assertNotEmpty($antes, 'El historial previo debe ser real.');
        $detalles = $this->foto('price_change_price_type', 'price_change_id', array_column($antes, 'id'));
        $max_detalle = (int) DB::table('price_change_price_type')->max('id');
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        $creados = DB::table('price_changes')->where('article_id', $articulo->id)
            ->whereNotIn('id', array_column($antes, 'id'))->pluck('id')->all();
        $this->assertNotEmpty($creados, 'La migración debe generar historial posterior al callback.');
        $this->assertGreaterThan(0, DB::table('price_change_price_type')->whereIn('price_change_id', $creados)->count());
        $this->ejecutar_reversion($sql);
        $this->assertSame($antes, $this->foto('price_changes', 'article_id', [$articulo->id, $centinela->id]));
        $this->assertSame($detalles, $this->foto('price_change_price_type', 'price_change_id', array_column($antes, 'id')));
        $this->assertSame(0, DB::table('price_change_price_type')->whereIn('price_change_id', $creados)->count(), 'P1: no dejar detalles del historial revertido.');
        $this->assertSame(0, DB::table('price_change_price_type as d')->leftJoin('price_changes as p', 'p.id', '=', 'd.price_change_id')
            ->where('d.id', '>', $max_detalle)->whereNull('p.id')->count(), 'Cero huérfanos NUEVOS; no sanear ajenos.');
    }
}
