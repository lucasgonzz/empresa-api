<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice `articles_user_chunk_idx` sobre `articles (user_id, chunk_number(60))` (misión
 * importacion-excel-motor-rapido, 24/9/2026).
 *
 * Al cerrar cada lote, ActualizarBBDD::set_articulos_creados_models() recupera los ids de lo
 * que acaba de insertar con
 *
 *     WHERE user_id = ? AND chunk_number = '<lote>-<import_history_id>'
 *
 * porque Article::insert() no devuelve ids. `chunk_number` es varchar(191) nullable
 * (2026_03_17_162905_add_import_id_to_articles) y nunca tuvo índice: MySQL entraba por un
 * índice que arranca en `user_id` y filtraba el comercio entero en cada lote con creados (en
 * Servian, 568.000 artículos, 1 a 3 segundos por lote). Con este índice la relectura es un
 * `ref` de exactamente las filas del lote.
 *
 * Prefijo de 60: los valores son "<n>-<id>" (menos de 20 caracteres); 60 deja margen de sobra
 * y cumple la regla del proyecto de acotar las columnas string en los índices. Va por
 * DB::statement porque Blueprint::index() no sabe escribir la longitud de prefijo. Sin foreign
 * keys, como todo el esquema. La guarda por NOMBRE (SHOW INDEX) hace idempotentes up() y
 * down(), igual que 2026_09_10_150000_add_hot_path_indexes_to_articles_and_inventory_pivot.php.
 */
class AddUserChunkNumberIndexToArticlesTable extends Migration
{
    /** Nombre corto y fijo: la guarda y el down() miran exactamente este. */
    const INDICE = 'articles_user_chunk_idx';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('articles') || !Schema::hasColumn('articles', 'chunk_number')) {
            return;
        }

        if ($this->existe_indice()) {
            return;
        }

        DB::statement('ALTER TABLE `articles` ADD INDEX `' . self::INDICE . '` (`user_id`, `chunk_number`(60))');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('articles') || !$this->existe_indice()) {
            return;
        }

        DB::statement('ALTER TABLE `articles` DROP INDEX `' . self::INDICE . '`');
    }

    /**
     * ¿`articles` ya tiene un índice con ESE NOMBRE en la base conectada?
     *
     * SHOW INDEX en vez de Schema::hasIndex() porque ese método no existe en el Laravel del
     * proyecto, y en vez de doctrine/dbal para no depender de que esté instalado en cada cliente.
     *
     * @return bool
     */
    private function existe_indice()
    {
        return count(DB::select('SHOW INDEX FROM `articles` WHERE Key_name = ?', [self::INDICE])) > 0;
    }
}
