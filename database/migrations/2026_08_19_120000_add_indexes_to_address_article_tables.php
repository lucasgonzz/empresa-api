<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indices para el filtro por sucursal del Listado de articulos (19/8/2026).
 *
 * El operador `address_stock_seteado` de ExtraFiltersHelper arma dos EXISTS correlacionados:
 *
 *   exists (select ... from addresses join address_article
 *           where articles.id = address_article.article_id and addresses.id = ?)
 *   exists (select ... from article_variants
 *           where articles.id = article_variants.article_id and exists (
 *               select ... from addresses join address_article_variant
 *               where article_variants.id = address_article_variant.article_variant_id
 *                 and addresses.id = ?))
 *
 * Medido el 19/8/2026 contra el schema real: las tres tablas involucradas tenian UNICAMENTE su
 * PRIMARY(id). Sin indice, cada fila candidata de `articles` dispara un table scan de
 * `address_article`, y las que llegan al segundo EXISTS disparan ademas uno de `article_variants`
 * y otro de `address_article_variant` por variante. Sobre un catalogo de decenas de miles de
 * articulos eso es cuadratico, y `paginate()` corre la query DOS veces (el count y la pagina).
 *
 * Los dos indices de los pivotes son COMPUESTOS y en ese orden a proposito: la columna de la
 * correlacion va primera (es la que el EXISTS busca fila por fila) y la de la sucursal segunda, con
 * lo cual el mismo indice resuelve el WHERE entero sin ir a la tabla. Un indice suelto por columna
 * serviria para la correlacion pero obligaria a leer la fila para chequear la sucursal.
 *
 * `article_variants.article_id` se indexa aparte porque es la correlacion del EXISTS de afuera, y
 * esa tabla tampoco lo tenia (solo tenia el indice de bar_code).
 *
 * Misma familia de error que el grupo 375 (`2026_08_08_120000_add_indexes_to_remaining_sale_pivot_tables`):
 * pivote sin indice en la columna por la que SIEMPRE se lo consulta. Se sigue el mismo patron,
 * incluida la guarda por indice ya existente.
 */
class AddIndexesToAddressArticleTables extends Migration
{
    /**
     * Agrega los indices que el filtro por sucursal necesita.
     *
     * Cada index() va guardado: hay ~40 bases de clientes en estados distintos y en alguna el
     * indice pudo haberse agregado a mano. Sin la guarda, el primer "Duplicate key name" deja la
     * migracion a medias y sin fila en `migrations`.
     *
     * @return void
     */
    public function up()
    {
        // Pivote articulo <-> sucursal. Es el que consulta el filtro para los articulos cuyo stock
        // por sucursal lo cargo una persona en el articulo mismo.
        Schema::table('address_article', function (Blueprint $table) {
            if (!$this->ya_tiene_indice('address_article', 'article_id')) {
                $table->index(['article_id', 'address_id']);
            }
            if (!$this->ya_tiene_indice('address_article', 'address_id')) {
                $table->index('address_id');
            }
        });

        // Pivote variante <-> sucursal. Es el que consulta el filtro para los articulos con
        // variantes, donde el pivote del articulo es un valor derivado y no sirve.
        Schema::table('address_article_variant', function (Blueprint $table) {
            if (!$this->ya_tiene_indice('address_article_variant', 'article_variant_id')) {
                $table->index(['article_variant_id', 'address_id']);
            }
            if (!$this->ya_tiene_indice('address_article_variant', 'address_id')) {
                $table->index('address_id');
            }
        });

        // Correlacion del EXISTS de afuera (articles.id = article_variants.article_id). Esta tabla
        // solo tenia el indice de bar_code.
        Schema::table('article_variants', function (Blueprint $table) {
            if (!$this->ya_tiene_indice('article_variants', 'article_id')) {
                $table->index('article_id');
            }
        });
    }

    /**
     * Elimina los indices agregados en up(), con la misma guarda para no explotar si no estaban.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('address_article', function (Blueprint $table) {
            if ($this->ya_tiene_indice('address_article', 'article_id')) {
                $table->dropIndex(['article_id', 'address_id']);
            }
            if ($this->ya_tiene_indice('address_article', 'address_id')) {
                $table->dropIndex(['address_id']);
            }
        });

        Schema::table('address_article_variant', function (Blueprint $table) {
            if ($this->ya_tiene_indice('address_article_variant', 'article_variant_id')) {
                $table->dropIndex(['article_variant_id', 'address_id']);
            }
            if ($this->ya_tiene_indice('address_article_variant', 'address_id')) {
                $table->dropIndex(['address_id']);
            }
        });

        Schema::table('article_variants', function (Blueprint $table) {
            if ($this->ya_tiene_indice('article_variants', 'article_id')) {
                $table->dropIndex(['article_id']);
            }
        });
    }

    /**
     * Devuelve true si la tabla ya tiene un indice cuya PRIMERA columna es la que se le pasa.
     *
     * Se usa SHOW INDEX en vez de Schema::hasIndex() porque ese metodo no existe en la version de
     * Laravel del proyecto, y en vez de doctrine/dbal porque no queremos depender de que este
     * instalado en cada cliente. Mismo helper que uso el grupo 375.
     *
     * Seq_in_index = 1 es a proposito: un indice compuesto que empiece por esa columna ya sirve
     * para la correlacion, asi que tampoco hay que duplicarlo.
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return bool
     */
    private function ya_tiene_indice($tabla, $columna)
    {
        $indices = DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Column_name = ? AND Seq_in_index = 1', [$columna]);

        return count($indices) >= 1;
    }
}
