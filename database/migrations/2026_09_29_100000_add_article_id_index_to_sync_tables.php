<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice por `article_id` en las colas de sincronización con Tienda Nube y Mercado Libre
 * (misión recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * `sync_to_t_n_articles` y `sync_to_meli_articles` nacieron solo con la PK (migraciones
 * 2025_11_19_114510 y 2025_10_09_094732). Las dos se consultan POR ARTÍCULO en el camino caliente
 * del precio: ArticleHelper::setFinalPrice() llama a TiendaNubeSyncArticleService::
 * add_article_to_sync() y a ProductService::add_article_to_sync(), y cada una hace un
 * `exists()` por article_id + user_id + status para no encolar dos veces el mismo artículo. Sin
 * índice, cada uno de esos exists() recorre la tabla entera: es el mismo patrón que tenía
 * `article_discounts` en Servian (200 ms por artículo), en un cliente con Tienda Nube o Mercado
 * Libre prendidos y la cola de sincronización crecida, multiplicado por cada artículo de cada
 * recálculo.
 *
 * Solo `article_id`: es lo que filtra de verdad (user_id y status repiten en casi todas las filas).
 *
 * Mismo mecanismo que `2026_09_28_120000_add_article_id_indexes_to_article_price_tables.php`: sin
 * foreign keys, idempotente por nombre, no crea nada si la tabla no existe o si ya tiene algún
 * índice que empiece por `article_id` (alguien pudo haberlo creado a mano con otro nombre), y el
 * down() borra solo lo que crea esta migración.
 */
class AddArticleIdIndexToSyncTables extends Migration
{
    /**
     * Tabla => [nombre del índice, columnas].
     */
    const INDICES = [
        'sync_to_t_n_articles'  => ['sync_to_t_n_articles_article_id_idx', ['article_id']],
        'sync_to_meli_articles' => ['sync_to_meli_articles_article_id_idx', ['article_id']],
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach (self::INDICES as $tabla => $indice) {
            $this->crear_indice($tabla, $indice[0], $indice[1]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        foreach (self::INDICES as $tabla => $indice) {
            $this->borrar_indice($tabla, $indice[0]);
        }
    }

    /**
     * Crea el índice si la tabla existe y no tiene ya uno con ese nombre ni uno que empiece por
     * `article_id`.
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @param  array   $columnas
     * @return void
     */
    private function crear_indice($tabla, $nombre, array $columnas)
    {
        if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'article_id')) {
            return;
        }

        foreach ($columnas as $columna) {
            if (! Schema::hasColumn($tabla, $columna)) {
                return;
            }
        }

        if ($this->existe_indice($tabla, $nombre) || $this->hay_indice_por_article_id($tabla)) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) use ($nombre, $columnas) {
            $table->index($columnas, $nombre);
        });
    }

    /**
     * Borra el índice si existe (solo el que crea esta migración, por nombre).
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @return void
     */
    private function borrar_indice($tabla, $nombre)
    {
        if (! Schema::hasTable($tabla) || ! $this->existe_indice($tabla, $nombre)) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) use ($nombre) {
            $table->dropIndex($nombre);
        });
    }

    /**
     * ¿La tabla ya tiene un índice con ESE NOMBRE? SHOW INDEX porque Schema::hasIndex() no existe
     * en el Laravel del proyecto.
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @return bool
     */
    private function existe_indice($tabla, $nombre)
    {
        return count(DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Key_name = ?', [$nombre])) > 0;
    }

    /**
     * ¿La tabla ya tiene algún índice cuya primera columna sea `article_id`?
     *
     * @param  string  $tabla
     * @return bool
     */
    private function hay_indice_por_article_id($tabla)
    {
        return count(DB::select(
            'SHOW INDEX FROM `' . $tabla . '` WHERE Column_name = ? AND Seq_in_index = 1',
            ['article_id']
        )) > 0;
    }
}
