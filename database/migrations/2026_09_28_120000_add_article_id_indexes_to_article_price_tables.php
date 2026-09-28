<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice por `article_id` en las tablas de descuentos, recargos y listas de precio del artículo
 * (misión exportacion-articulos-streaming, 28/9/2026).
 *
 * Ninguna de las seis tablas tenía índice por `article_id`: solo la PK (y `article_discounts`, los
 * de `provider_id` y `provider_discount_id`). Todo lo que las lee entra por el artículo: las
 * relaciones `article_discounts`, `article_surchages`, `article_discounts_blanco`,
 * `article_surchages_blanco`, `price_types` y `price_type_monedas` de `Article`, que se cargan en
 * el listado, en Vender, en el recálculo de precios y en la exportación a Excel. Sin índice, cada
 * carga `WHERE article_id IN (...)` recorre la tabla entera.
 *
 * Medido en Servian (28/9/2026): `article_discounts` con ~315 mil filas y sin índice. La
 * exportación nueva lee los artículos de a mil: eran 750 recorridos completos de esa tabla solo
 * para armar el Excel.
 *
 * Mismo mecanismo que `2026_09_10_190000_add_hot_path_indexes_to_articles_and_inventory_pivot.php`:
 * sin foreign keys, idempotente por nombre, y además no crea nada si la tabla ya tiene algún índice
 * que empiece por `article_id` (alguien pudo haberlo creado a mano con otro nombre).
 */
class AddArticleIdIndexesToArticlePriceTables extends Migration
{
    /**
     * Tabla => [nombre del índice, columnas]. En los pivots de listas va también `price_type_id`:
     * la relación los lee por artículo y los ordena por lista.
     */
    const INDICES = [
        'article_discounts'          => ['article_discounts_article_id_idx', ['article_id']],
        'article_surchages'          => ['article_surchages_article_id_idx', ['article_id']],
        'article_discount_blancos'   => ['article_discount_blancos_article_id_idx', ['article_id']],
        'article_surchage_blancos'   => ['article_surchage_blancos_article_id_idx', ['article_id']],
        'article_price_type'         => ['article_price_type_article_id_idx', ['article_id', 'price_type_id']],
        'article_price_type_monedas' => ['apt_monedas_article_id_idx', ['article_id', 'price_type_id']],
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
