<?php

namespace Tests\Feature\AuditoriaDeCambios;

require_once __DIR__.'/../ListasSiempre/RollbackHistorialMigracionTest.php';

use App\Models\Address;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ListasSiempre\RollbackIntegracionTestCase;

class ReversionMigracionTrazableTest extends RollbackIntegracionTestCase
{
    private function foto_auditoria()
    {
        // DB exclusiva: incluir también logs sin dueño y contrapartidas de dueño incorrecto.
        return json_decode(json_encode(DB::table('audit_logs')->orderBy('id')->get()), true);
    }

    private function preparar_migracion()
    {
        $dueno = $this->nuevo_dueno();
        $direccion = Address::create(['street' => 'Existente auditada', 'user_id' => $dueno->id, 'default_address' => 0]);
        DB::table('addresses')->where('id', $direccion->id)->update(['updated_at' => '2020-01-02 03:04:05']);
        $articulo = $this->nuevo_articulo($dueno, ['stock' => 12]);
        foreach ([5, 7] as $n => $stock) {
            $v = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Auditada '.$n, 'stock' => $stock]);
            DB::table('article_variants')->where('id', $v->id)->update(['updated_at' => '2020-02-0'.($n + 1).' 04:05:06']);
        }
        $antes = $this->foto_comercial($dueno->id, [$articulo->id]);
        $previos = $this->foto_auditoria();
        $this->assertNotEmpty($this->foto('audit_logs', 'user_id', [$dueno->id]), 'El fixture debe tener auditoría real propia.');
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
        $originales = $this->foto_auditoria();
        $this->assertSame($previos, $this->foto('audit_logs', 'id', array_column($previos, 'id')));
        $ids_previos = array_column($previos, 'id');
        $migracion = array_values(array_filter($originales, function ($fila) use ($ids_previos) {
            return !in_array($fila['id'], $ids_previos, true);
        }));
        $this->assertNotEmpty($migracion, 'Exigir logs de la migración, no reutilizar los previos.');
        $aplicado = $this->foto_comercial($dueno->id, [$articulo->id]);
        $this->assertNotSame($antes, $aplicado);
        return compact('dueno', 'direccion', 'articulo', 'antes', 'previos', 'sql', 'originales', 'migracion', 'aplicado');
    }

    private function estado_bloque(array $foto, $articulo)
    {
        // Contrato propuesto de estado CRUDO: tablas completas del bloque, no un delta ni un marcador.
        $claves = $articulo ? ['articulos', 'variantes', 'filas', 'filas_variantes', 'movimientos'] : ['usuarios', 'direcciones'];
        return array_intersect_key($foto, array_flip($claves));
    }

    public function test_conserva_auditoria_original_agrega_contrapartida_y_no_la_duplica()
    {
        $f = $this->preparar_migracion();
        $uuid = $this->uuid_respaldo($f['sql']);
        $esperados = [
            Article::class => ['id' => $f['articulo']->id, 'bloque' => 'articulo:'.$f['articulo']->id, 'logs' => []],
            Address::class => ['id' => $f['direccion']->id, 'bloque' => 'sucursal:'.$f['direccion']->id, 'logs' => []],
        ];
        foreach ($f['migracion'] as $log) {
            $tipo = $log['auditable_type'];
            if ($tipo === StockMovement::class) {
                $this->assertSame((int) $f['articulo']->id, (int) DB::table('stock_movements')->where('id', $log['auditable_id'])->value('article_id'), 'Resolver identidad de movimiento ANTES de eliminarlo.');
                $tipo = Article::class;
            } elseif ($tipo === ArticleVariant::class) {
                $this->assertSame((int) $f['articulo']->id, (int) DB::table('article_variants')->where('id', $log['auditable_id'])->value('article_id'), 'Resolver la variante real del bloque sin omitir su log.');
                $tipo = Article::class;
            } else {
                $this->assertArrayHasKey($tipo, $esperados, 'No descartar logs originales sin asignar bloque.');
                $this->assertSame((int) $esperados[$tipo]['id'], (int) $log['auditable_id']);
            }
            $esperados[$tipo]['logs'][] = (int) $log['id'];
        }
        $this->ejecutar_reversion($f['sql'], true);
        $restaurado = $this->foto_comercial($f['dueno']->id, [$f['articulo']->id]);
        $this->assertSame($f['antes'], $restaurado, 'Restauración comercial exacta antes de revisar la auditoría.');
        $this->assertSame($f['originales'], $this->foto('audit_logs', 'id', array_column($f['originales'], 'id')), 'Previos y migración son inmutables.');
        $reversiones = $this->foto_auditoria();
        $reversiones = array_values(array_filter($reversiones, function ($fila) use ($f) {
            return !in_array($fila['id'], array_column($f['originales'], 'id'), true);
        }));
        $this->assertCount(2, $reversiones, 'Exactamente una contrapartida por bloque: artículo y dirección.');
        $vistos = [];
        foreach ($reversiones as $fila) {
            $tipo = $fila['auditable_type'];
            $this->assertArrayHasKey($tipo, $esperados);
            $this->assertNotContains($tipo, $vistos, 'No duplicar un bloque omitiendo el otro.');
            $vistos[] = $tipo;
            $e = $esperados[$tipo];
            $this->assertSame('rollback', $fila['event']);
            $this->assertSame('console', $fila['source']);
            $this->assertSame((int) $f['dueno']->id, (int) $fila['user_id']);
            $this->assertSame((int) $e['id'], (int) $fila['auditable_id']);
            $this->assertStringContainsString('sucursales:migrar-a-principal', $fila['origin']);
            $this->assertMatchesRegularExpression('/reversi[oó]n|rollback/iu', $fila['origin']);
            $this->assertNull($fila['actor_id']);
            $this->assertSame($uuid, $fila['batch_uuid'], 'UUID del RESPALDO, no cualquiera de formato válido.');
            $viejos = json_decode($fila['old_values'], true);
            $nuevos = json_decode($fila['new_values'], true);
            $this->assertIsArray($viejos);
            $this->assertIsArray($nuevos);
            foreach (['respaldo_uuid', 'bloque', 'logs_originales', 'estado'] as $clave) {
                $this->assertArrayHasKey($clave, $nuevos);
            }
            $this->assertArrayHasKey('estado', $viejos);
            $this->assertSame($uuid, $nuevos['respaldo_uuid']);
            $this->assertSame($e['bloque'], $nuevos['bloque']);
            $this->assertNotEmpty($e['logs']);
            $this->assertIsArray($nuevos['logs_originales']);
            $ids = $nuevos['logs_originales'];
            sort($ids); sort($e['logs']);
            $this->assertSame($e['logs'], $ids, 'IDs exactos de ESTE bloque, sin previos, omisiones ni duplicados.');
            $this->assertSame($this->estado_bloque($f['aplicado'], $tipo === Article::class), $viejos['estado']);
            $this->assertSame($this->estado_bloque($restaurado, $tipo === Article::class), $nuevos['estado']);
        }
        $audit_una_vez = $this->foto_auditoria();
        $comercial_una_vez = $this->foto_comercial($f['dueno']->id, [$f['articulo']->id]);
        $this->ejecutar_reversion($f['sql'], true);
        $this->assertSame($audit_una_vez, $this->foto_auditoria(), 'Idempotencia auditada.');
        $this->assertSame($comercial_una_vez, $this->foto_comercial($f['dueno']->id, [$f['articulo']->id]), 'Idempotencia comercial COMPLETA.');
    }

    public function test_fallo_real_de_insert_auditado_aborta_la_compensacion_del_bloque()
    {
        $f = $this->preparar_migracion();
        $this->bloques_reversion($f['sql'], true);
        $pdo = DB::connection()->getPdo();
        $trigger = 'rollback_fallo_'.bin2hex(random_bytes(6));
        $mensaje = 'fallo_auditado_'.bin2hex(random_bytes(6));
        // Fallo REAL del motor en INSERT, no mock ni transacción exterior del fixture.
        $pdo->exec("CREATE TRIGGER `".$trigger."` BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".$mensaje."'");
        $fallo = null;
        try {
            try {
                $this->ejecutar_reversion($f['sql'], true);
            } catch (\PDOException $error) {
                $fallo = $error;
            }
        } finally {
            $pdo->exec('DROP TRIGGER `'.$trigger.'`');
        }
        $this->assertInstanceOf(\PDOException::class, $fallo, 'Debe intentar insertar la contrapartida y propagar el fallo.');
        $this->assertStringContainsString($mensaje, $fallo->getMessage(), 'No confundir un error comercial con el fallo de auditoría inyectado.');
        $this->assertSame('45000', $fallo->errorInfo[0]);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame($f['aplicado'], $this->foto_comercial($f['dueno']->id, [$f['articulo']->id]), 'El primer bloque NO debe confirmar compensación si no pudo auditarla.');
        $this->assertSame($f['originales'], $this->foto_auditoria());
    }
}
