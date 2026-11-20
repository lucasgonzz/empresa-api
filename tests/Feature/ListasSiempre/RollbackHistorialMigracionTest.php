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
use Tests\TestCase;

/** Base compartida solamente por las tres regresiones nuevas; sin DatabaseTransactions. */
abstract class RollbackIntegracionTestCase extends TestCase
{
    protected $salida;

    protected function setUp(): void
    {
        // El productor único habilita esta corrida recién cuando libera lane147.
        if (getenv('ROLLBACK_SQL_GATE') !== 'lane147-liberado'
            || getenv('DB_DATABASE') !== 'empresa_testing_rollback_20261119') {
            $this->markTestSkipped('PENDIENTETESTS: requiere lane147 liberado y DB propia.');
        }
        parent::setUp();
        $this->assertSame('empresa_testing_rollback_20261119', DB::connection()->getDatabaseName());
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
                $this->assertSame(1, preg_match('/^START TRANSACTION\s*;[\s\S]*\bCOMMIT\s*;$/i', $limpio), 'Cada bloque NUEVO debe traer su transacción completa.');
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
            }
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
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
        $sql = $this->migrar_y_leer('listas:migrar-a-precio-final', $dueno);
        $uuid = $this->uuid_respaldo($sql);
        $originales = $this->foto('audit_logs', 'user_id', [$dueno->id]);
        $this->ejecutar_reversion($sql, true);
        $this->assertSame($antes, $this->foto_comercial($dueno->id, [$a->id]));
        $this->assertSame($listas, $this->foto('price_types', 'user_id', [$dueno->id]));
        $this->assertSame($originales, $this->foto('audit_logs', 'id', array_column($originales, 'id')));
        $logs = $this->foto('audit_logs', 'batch_uuid', [$uuid]);
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertSame('rollback', $log['event']);
            $this->assertSame('console', $log['source']);
            $this->assertNull($log['actor_id']);
            $payload = json_decode($log['new_values'], true);
            $this->assertSame($uuid, $payload['respaldo_uuid']);
            foreach (['bloque', 'logs_originales', 'estado'] as $clave) {
                $this->assertArrayHasKey($clave, $payload);
            }
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
        file_put_contents($script, '<?php $pdo = new PDO("mysql:unix_socket=".getenv("DB_SOCKET").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $pdo->exec("SET innodb_lock_wait_timeout=5"); file_put_contents($argv[2], "listo"); $pdo->exec(file_get_contents($argv[1]));');
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        $pdo->query('SELECT id FROM users WHERE id = '.(int) $dueno->id.' FOR UPDATE')->fetchAll();
        $procesos = [];
        try {
            foreach ([1, 2] as $n) {
                $procesos[$n] = proc_open([PHP_BINARY, $script, $archivo, $this->salida.'/listo'.$n],
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
