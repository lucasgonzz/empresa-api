<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IndiceDeUbicacionesPorArticuloTest extends TestCase
{
    const MIGRACION = 'migrations/2026_11_16_220000_add_article_index_to_article_article_ubication_table.php';
    const ARTICULO = 987654321;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('mysql', DB::connection()->getDriverName());
        require_once database_path(self::MIGRACION);

        // Las tablas temporales sombrean las reales solo en esta conexión. El DDL de la
        // migración se prueba sin borrar datos ni modificar el esquema persistente del slot.
        foreach (['article_article_ubication', 'article_ubications'] as $tabla) {
            $definicion = DB::select('SHOW CREATE TABLE `' . $tabla . '`')[0]->{'Create Table'};
            DB::statement(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definicion, 1));
        }
        foreach (DB::select('SHOW INDEX FROM `article_article_ubication` WHERE Key_name <> ?', ['PRIMARY']) as $fila) {
            if ((int) $fila->Seq_in_index === 1) {
                DB::statement('ALTER TABLE `article_article_ubication` DROP INDEX `' . str_replace('`', '``', $fila->Key_name) . '`');
            }
        }
        DB::table('article_ubications')->insert([
            ['id' => 1, 'name' => 'Ubicación de prueba A', 'user_id' => 1],
            ['id' => 2, 'name' => 'Ubicación de prueba B', 'user_id' => 1],
        ]);
        DB::table('article_article_ubication')->insert([
            ['article_id' => self::ARTICULO, 'article_ubication_id' => 1, 'ubication' => 'A1', 'notes' => 'Primera'],
            ['article_id' => self::ARTICULO, 'article_ubication_id' => 1, 'ubication' => 'A2', 'notes' => 'Otra fila válida'],
            ['article_id' => self::ARTICULO, 'article_ubication_id' => 2, 'ubication' => 'B1', 'notes' => null],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS `article_article_ubication`, `article_ubications`');
        } finally {
            parent::tearDown();
        }
    }

    protected function migracion()
    {
        return new \AddArticleIndexToArticleArticleUbicationTable();
    }

    protected function indices()
    {
        return DB::select('SHOW INDEX FROM `article_article_ubication`');
    }

    protected function consulta()
    {
        return DB::table('article_ubications')
            ->join('article_article_ubication', 'article_ubications.id', '=', 'article_article_ubication.article_ubication_id')
            ->where('article_article_ubication.article_id', self::ARTICULO)
            ->select('article_ubications.name', 'article_article_ubication.id', 'article_article_ubication.ubication', 'article_article_ubication.notes')
            ->orderBy('article_article_ubication.id');
    }

    protected function plan_del_pivot()
    {
        $consulta = $this->consulta();
        $plan = DB::select('EXPLAIN ' . $consulta->toSql(), $consulta->getBindings());
        foreach ($plan as $fila) {
            if ($fila->table === 'article_article_ubication') {
                return $fila;
            }
        }
        $this->fail('EXPLAIN no informó el acceso a la tabla intermedia.');
    }

    public function test_el_indice_evade_el_barrido_y_conserva_todas_las_ubicaciones()
    {
        $filas = [];
        for ($i = 1; $i <= 4000; $i++) {
            $filas[] = ['article_id' => $i, 'article_ubication_id' => 1 + $i % 2, 'ubication' => 'Otra ubicación', 'notes' => null];
        }
        foreach (array_chunk($filas, 500) as $tanda) {
            DB::table('article_article_ubication')->insert($tanda);
        }

        $antes = $this->consulta()->get()->toArray();
        $plan_antes = $this->plan_del_pivot();
        $this->assertNull($plan_antes->key);
        $this->assertSame('ALL', $plan_antes->type);
        $this->assertGreaterThan(1000, (int) $plan_antes->rows);

        $this->migracion()->up();

        $plan_despues = $this->plan_del_pivot();
        $this->assertSame(\AddArticleIndexToArticleArticleUbicationTable::INDICE, $plan_despues->key);
        $this->assertSame('ref', $plan_despues->type);
        $this->assertLessThan(10, (int) $plan_despues->rows);
        $this->assertEquals($antes, $this->consulta()->get()->toArray());
        $this->assertSame(4003, DB::table('article_article_ubication')->count());
        $this->assertSame(2, DB::table('article_article_ubication')->where('article_id', self::ARTICULO)->where('article_ubication_id', 1)->count());
        $this->assertSame(1, (int) DB::select('SHOW INDEX FROM `article_article_ubication` WHERE Key_name = ?', [\AddArticleIndexToArticleArticleUbicationTable::INDICE])[0]->Non_unique);
    }

    public function test_up_repetido_no_duplica_y_down_solo_quita_el_indice_propio()
    {
        $migracion = $this->migracion();
        $migracion->up();
        $migracion->up();
        $this->assertCount(3, $this->indices()); // PRIMARY + dos columnas de un único índice secundario.

        $migracion->down();
        $migracion->down();
        $this->assertCount(1, $this->indices());
        $this->assertSame(3, DB::table('article_article_ubication')->count());
        $migracion->up();
        $this->assertCount(3, $this->indices());
    }

    /** @dataProvider indices_ajenos_utilizables */
    public function test_conserva_un_indice_ajeno_cuyo_primer_campo_resuelve_la_busqueda($columnas)
    {
        DB::statement('ALTER TABLE `article_article_ubication` ADD INDEX `ubicaciones_preexistente` (' . $columnas . ')');
        $antes = $this->indices();
        $migracion = $this->migracion();
        $migracion->up();
        $migracion->up();
        $migracion->down();
        $this->assertEquals($antes, $this->indices());
        $this->assertSame(3, DB::table('article_article_ubication')->count());
    }

    public function indices_ajenos_utilizables()
    {
        return [
            'solo artículo' => ['`article_id`'],
            'mismo compuesto con otro nombre' => ['`article_id`, `article_ubication_id`'],
            'prefijo del compuesto más largo' => ['`article_id`, `article_ubication_id`, `id`'],
        ];
    }

    public function test_un_indice_con_article_id_segundo_no_evade_la_migracion()
    {
        DB::statement('ALTER TABLE `article_article_ubication` ADD INDEX `orden_inverso` (`article_ubication_id`, `article_id`)');
        $this->migracion()->up();
        $this->assertCount(2, DB::select('SHOW INDEX FROM `article_article_ubication` WHERE Key_name = ?', [\AddArticleIndexToArticleArticleUbicationTable::INDICE]));
        $this->migracion()->down();
        $this->assertCount(2, DB::select('SHOW INDEX FROM `article_article_ubication` WHERE Key_name = ?', ['orden_inverso']));
    }

    public function test_down_no_borra_un_homonimo_con_otra_definicion()
    {
        DB::statement('ALTER TABLE `article_article_ubication` ADD INDEX `' . \AddArticleIndexToArticleArticleUbicationTable::INDICE . '` (`article_ubication_id`)');
        $antes = $this->indices();
        $this->migracion()->down();
        $this->assertEquals($antes, $this->indices());
    }

    public function test_un_indice_invisible_no_sustituye_un_indice_que_mysql_pueda_usar()
    {
        if (!property_exists($this->indices()[0], 'Visible')) {
            $this->markTestSkipped('Este motor no ofrece índices invisibles de MySQL.');
        }
        DB::statement('ALTER TABLE `article_article_ubication` ADD INDEX `indice_invisible_previo` (`article_id`) INVISIBLE');
        $this->migracion()->up();
        $this->assertCount(2, DB::select('SHOW INDEX FROM `article_article_ubication` WHERE Key_name = ?', [\AddArticleIndexToArticleArticleUbicationTable::INDICE]));
        $this->migracion()->down();
        $this->assertCount(1, DB::select('SHOW INDEX FROM `article_article_ubication` WHERE Key_name = ?', ['indice_invisible_previo']));
    }

    public function test_down_conserva_un_homonimo_identico_que_existia_antes_de_migrar()
    {
        DB::statement('ALTER TABLE `article_article_ubication` ADD INDEX `' . \AddArticleIndexToArticleArticleUbicationTable::INDICE
            . '` (`article_id`, `article_ubication_id`) COMMENT \'Agregado antes de la migración\'');
        $antes = $this->indices();
        $this->migracion()->up();
        $this->migracion()->down();
        $this->assertEquals($antes, $this->indices());
    }
}
