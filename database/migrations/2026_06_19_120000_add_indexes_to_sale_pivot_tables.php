<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices en tablas pivote de venta para evitar table scan en DELETE/SELECT por sale_id.
 * Sin índice, detachItems() bloquea filas innecesarias bajo InnoDB REPEATABLE READ (Lock wait timeout).
 */
class AddIndexesToSalePivotTables extends Migration
{
    /**
     * Agrega índices en sale_id y en la FK opuesta de cada tabla pivote de venta.
     *
     * @return void
     */
    public function up()
    {
        // Pivot artículos ↔ ventas: acelera DELETE WHERE sale_id = X en detachItems().
        Schema::table('article_sale', function (Blueprint $table) {
            $table->index('sale_id');
            $table->index('article_id');
        });

        // Pivot combos ↔ ventas.
        Schema::table('combo_sale', function (Blueprint $table) {
            $table->index('sale_id');
            $table->index('combo_id');
        });

        // Pivot promociones vinoteca ↔ ventas.
        Schema::table('promocion_vinoteca_sale', function (Blueprint $table) {
            $table->index('sale_id');
            $table->index('promocion_vinoteca_id');
        });

        // Pivot servicios ↔ ventas.
        Schema::table('sale_service', function (Blueprint $table) {
            $table->index('sale_id');
            $table->index('service_id');
        });
    }

    /**
     * Elimina los índices agregados en up().
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_sale', function (Blueprint $table) {
            $table->dropIndex(['sale_id']);
            $table->dropIndex(['article_id']);
        });

        Schema::table('combo_sale', function (Blueprint $table) {
            $table->dropIndex(['sale_id']);
            $table->dropIndex(['combo_id']);
        });

        Schema::table('promocion_vinoteca_sale', function (Blueprint $table) {
            $table->dropIndex(['sale_id']);
            $table->dropIndex(['promocion_vinoteca_id']);
        });

        Schema::table('sale_service', function (Blueprint $table) {
            $table->dropIndex(['sale_id']);
            $table->dropIndex(['service_id']);
        });
    }
}
