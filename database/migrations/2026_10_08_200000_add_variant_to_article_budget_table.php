<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: la VARIANTE de cada renglón de un presupuesto (misión presupuestos-con-variantes,
 * 8/10/2026).
 *
 * Hasta hoy `article_budget` guardaba artículo, cantidad y precio, pero no la variante. Un
 * presupuesto armado en Vender con Remera M y Remera L se guardaba como dos renglones del padre
 * sin variante: al reabrirlo los dos renglones tenían la misma identidad, y al confirmarlo la venta
 * perdía la variante y el stock salía del artículo y no de cada variante en su sucursal.
 *
 * Mismos tipos que `article_sale` (`2019_10_24_003425_create_article_sale_table.php`), para que la
 * venta que nace del presupuesto herede los dos valores tal cual. NULL = sin variante (o un
 * presupuesto anterior a estas columnas, que sigue exactamente igual). Sin FK y sin índice, como el
 * resto de las columnas de variante de los renglones.
 *
 * La base es compartida con `tienda`, pero `tienda-api` no lee `article_budget` (verificado en el
 * plan de la misión). Es aditiva y NULL por defecto: ninguna fila existente cambia.
 *
 * Guarda `hasColumn` por columna: hay ~40 bases de clientes en estados de esquema distintos y una
 * que ya tenga alguna no puede tumbar la migración. El código que las escribe o las lee va detrás
 * de VarianteEnPresupuestoEsquemaHelper, para la ventana del deploy en la que todavía no existen.
 */
class AddVariantToArticleBudgetTable extends Migration
{
    /**
     * Agrega las dos columnas, cada una solo si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('article_budget', 'article_variant_id')) {

            Schema::table('article_budget', function (Blueprint $table) {
                // La variante del renglón; NULL = sin variante o presupuesto anterior a la columna.
                $table->integer('article_variant_id')->nullable();
            });
        }

        if (!Schema::hasColumn('article_budget', 'variant_description')) {

            Schema::table('article_budget', function (Blueprint $table) {
                // La descripción de la variante al momento de guardar (la que se imprime en el PDF).
                $table->string('variant_description')->nullable();
            });
        }
    }

    /**
     * Saca las dos columnas, cada una solo si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('article_budget', 'article_variant_id')) {

            Schema::table('article_budget', function (Blueprint $table) {
                $table->dropColumn('article_variant_id');
            });
        }

        if (Schema::hasColumn('article_budget', 'variant_description')) {

            Schema::table('article_budget', function (Blueprint $table) {
                $table->dropColumn('variant_description');
            });
        }
    }
}
