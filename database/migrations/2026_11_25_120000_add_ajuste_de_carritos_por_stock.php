<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Misión carritos-ajuste-stock (25/11/2026): ajuste automático de los carritos de la tienda cuando baja
 * el stock, con aviso por mail al comprador y restauración al reponer.
 *
 * Columnas nuevas (todas boolean NOT NULL con default 1, es decir prendidas):
 *  - online_configurations.ajustar_carritos_por_stock: ajustar los carritos cuando baja el stock.
 *  - online_configurations.avisar_ajuste_carrito_por_mail: mail al comprador cuando se ajusta su carrito.
 *  - online_configurations.avisar_puede_agotarse: aviso en el carrito de que el producto está en carritos
 *    de otros clientes (lo lee tienda-api).
 *  - article_cart.completar_al_reponer: casilla del comprador "Completar mi carrito cuando vuelva a haber
 *    stock" (lo escribe tienda-api; un renglón insertado por una tienda vieja, sin la columna, nace en 1).
 *
 * Índice nuevo: article_cart(article_id) llamado `ac_article_id_idx`. La tabla no tenía ningún índice y el
 * ajuste busca los renglones por artículo en cada movimiento de stock.
 *
 * Sin foreign keys. Cada paso tiene su guarda (hasColumn / consulta a information_schema) porque las bases de
 * los clientes pueden diferir y la migración tiene que poder correrse dos veces.
 */
class AddAjusteDeCarritosPorStock extends Migration
{
    /**
     * Nombre del índice por artículo de article_cart (corto, como piden las reglas de migraciones).
     *
     * @var string
     */
    const INDEX_NAME = 'ac_article_id_idx';

    /**
     * Agrega las columnas y el índice que todavía no existan.
     *
     * @return void
     */
    public function up()
    {
        // Las tres opciones del comercio, prendidas por defecto.
        foreach (['ajustar_carritos_por_stock', 'avisar_ajuste_carrito_por_mail', 'avisar_puede_agotarse'] as $column) {
            if (!Schema::hasColumn('online_configurations', $column)) {
                Schema::table('online_configurations', function (Blueprint $table) use ($column) {
                    $table->boolean($column)->default(1);
                });
            }
        }

        // Casilla del comprador en cada renglón del carrito.
        if (!Schema::hasColumn('article_cart', 'completar_al_reponer')) {
            Schema::table('article_cart', function (Blueprint $table) {
                $table->boolean('completar_al_reponer')->default(1);
            });
        }

        // Índice por artículo (sólo si la tabla todavía no lo tiene con este nombre).
        if (!self::index_exists()) {
            Schema::table('article_cart', function (Blueprint $table) {
                $table->index('article_id', self::INDEX_NAME);
            });
        }
    }

    /**
     * Quita lo que agregó `up()` (simétrico, con las mismas guardas).
     *
     * @return void
     */
    public function down()
    {
        if (self::index_exists()) {
            Schema::table('article_cart', function (Blueprint $table) {
                $table->dropIndex(self::INDEX_NAME);
            });
        }

        if (Schema::hasColumn('article_cart', 'completar_al_reponer')) {
            Schema::table('article_cart', function (Blueprint $table) {
                $table->dropColumn('completar_al_reponer');
            });
        }

        foreach (['ajustar_carritos_por_stock', 'avisar_ajuste_carrito_por_mail', 'avisar_puede_agotarse'] as $column) {
            if (Schema::hasColumn('online_configurations', $column)) {
                Schema::table('online_configurations', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * Indica si article_cart ya tiene el índice por artículo.
     *
     * @return bool
     */
    private static function index_exists()
    {
        // information_schema.statistics lista cada columna de cada índice de la base actual.
        $rows = DB::select(
            'select index_name from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ?',
            ['article_cart', self::INDEX_NAME]
        );

        return count($rows) > 0;
    }
}
