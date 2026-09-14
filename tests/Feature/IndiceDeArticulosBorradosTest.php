<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión busqueda-lenta-y-pausa-embeddings (14/9/2026) — el índice de `index_deleted()` existe
 * después de migrar, con las columnas en el orden que decidió el EXPLAIN.
 *
 * Ver el docblock de la migración `2026_09_14_130000_add_deleted_index_to_articles_table.php`
 * para los números completos (60.000 filas sintéticas, antes/después, EXPLAIN y tiempo real).
 * `deleted_at` va SEGUNDA a propósito: `index_deleted()` filtra por `user_id` (igualdad) y
 * `deleted_at IS NOT NULL` (rango) nada más -- sin `status` ni `es_insumo` de por medio, a
 * diferencia de `articles_user_status_created_idx` (el índice de `index()`, fase1) -- así que acá
 * alcanzan dos columnas.
 *
 * No corre DDL: verifica lo que dejó `migrate` y que volver a correr up() sobre una base ya
 * migrada sea un no-op (la guarda por nombre), sin tocar el esquema.
 */
class IndiceDeArticulosBorradosTest extends TestCase
{
    /** Ruta de la migración, para probar su idempotencia con la clase real. */
    const MIGRACION = 'migrations/2026_09_14_130000_add_deleted_index_to_articles_table.php';

    /**
     * Columnas de un índice, en el orden del índice (Seq_in_index). Vacío si no existe.
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @return array
     */
    protected function columnas_del_indice($tabla, $nombre)
    {
        $filas = DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Key_name = ?', [$nombre]);

        usort($filas, function ($a, $b) {
            return (int) $a->Seq_in_index - (int) $b->Seq_in_index;
        });

        return array_map(function ($fila) {
            return $fila->Column_name;
        }, $filas);
    }

    /**
     * articles_user_deleted_idx: (user_id, deleted_at).
     *
     * @return void
     */
    public function test_articles_tiene_el_indice_de_eliminados_en_el_orden_del_explain()
    {
        $this->assertSame(
            ['user_id', 'deleted_at'],
            $this->columnas_del_indice('articles', 'articles_user_deleted_idx'),
            'El índice de index_deleted() falta o cambió de orden: ver el docblock de la migración antes de tocarlo.'
        );
    }

    /**
     * Volver a correr up() sobre una base que ya tiene el índice no tira ni lo duplica (la guarda
     * por SHOW INDEX): es lo que pasa en una instancia donde alguien lo creó a mano por SSH antes
     * del upgrade.
     *
     * @return void
     */
    public function test_volver_a_correr_la_migracion_es_un_no_op()
    {
        require_once database_path(self::MIGRACION);

        $migracion = new \AddDeletedIndexToArticlesTable();

        $this->assertSame(['user_id', 'deleted_at'], \AddDeletedIndexToArticlesTable::COLUMNAS);

        $migracion->up();
        $migracion->up();

        $this->assertCount(2, DB::select('SHOW INDEX FROM `articles` WHERE Key_name = ?', ['articles_user_deleted_idx']));
    }
}
