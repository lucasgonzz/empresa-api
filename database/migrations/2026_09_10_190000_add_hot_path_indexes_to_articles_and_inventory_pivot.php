<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices del camino caliente de artículos (misión optimizacion-vps-fase1, 10/9/2026, release 4.0.24).
 *
 * Dos índices, ninguna foreign key (como el resto del esquema), los dos idempotentes por nombre.
 *
 * 1. `articles_user_status_created_idx` sobre `articles (user_id, status, deleted_at, created_at, es_insumo)`.
 *
 *    Es la consulta de `ArticleController::index()` (GET article/index/from-status), que es la que
 *    baja el catálogo para el modo offline y la que la SPA pega cada vez que entra al Listado:
 *
 *        WHERE user_id = ? AND status = 'active' AND (es_insumo = 0 OR es_insumo IS NULL)
 *          AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 500
 *
 *    más el `COUNT(*)` con la misma cláusula que agrega `paginate()`. Hasta hoy `articles` tenía
 *    índices que empiezan por `user_id` y siguen por `updated_at`, `provider_code`, `bar_code` o
 *    `sku`: ninguno sirve el `status`, el `deleted_at` ni el `ORDER BY`. Y como una instancia es
 *    UN cliente, casi todas las filas de la tabla son del mismo `user_id`: un índice que discrimina
 *    solo por esa columna no discrimina nada. En ferretotal el listado tarda 1,1 s de SQL por
 *    página (informe 20260910-plan-optimizacion-vps.md, sección 2).
 *
 *    🔴 El orden de las columnas NO es intercambiable, y se decidió con EXPLAIN, no a ojo. Se
 *    cargaron 200.000 filas sintéticas de un solo owner en `empresa_testing_s3.articles` (166.000
 *    de ellas matcheando el listado) y se corrieron las dos consultas con cada candidato:
 *
 *    | Índice                                                  | Listado (EXPLAIN)                                                                  | Listado (tiempo)  | COUNT(*) (EXPLAIN)                                            | COUNT(*) (tiempo) |
 *    |---------------------------------------------------------|------------------------------------------------------------------------------------|-------------------|---------------------------------------------------------------|-------------------|
 *    | sin índice nuevo                                        | ref sobre articles_user_updated_id_index, rows 99.294, Using where; Using filesort | 0,58 – 0,62 s     | ref sobre el mismo, rows 99.294, Using where                  | 0,42 – 0,46 s     |
 *    | A: (user_id, status, deleted_at, created_at, es_insumo) | range sobre el nuevo, key_len 10, Using index condition; Backward index scan       | 0,0027 – 0,0031 s | ref const,const,const, rows 99.294, Using where; Using index  | 0,085 – 0,12 s    |
 *    | B: (user_id, status, es_insumo, deleted_at, created_at) | el optimizador NI LO ELIGE: mismo plan que sin índice, con filesort                | 0,64 – 0,70 s     | tampoco lo elige                                              | 0,43 – 0,71 s     |
 *
 *    Por qué gana A: `user_id = ?`, `status = ?` y `deleted_at IS NULL` son las tres igualdades
 *    (para el índice `IS NULL` es una igualdad más, por eso key_len = 4 + 1 + 5 = 10), y con las
 *    tres fijas la cuarta columna, `created_at`, ya está ordenada: el `ORDER BY ... DESC LIMIT 500`
 *    se sirve leyendo el índice hacia atrás y cortando en 500 filas, sin tocar las otras 165.000 y
 *    sin ordenar nada en memoria. `es_insumo` va ÚLTIMA a propósito: `es_insumo = 0 OR es_insumo
 *    IS NULL` son DOS rangos, y cualquier columna que venga después de un rango deja de estar
 *    ordenada para el optimizador. Eso es exactamente lo que le pasa a B: con `es_insumo` antes de
 *    `created_at` no puede servir el ORDER BY, así que MySQL vuelve al índice viejo y al filesort,
 *    y el índice nuevo queda ocupando disco sin que nadie lo use. Al final del índice, `es_insumo`
 *    se filtra con "Using index condition" sin ir a la fila, que es lo que se quiere de él.
 *
 *    El COUNT(*) también lo aprovecha ("Using index": lo resuelve leyendo solo el índice, sin
 *    tocar la tabla), 4-5 veces más rápido. No baja tanto como el listado porque un conteo tiene
 *    que recorrer todas las entradas que matchean, no 500.
 *
 *    El ALTER TABLE tardó 1,1 s sobre 200.000 filas; InnoDB lo hace INPLACE y sin bloquear
 *    lecturas. En servian (537k artículos) son unos segundos dentro del upgrade.
 *
 * 2. `aip_perf_article_idx` sobre `article_inventory_performance (inventory_performance_id, article_id)`.
 *
 *    La pivot del reporte de inventario (artículos bajo el stock mínimo) nació con la PK sola:
 *    `SHOW INDEX` en cualquier instancia devuelve una fila. Todo lo que la lee entra por
 *    `inventory_performance_id`: la relación `InventoryPerformance::articles_stock_minimo()`
 *    (belongsToMany, es el JOIN del endpoint paginado GET inventory-performance/articles-stock-minimo
 *    y del Excel de stock mínimo) y el `whereIn('inventory_performance_id', ...)->delete()` con el
 *    que `InventoryPerformanceHelper::crear_inventory_performance()` borra las filas de los reportes
 *    viejos. Sin índice, cada una de esas operaciones es un full scan de una tabla que en cuentas
 *    grandes tiene decenas de miles de filas por reporte, y que hasta la 4.0.23 se regeneraba
 *    varias veces por día. `article_id` va segundo porque el JOIN cierra por
 *    `articles.id = pivot.article_id` después de fijar el reporte.
 *
 * La guarda por `SHOW INDEX` (por NOMBRE, no por columnas) es para las instancias donde alguien
 * lo haya creado a mano por SSH antes de este upgrade: un `ADD INDEX` repetido tira y dejaría el
 * upgrade a medias. Es el mismo mecanismo que `2026_09_09_150000_add_buyer_id_index_to_messages_table.php`.
 */
class AddHotPathIndexesToArticlesAndInventoryPivot extends Migration
{
    /** Nombre corto y fijo del índice del listado de artículos: la guarda y el down() miran exactamente este. */
    const INDICE_ARTICLES = 'articles_user_status_created_idx';

    /** Columnas del índice del listado, en el orden que decidió el EXPLAIN (ver docblock: NO reordenar). */
    const COLUMNAS_ARTICLES = ['user_id', 'status', 'deleted_at', 'created_at', 'es_insumo'];

    /** Nombre corto y fijo del índice de la pivot del reporte de inventario. */
    const INDICE_PIVOT = 'aip_perf_article_idx';

    /** Columnas del índice de la pivot: primero el reporte, que es por donde entran todas las consultas. */
    const COLUMNAS_PIVOT = ['inventory_performance_id', 'article_id'];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->crear_indice('articles', self::INDICE_ARTICLES, self::COLUMNAS_ARTICLES);
        $this->crear_indice('article_inventory_performance', self::INDICE_PIVOT, self::COLUMNAS_PIVOT);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->borrar_indice('articles', self::INDICE_ARTICLES);
        $this->borrar_indice('article_inventory_performance', self::INDICE_PIVOT);
    }

    /**
     * Crea el índice si la tabla todavía no tiene uno con ese nombre.
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @param  array   $columnas
     * @return void
     */
    private function crear_indice($tabla, $nombre, array $columnas)
    {
        if ($this->existe_indice($tabla, $nombre)) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) use ($nombre, $columnas) {
            $table->index($columnas, $nombre);
        });
    }

    /**
     * Borra el índice si existe.
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @return void
     */
    private function borrar_indice($tabla, $nombre)
    {
        if (! $this->existe_indice($tabla, $nombre)) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) use ($nombre) {
            $table->dropIndex($nombre);
        });
    }

    /**
     * ¿La tabla ya tiene un índice con ESE NOMBRE en la base conectada?
     *
     * SHOW INDEX en vez de Schema::hasIndex() porque ese método no existe en el Laravel del
     * proyecto, y en vez de doctrine/dbal para no depender de que esté instalado en cada cliente.
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @return bool
     */
    private function existe_indice($tabla, $nombre)
    {
        return count(DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Key_name = ?', [$nombre])) > 0;
    }
}
