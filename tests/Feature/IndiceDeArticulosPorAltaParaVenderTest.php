<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión search-modal-paginado-y-busqueda-lenta (22/11/2026), criterio CA-A1.
 *
 * La página de resultados del buscador de Vender (`ORDER BY created_at DESC, id DESC LIMIT 50` con
 * `user_id = ? AND status = 'active' AND deleted_at IS NULL`) hacía filesort de todas las
 * coincidencias porque `articles_user_status_created_idx` lleva `es_insumo` entre `deleted_at` y el
 * final. Con `articles_user_st_del_created_idx (user_id, status, deleted_at, created_at)` el motor
 * lee el índice hacia atrás y corta a los 50 sin ordenar.
 *
 * Se prueba contra MySQL/MariaDB real (information_schema, EXPLAIN); con otro driver se saltea.
 * El test de EXPLAIN necesita datos COMMITEADOS y ANALYZE TABLE (sin estadísticas el optimizador
 * cree la tabla vacía): no usa DatabaseTransactions y borra lo que sembró.
 */
class IndiceDeArticulosPorAltaParaVenderTest extends TestCase
{
    /** Nombre fijo del índice que crea la migración. */
    const INDICE = 'articles_user_st_del_created_idx';

    /** Ruta de la migración, para probar su idempotencia con la clase real. */
    const MIGRACION = 'migrations/2026_11_22_100000_add_user_status_deleted_created_index_to_articles_table.php';

    /**
     * Saltea el test si la base no es MySQL/MariaDB.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Estos tests leen el esquema con SHOW INDEX / EXPLAIN, propios de MySQL.');
        }
    }

    /**
     * Columnas del índice, en orden de Seq_in_index. Vacío si no existe.
     *
     * @param  string $nombre
     * @return array
     */
    protected function columnas_del_indice($nombre)
    {
        $filas = DB::select('SHOW INDEX FROM `articles` WHERE Key_name = ?', [$nombre]);

        usort($filas, function ($a, $b) {
            return (int) $a->Seq_in_index - (int) $b->Seq_in_index;
        });

        return array_map(function ($fila) {
            return $fila->Column_name;
        }, $filas);
    }

    /**
     * Carga la clase de la migración (falla con un mensaje claro si el archivo no existe aún).
     *
     * @return object Instancia de la migración.
     */
    protected function instanciar_migracion()
    {
        $this->assertFileExists(database_path(self::MIGRACION), 'Falta la migración del índice de Vender.');

        require_once database_path(self::MIGRACION);

        return new \AddUserStatusDeletedCreatedIndexToArticlesTable();
    }

    /**
     * CA-A1: el índice existe con sus cuatro columnas en ese orden.
     *
     * @return void
     */
    public function test_el_indice_existe_con_sus_columnas_en_orden()
    {
        $this->assertSame(
            ['user_id', 'status', 'deleted_at', 'created_at'],
            $this->columnas_del_indice(self::INDICE),
            'El índice de la página de Vender falta o tiene otro orden de columnas.'
        );
    }

    /**
     * CA-A1: up() dos veces no falla ni duplica; down() lo saca (y se vuelve a dejar puesto).
     *
     * @return void
     */
    public function test_la_migracion_es_idempotente()
    {
        $migracion = $this->instanciar_migracion();

        try {
            $migracion->up();
            $migracion->up();
            $this->assertCount(4, DB::select('SHOW INDEX FROM `articles` WHERE Key_name = ?', [self::INDICE]));

            $migracion->down();
            $migracion->down();
            $this->assertSame([], $this->columnas_del_indice(self::INDICE));
        } finally {
            // El DDL no se revierte con transacciones: se deja la base como estaba.
            $migracion->up();
        }

        $this->assertSame(['user_id', 'status', 'deleted_at', 'created_at'], $this->columnas_del_indice(self::INDICE));
    }

    /**
     * CA-A1: la página de Vender (orden por alta descendente, límite 50) no hace filesort.
     *
     * @return void
     */
    public function test_la_pagina_de_vender_no_usa_filesort()
    {
        // Dueño ficticio con id alto: sus artículos se borran al terminar.
        $user_id = (int) DB::table('users')->insertGetId([
            'name' => 'Indice Vender', 'email' => uniqid('indice-vender-') . '@test.local', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $filas = [];
            for ($i = 1; $i <= 2000; $i++) {
                $filas[] = ['user_id' => $user_id, 'name' => 'Mazo ' . $i, 'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s', 1700000000 + $i * 60), 'updated_at' => now()];
            }
            foreach (array_chunk($filas, 500) as $lote) {
                DB::table('articles')->insert($lote);
            }
            DB::statement('ANALYZE TABLE articles');

            $plan = DB::select(
                "EXPLAIN SELECT * FROM articles WHERE user_id = ? AND status = 'active' AND deleted_at IS NULL"
                . " AND (es_insumo = 0 OR es_insumo IS NULL) AND (name LIKE '%ma%')"
                . ' ORDER BY created_at DESC, id DESC LIMIT 50',
                [$user_id]
            );

            $extra = isset($plan[0]->Extra) ? (string) $plan[0]->Extra : '';
            $this->assertStringNotContainsString('filesort', strtolower($extra), 'La página de Vender hace filesort: Extra = ' . $extra . ', key = ' . $plan[0]->key);
            $this->assertSame(self::INDICE, $plan[0]->key, 'La página de Vender debería leer el índice nuevo.');
        } finally {
            DB::table('articles')->where('user_id', $user_id)->delete();
            DB::table('users')->where('id', $user_id)->delete();
        }
    }
}
