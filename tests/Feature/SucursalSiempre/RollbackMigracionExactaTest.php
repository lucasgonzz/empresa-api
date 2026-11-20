<?php

namespace Tests\Feature\SucursalSiempre;

require_once __DIR__.'/../ListasSiempre/RollbackHistorialMigracionTest.php';

use App\Models\Address;
use App\Models\ArticleVariant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ListasSiempre\RollbackIntegracionTestCase;

class RollbackMigracionExactaTest extends RollbackIntegracionTestCase
{
    public function test_respaldo_nuevo_entrega_orden_fisico_y_transacciones_completas()
    {
        $dueno = $this->nuevo_dueno();
        $this->nuevo_articulo($dueno, ['stock' => 12]);
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
        // El legado sirve para diagnosticar P1/P2, nunca acredita el contrato nuevo.
        $this->bloques_reversion($sql, true);
    }

    public function test_restauracion_comercial_incluye_timestamps_crudos_y_stock_de_variantes()
    {
        $dueno = $this->nuevo_dueno();
        $direccion = Address::create(['street' => 'Existente', 'user_id' => $dueno->id, 'default_address' => 0]);
        DB::table('addresses')->where('id', $direccion->id)->update(['default_address' => 0, 'updated_at' => '2020-01-02 03:04:05']);
        $ids = []; $variantes = [];
        foreach ([null, 0, -3, 9] as $stock) {
            $articulo = $this->nuevo_articulo($dueno, ['stock' => $stock]);
            $ids[] = $articulo->id;
            if ($stock === 9) {
                foreach ([5, 4] as $n => $cantidad) {
                    $v = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Regresión '.$n, 'stock' => $cantidad]);
                    DB::table('article_variants')->where('id', $v->id)->update(['updated_at' => '2020-02-0'.($n + 1).' 04:05:06']);
                    $variantes[] = $v->id;
                }
            }
        }
        $papelera = $this->nuevo_articulo($dueno, ['stock' => 4]);
        $papelera->delete();
        $ids[] = $papelera->id;
        $antes = $this->foto_comercial($dueno->id, $ids, $variantes);
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
        $this->assertSame(1, (int) DB::table('addresses')->where('id', $direccion->id)->value('default_address'));
        $this->ejecutar_reversion($sql);
        $this->assertSame($antes, $this->foto_comercial($dueno->id, $ids, $variantes), 'P2: igualdad íntegra, incluidos NULL y timestamps exactos.');
    }


    public function test_variantes_null_cero_negativas_y_papelera_restauran_filas_crudas()
    {
        $dueno = $this->nuevo_dueno();
        $ids = []; $variantes = [];
        foreach ([[null, null, false], [0, 0, false], [-3, -4, false], [-3, -4, true]] as $caso) {
            $a = $this->nuevo_articulo($dueno, ['stock' => $caso[0] === null ? null : $caso[0] + $caso[1]]);
            $ids[] = $a->id;
            foreach ([$caso[0], $caso[1]] as $n => $stock) {
                $v = ArticleVariant::create(['article_id' => $a->id, 'variant_description' => 'Cruda '.$n, 'stock' => $stock]);
                DB::table('article_variants')->where('id', $v->id)->update(['updated_at' => '2020-02-01 04:05:06']);
                $variantes[] = $v->id;
            }
            if ($caso[2]) { $a->delete(); }
        }
        $antes = $this->foto_comercial($dueno->id, $ids, $variantes);
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
        $this->ejecutar_reversion($sql, true);
        $this->assertSame($antes, $this->foto_comercial($dueno->id, $ids, $variantes));
    }

    public function test_sucursal_creada_restaura_empleados_antes_de_borrar_y_aisla_otro_owner()
    {
        $dueno = $this->nuevo_dueno();
        $empleado = $this->nuevo_dueno();
        DB::table('users')->where('id', $empleado->id)->update(['owner_id' => $dueno->id, 'address_id' => null]);
        $ajeno = $this->nuevo_dueno();
        $centinela = $this->nuevo_articulo($ajeno, ['stock' => -8]);
        $a = $this->nuevo_articulo($dueno, ['stock' => 12]);
        $antes = $this->foto_comercial($dueno->id, [$a->id]);
        $externo = $this->foto_comercial($ajeno->id, [$centinela->id]);
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
        $this->assertNotNull(DB::table('users')->where('id', $empleado->id)->value('address_id'));
        $bloques = $this->bloques_reversion($sql, true);
        $cuenta = end($bloques);
        $this->assertLessThan(strpos($cuenta, 'DELETE FROM addresses'), strpos($cuenta, 'UPDATE users'));
        $this->ejecutar_reversion($sql, true);
        $this->assertSame($antes, $this->foto_comercial($dueno->id, [$a->id]));
        $this->assertSame($externo, $this->foto_comercial($ajeno->id, [$centinela->id]));
        $logs = $this->foto('audit_logs', 'batch_uuid', [$this->uuid_respaldo($sql)]);
        $this->ejecutar_reversion($sql, true);
        $this->assertSame($logs, $this->foto('audit_logs', 'batch_uuid', [$this->uuid_respaldo($sql)]));
    }

    public function test_rechaza_stock_posterior_sin_pisar_movimientos_ni_auditoria()
    {
        $dueno = $this->nuevo_dueno();
        $a = $this->nuevo_articulo($dueno, ['stock' => 12]);
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
        DB::table('articles')->where('id', $a->id)->update(['stock' => 99]);
        $aplicado = $this->foto_comercial($dueno->id, [$a->id]);
        $audit = $this->foto('audit_logs', 'user_id', [$dueno->id]);
        $error = null;
        try { $this->ejecutar_reversion($sql, true); } catch (\PDOException $e) { $error = $e; }
        $this->assertInstanceOf(\PDOException::class, $error);
        $this->assertSame($aplicado, $this->foto_comercial($dueno->id, [$a->id]));
        $this->assertSame($audit, $this->foto('audit_logs', 'user_id', [$dueno->id]));
    }

    public function test_articulo_fallido_no_deja_bloque_falso_en_respaldo_parcial()
    {
        $dueno = $this->nuevo_dueno();
        $bien = $this->nuevo_articulo($dueno, ['stock' => 0]);
        $mal = $this->nuevo_articulo($dueno, ['stock' => 12]);
        $antes = $this->foto_comercial($dueno->id, [$bien->id, $mal->id]);
        $trigger = 'rollback_stock_'.bin2hex(random_bytes(6));
        $pdo = DB::connection()->getPdo();
        $pdo->exec("CREATE TRIGGER `".$trigger."` BEFORE INSERT ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fallo_movimiento_real'");
        try {
            $codigo = \Illuminate\Support\Facades\Artisan::call('sucursales:migrar-a-principal',
                ['user_id' => $dueno->id, '--aplicar' => true, '--salida' => $this->salida]);
        } finally { $pdo->exec('DROP TRIGGER `'.$trigger.'`'); }
        $this->assertSame(1, $codigo);
        $sql = file_get_contents(glob($this->salida.'/*/reversion.sql')[0]);
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
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
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

    public function test_fallo_de_respaldo_dentro_de_cuenta_aborta_cambios_comerciales()
    {
        $dueno = $this->nuevo_dueno();
        $a = $this->nuevo_articulo($dueno);
        $antes = $this->foto_comercial($dueno->id, [$a->id]);
        $listas = $this->foto('price_types', 'user_id', [$dueno->id]);
        $extensiones = $this->foto('extencion_empresa_user', 'user_id', [$dueno->id]);
        $cmd = new \App\Console\Commands\MigrarASucursalPrincipal();
        $cmd->setLaravel($this->app);
        $input = new \Symfony\Component\Console\Input\ArrayInput(['user_id' => $dueno->id, '--aplicar' => true, '--salida' => $this->salida], $cmd->getDefinition());
        foreach (['input' => $input, 'output' => new \Illuminate\Console\OutputStyle($input, new \Symfony\Component\Console\Output\BufferedOutput())] as $nombre => $valor) {
            $p = new \ReflectionProperty($cmd, $nombre); $p->setAccessible(true); $p->setValue($cmd, $valor);
        }
        $invocar = function ($nombre, array $args = []) use ($cmd) {
            $m = new \ReflectionMethod($cmd, $nombre); $m->setAccessible(true); return $m->invokeArgs($cmd, $args);
        };
        $m = $invocar('medir', [$dueno, 'Principal']);
        $this->assertTrue($invocar('abrir_respaldo', [$dueno->id]));
        if (!in_array('rollback.fallo_segundo', stream_get_filters(), true)) {
            stream_filter_register('rollback.fallo_segundo', \Tests\Feature\ListasSiempre\FalloSegundoRespaldoFilter::class);
        }
        $p = new \ReflectionProperty($cmd, 'respaldo'); $p->setAccessible(true);
        $stream = $p->getValue($cmd);
        $filter = stream_filter_append($stream, 'rollback.fallo_segundo', STREAM_FILTER_WRITE);
        $this->assertIsResource($filter);
        $error = null;
        try { $invocar('aplicar', [$dueno, $m, 'Principal']); } catch (\Throwable $e) { $error = $e; }
        finally { stream_filter_remove($filter); $invocar('cerrar_respaldo'); }
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame(9002, $error->getCode());
        $this->assertSame($antes, $this->foto_comercial($dueno->id, [$a->id]));
        $this->assertSame($listas, $this->foto('price_types', 'user_id', [$dueno->id]));
        $this->assertSame($extensiones, $this->foto('extencion_empresa_user', 'user_id', [$dueno->id]));
    }

}
