<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice `articles_user_slug_idx` sobre `articles (user_id, slug(191))` (misión
 * importacion-excel-motor-rapido, 24/9/2026).
 *
 * Es lo que vuelve barata la consulta de slugs tomados de la importación
 * (ArticleImport::slugs(), una por lote):
 *
 *     WHERE user_id = ? AND (slug = 'base' OR slug LIKE 'base-%' OR ...) AND deleted_at IS NULL
 *
 * `articles.slug` es TEXT desde la migración base (2019_10_24_002337) y ninguna migración lo
 * indexó: con `user_id` como único predicado indexable, MySQL entraba por un índice que
 * arranca en `user_id` y evaluaba los ~200 OR sobre TODAS las filas del comercio (en Servian,
 * 717.000 examinadas, 2 a 3 segundos por lote, medido el 23/9/2026). Con el prefijo de 191
 * cada `= base` y cada `LIKE 'base-%'` (prefijo constante, sin comodín adelante) son rangos del
 * índice. 191 es el tope clásico de un prefijo en utf8mb4 (767 bytes / 4), y un slug de más de
 * 191 caracteres no existe: Str::slug() de un nombre de artículo no llega ni a la mitad.
 *
 * La misma consulta de ArticleHelper::slug() (`WHERE user_id = ? AND slug = ?`, el ABM) también
 * la aprovecha.
 *
 * Va por DB::statement y no por Blueprint::index(): el Blueprint no sabe escribir la longitud de
 * prefijo, y sin prefijo un índice sobre TEXT no se puede crear. Sin foreign keys, como todo el
 * esquema. La guarda por NOMBRE (SHOW INDEX) hace idempotentes up() y down(), igual que
 * 2026_09_10_150000_add_hot_path_indexes_to_articles_and_inventory_pivot.php.
 */
class AddUserSlugIndexToArticlesTable extends Migration
{
    /** Nombre corto y fijo: la guarda y el down() miran exactamente este. */
    const INDICE = 'articles_user_slug_idx';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('articles') || !Schema::hasColumn('articles', 'slug')) {
            return;
        }

        if ($this->existe_indice()) {
            return;
        }

        DB::statement('ALTER TABLE `articles` ADD INDEX `' . self::INDICE . '` (`user_id`, `slug`(191))');
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
