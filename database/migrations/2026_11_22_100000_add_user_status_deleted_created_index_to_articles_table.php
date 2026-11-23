<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice de la página de resultados del buscador de Vender (misión
 * search-modal-paginado-y-busqueda-lenta, 22/11/2026).
 *
 * `articles_user_st_del_created_idx` sobre `articles (user_id, status, deleted_at, created_at)`,
 * sin foreign key, idempotente por NOMBRE (mismo mecanismo que
 * `2026_09_14_130000_add_deleted_index_to_articles_table.php`).
 *
 * La consulta de la página de `SearchController::globalSearch` con contexto Vender es:
 *
 *     WHERE user_id = ? AND status = 'active' AND deleted_at IS NULL AND (es_insumo ...) AND (name LIKE ...)
 *     ORDER BY created_at DESC, id DESC LIMIT 50
 *
 * El índice que ya existe, `articles_user_status_created_idx (user_id, status, deleted_at,
 * created_at, es_insumo)`, lleva `es_insumo` DESPUÉS de `created_at`: como esa columna se filtra con
 * un OR (`= 0 OR IS NULL`), el motor no puede usarla como rango y el orden por `created_at` no sale
 * del índice: ordena en memoria (filesort) TODAS las coincidencias antes de cortar en 50. Con un
 * catálogo de 560.000 artículos y el criterio "ma" (200.000 coincidencias) eso eran ~570 ms de los
 * ~850 ms del pedido (medido en MariaDB 10.11, datos sintéticos).
 *
 * Este índice termina en `created_at`; InnoDB le agrega la clave primaria al final, así que entrega
 * `created_at DESC, id DESC` ya ordenado y el LIMIT corta a los 50 sin ordenar nada (EXPLAIN: sin
 * "Using filesort"; 1 ms en SQL). No cambia ninguna consulta ni ningún resultado. NO se toca
 * `articles_user_status_created_idx` (lo eligió el listado de fase1 con EXPLAIN).
 *
 * Costo: un índice más en `articles` encarece un poco los insert/update (importaciones grandes). En
 * una base de 870.000 filas el ADD INDEX tardó 0,7 s en la prueba local; en MySQL 8.4 es online
 * (INPLACE, sin bloquear escrituras) pero en una base grande tarda: avisarlo al desplegar.
 *
 * La guarda por `SHOW INDEX` (por NOMBRE) es para las instancias donde alguien lo haya creado a
 * mano por SSH antes de este upgrade.
 */
class AddUserStatusDeletedCreatedIndexToArticlesTable extends Migration
{
    /** Nombre corto y fijo del índice: la guarda y el down() miran exactamente este. */
    const INDICE = 'articles_user_st_del_created_idx';

    /** Columnas del índice, en el orden del WHERE y del ORDER BY (NO reordenar ni agregar es_insumo). */
    const COLUMNAS = ['user_id', 'status', 'deleted_at', 'created_at'];

    /**
     * Crea el índice si todavía no existe.
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
     * Saca el índice, sólo si existe.
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
