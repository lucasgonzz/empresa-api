<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega la columna price_description a la tabla sales.
 *
 * Almacena como JSON serializado el array de descripciones del cálculo
 * del precio final de la venta (generado por vender_set_total.js en el frontend).
 */
class AddPriceDescriptionToSales extends Migration
{
    /**
     * Agrega la columna price_description (TEXT nullable) a sales.
     */
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            // Almacena el array de descripciones serializado como JSON
            $table->text('price_description')->nullable()->after('ganancia');
        });
    }

    /**
     * Elimina la columna price_description de sales.
     */
    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('price_description');
        });
    }
}
