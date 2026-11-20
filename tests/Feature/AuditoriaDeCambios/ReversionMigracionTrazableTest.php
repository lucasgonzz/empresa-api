<?php

namespace Tests\Feature\AuditoriaDeCambios;

require_once __DIR__.'/../ListasSiempre/RollbackHistorialMigracionTest.php';

use App\Models\Address;
use App\Models\Article;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ListasSiempre\RollbackIntegracionTestCase;

class ReversionMigracionTrazableTest extends RollbackIntegracionTestCase
{
    public function test_conserva_auditoria_original_agrega_contrapartida_y_no_la_duplica()
    {
        $dueno = $this->nuevo_dueno();
        Address::create(['street' => 'Existente auditada', 'user_id' => $dueno->id, 'default_address' => 0]);
        $articulo = $this->nuevo_articulo($dueno, ['stock' => 12]);
        $previos = $this->foto('audit_logs', 'user_id', [$dueno->id]);
        $this->assertNotEmpty($previos, 'El fixture debe tener auditoría real.');
        $sql = $this->migrar_y_leer('sucursales:migrar-a-principal', $dueno);
        $originales = $this->foto('audit_logs', 'user_id', [$dueno->id]);
        $this->assertGreaterThan(count($previos), count($originales), 'La migración debe dejar sus logs originales.');
        $ids_originales = array_column($originales, 'id');
        $this->ejecutar_reversion($sql);
        $this->assertSame($originales, $this->foto('audit_logs', 'id', $ids_originales), 'No borrar ni modificar logs originales.');
        $reversiones = DB::table('audit_logs')->where('user_id', $dueno->id)->where('event', 'rollback')->orderBy('id')->get();
        $this->assertNotEmpty($reversiones, 'P2: falta contrapartida explícita de la compensación.');
        $encontrado = false;
        foreach ($reversiones as $fila) {
            $this->assertSame('console', $fila->source);
            $this->assertStringContainsString('sucursales:migrar-a-principal', $fila->origin);
            $this->assertNull($fila->actor_id, 'No inventar actor para SQL de consola.');
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $fila->batch_uuid);
            $viejos = json_decode($fila->old_values, true);
            $nuevos = json_decode($fila->new_values, true);
            $this->assertIsArray($viejos);
            $this->assertIsArray($nuevos);
            foreach (['respaldo_uuid', 'bloque', 'logs_originales', 'estado'] as $clave) {
                $this->assertArrayHasKey($clave, $nuevos);
            }
            $this->assertNotEmpty($nuevos['bloque']);
            $this->assertSame($fila->batch_uuid, $nuevos['respaldo_uuid']);
            $this->assertNotEmpty($nuevos['logs_originales']);
            $this->assertSame([], array_values(array_diff($nuevos['logs_originales'], $ids_originales)));
            if ($fila->auditable_type === Article::class && (int) $fila->auditable_id === $articulo->id) {
                $encontrado = true;
                $this->assertArrayHasKey('estado', $viejos);
                $this->assertNotEquals($viejos['estado'], $nuevos['estado'], 'Aplicado → restaurado, no un marcador vacío.');
            }
        }
        $this->assertTrue($encontrado, 'Debe existir contrapartida del artículo compensado.');
        $audit_una_vez = $this->foto('audit_logs', 'user_id', [$dueno->id]);
        $articulo_una_vez = $this->foto('articles', 'id', [$articulo->id]);
        $this->ejecutar_reversion($sql);
        $this->assertSame($audit_una_vez, $this->foto('audit_logs', 'user_id', [$dueno->id]), 'Idempotencia: no duplicar ni modificar auditoría.');
        $this->assertSame($articulo_una_vez, $this->foto('articles', 'id', [$articulo->id]));
    }
}
