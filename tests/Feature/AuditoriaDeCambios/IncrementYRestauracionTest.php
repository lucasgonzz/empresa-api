<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\Article;

/**
 * Test 12 del plan: `increment()` / `decrement()` sobre un modelo auditado dejan una fila
 * `updated` (misión auditoria-de-cambios, 30/9/2026). Más el evento `restored` de los modelos con
 * borrado lógico.
 *
 * `increment()` no pasa por `save()`: Eloquent hace su propio UPDATE y dispara el evento `updated`
 * a mano (Model::incrementOrDecrement), con el valor nuevo en `getChanges()` y el viejo todavía en
 * el original. Este test prueba que el listener lo ve y que guarda bien los dos valores.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class IncrementYRestauracionTest extends AuditoriaTestCase
{
    /**
     * @return void
     */
    public function test_increment_y_decrement_dejan_una_fila_updated_con_el_valor_viejo_y_el_nuevo()
    {
        $articulo = $this->crear_articulo(['name' => 'ZZ Auditoria increment', 'cost' => 100]);

        $desde = $this->filas(Article::class, 'updated')->count();

        $articulo->increment('cost', 25);

        $filas = $this->filas(Article::class, 'updated');

        $this->assertCount($desde + 1, $filas, 'increment() tiene que dejar una fila updated.');

        $viejos = json_decode($filas->last()->old_values, true);
        $nuevos = json_decode($filas->last()->new_values, true);

        $this->assertEquals(100, $viejos['cost']);
        $this->assertEquals(125, $nuevos['cost']);

        $articulo->decrement('cost', 5);

        $filas = $this->filas(Article::class, 'updated');

        $this->assertCount($desde + 2, $filas, 'decrement() tiene que dejar otra fila updated.');

        $viejos = json_decode($filas->last()->old_values, true);
        $nuevos = json_decode($filas->last()->new_values, true);

        $this->assertEquals(125, $viejos['cost']);
        $this->assertEquals(120, $nuevos['cost']);
    }

    /**
     * Un increment con columnas extra las registra también.
     *
     * @return void
     */
    public function test_increment_con_columnas_extra_registra_todas()
    {
        $articulo = $this->crear_articulo(['name' => 'ZZ Auditoria increment extra', 'cost' => 100]);

        $articulo->increment('cost', 10, ['name' => 'ZZ Auditoria increment extra editado']);

        $fila = $this->filas(Article::class, 'updated')->last();

        $nuevos = json_decode($fila->new_values, true);

        $this->assertEquals(110, $nuevos['cost']);
        $this->assertSame('ZZ Auditoria increment extra editado', $nuevos['name']);
        $this->assertSame('ZZ Auditoria increment extra', json_decode($fila->old_values, true)['name']);
    }

    /**
     * Borrar (lógico) y restaurar un artículo deja `deleted` y `restored`.
     *
     * @return void
     */
    public function test_borrar_y_restaurar_dejan_deleted_y_restored()
    {
        $articulo = $this->crear_articulo(['name' => 'ZZ Auditoria restaurar']);

        $articulo->delete();

        $articulo->restore();

        $this->assertCount(1, $this->filas(Article::class, 'deleted'));

        $restaurada = $this->filas(Article::class, 'restored');

        $this->assertCount(1, $restaurada);
        $this->assertSame((int) $articulo->id, (int) $restaurada->first()->auditable_id);
        $this->assertNull($restaurada->first()->old_values);
        $this->assertNull($restaurada->first()->new_values);
    }
}
