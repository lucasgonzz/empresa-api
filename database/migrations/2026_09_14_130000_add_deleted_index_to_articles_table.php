<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice de `index_deleted()` (misión busqueda-lenta-y-pausa-embeddings, 14/9/2026).
 *
 * `articles_user_deleted_idx` sobre `articles (user_id, deleted_at)`, sin foreign key, idempotente
 * por nombre (mismo mecanismo que `2026_09_10_190000_add_hot_path_indexes_to_articles_and_inventory_pivot.php`).
 *
 * Es la consulta de `ArticleController::index_deleted()` (GET article/index-deleted, sincronización
 * offline de artículos borrados), que el `php-slow.log` de ferretotal marcó lenta el 14/9/2026:
 *
 *     WHERE user_id = ? AND deleted_at IS NOT NULL [AND deleted_at >= ?] ORDER BY deleted_at DESC LIMIT 500
 *
 * más el `COUNT(*)` con la misma cláusula que agrega `paginate()`.
 *
 * 🔴 NO es la misma consulta que ya indexa `articles_user_status_created_idx` (el índice de
 * `index()`, fase1): esa arranca en `user_id, status`, y `index_deleted()` no filtra por `status` ni
 * por `es_insumo` -- withTrashed() sólo saca el scope global de `deleted_at IS NULL`, no agrega
 * `status`. Sin `status` fijo, el optimizador no puede usar la tercera columna de ese índice
 * (`deleted_at`) como rango ordenado: por eso hace falta un índice nuevo, más angosto, en vez de
 * reusar el que ya existe (confirmado con EXPLAIN, no a ojo -- ver la tabla de abajo).
 *
 * Se cargaron 60.000 filas sintéticas de un solo owner (60% con `deleted_at IS NULL`, 40% repartido
 * en los últimos ~400 días) en `empresa_testing_s2.articles`, y se corrieron las dos consultas antes
 * y después del índice:
 *
 * | Índice                                  | Listado (EXPLAIN)                                                          | Listado (tiempo) | COUNT(*) (EXPLAIN)                                          | COUNT(*) (tiempo) |
 * |------------------------------------------|-----------------------------------------------------------------------------|-------------------|---------------------------------------------------------------|--------------------|
 * | sin índice nuevo                          | ref sobre articles_user_updated_id_index, rows 29.911, Using where; Using filesort | 1,7 – 2,2 s       | ref sobre articles_user_status_created_idx, rows 29.911, Using index | 0,76 – 1,17 s      |
 * | candidato: (user_id, deleted_at)          | range sobre el nuevo, key_len 9, Using index condition; Backward index scan  | 0,46 – 0,66 s     | sigue en articles_user_status_created_idx, sin cambios         | 0,56 – 0,59 s      |
 *
 * El listado es el que de verdad mejora: sin índice, MySQL ordena en memoria (filesort) las ~30.000
 * filas que matchean antes de cortar en 500; con el índice, `ORDER BY deleted_at DESC LIMIT 500` se
 * sirve leyendo el índice hacia atrás, sin tocar las filas descartadas. El `COUNT(*)` no cambia de
 * plan (el optimizador sigue prefiriendo `articles_user_status_created_idx`, que ya es covering para
 * un conteo): la mejora de tiempo que se ve en la tabla es ruido de máquina, no el índice nuevo, y se
 * deja anotada para que quede clara la diferencia con el caso que sí importa.
 *
 * No se probó un segundo candidato: `(user_id, deleted_at)` ya deja "Using index condition; Backward
 * index scan" sin filesort, que es el óptimo para esta consulta (sólo filtra por esas dos columnas;
 * agregar una tercera no serviría a este WHERE y sólo agrandaría el índice sin beneficio).
 *
 * La guarda por `SHOW INDEX` (por NOMBRE) es para las instancias donde alguien lo haya creado a mano
 * por SSH antes de este upgrade.
 */
class AddDeletedIndexToArticlesTable extends Migration
{
    /** Nombre corto y fijo del índice: la guarda y el down() miran exactamente este. */
    const INDICE = 'articles_user_deleted_idx';

    /** Columnas del índice, en el orden que decidió el EXPLAIN (ver docblock: NO reordenar). */
    const COLUMNAS = ['user_id', 'deleted_at'];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if ($this->existe_indice()) {
            return;
        }

        Schema::table('articles', function (Blueprint $table) {
            $table->index(self::COLUMNAS, self::INDICE);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! $this->existe_indice()) {
            return;
        }

        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(self::INDICE);
        });
    }

    /**
     * ¿La tabla ya tiene un índice con ESE NOMBRE en la base conectada?
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
