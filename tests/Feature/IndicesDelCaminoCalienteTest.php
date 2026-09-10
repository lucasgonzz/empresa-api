<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión optimizacion-vps-fase1 (10/9/2026, release 4.0.24) — los índices del camino caliente
 * existen después de migrar, con las columnas en el orden que decidió el EXPLAIN.
 *
 * El orden se fija en el test a propósito: `es_insumo` va última porque `es_insumo = 0 OR
 * es_insumo IS NULL` son dos rangos y cualquier columna después de un rango deja de servir el
 * ORDER BY. Con `es_insumo` antes de `created_at` el optimizador ni elige el índice (medido en la
 * migración). Si alguien "reordena" el índice, este test lo dice.
 *
 * No corre DDL: verifica lo que dejó `migrate` y que volver a correr up() sobre una base ya
 * migrada sea un no-op (la guarda por nombre), sin tocar el esquema.
 */
class IndicesDelCaminoCalienteTest extends TestCase
{
    /** Ruta de la migración, para probar su idempotencia con la clase real. */
    const MIGRACION = 'migrations/2026_09_10_190000_add_hot_path_indexes_to_articles_and_inventory_pivot.php';

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
     * articles_user_status_created_idx: (user_id, status, deleted_at, created_at, es_insumo).
     *
     * @return void
     */
    public function test_articles_tiene_el_indice_del_listado_en_el_orden_del_explain()
    {
        $this->assertSame(
            ['user_id', 'status', 'deleted_at', 'created_at', 'es_insumo'],
            $this->columnas_del_indice('articles', 'articles_user_status_created_idx'),
            'El índice del listado falta o cambió de orden: ver el docblock de la migración antes de tocarlo.'
        );
    }

    /**
     * aip_perf_article_idx: (inventory_performance_id, article_id).
     *
     * @return void
     */
    public function test_la_pivot_del_reporte_de_inventario_tiene_su_indice()
    {
        $this->assertSame(
            ['inventory_performance_id', 'article_id'],
            $this->columnas_del_indice('article_inventory_performance', 'aip_perf_article_idx')
        );
    }

    /**
     * Volver a correr up() sobre una base que ya tiene los índices no tira ni los duplica (la
     * guarda por SHOW INDEX): es lo que pasa en una instancia donde alguien los creó a mano por
     * SSH antes del upgrade.
     *
     * @return void
     */
    public function test_volver_a_correr_la_migracion_es_un_no_op()
    {
        require_once database_path(self::MIGRACION);

        $migracion = new \AddHotPathIndexesToArticlesAndInventoryPivot();

        $this->assertSame(['user_id', 'status', 'deleted_at', 'created_at', 'es_insumo'], \AddHotPathIndexesToArticlesAndInventoryPivot::COLUMNAS_ARTICLES);
        $this->assertSame(['inventory_performance_id', 'article_id'], \AddHotPathIndexesToArticlesAndInventoryPivot::COLUMNAS_PIVOT);

        $migracion->up();
        $migracion->up();

        $this->assertCount(5, DB::select('SHOW INDEX FROM `articles` WHERE Key_name = ?', ['articles_user_status_created_idx']));
        $this->assertCount(2, DB::select('SHOW INDEX FROM `article_inventory_performance` WHERE Key_name = ?', ['aip_perf_article_idx']));
    }
}
