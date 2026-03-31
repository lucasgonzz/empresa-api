<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración para agregar la columna iva_aplicado a la tabla sales.
 *
 * Por defecto en true (1): mantiene el comportamiento actual
 * donde los precios de venta se interpretan con IVA aplicado.
 */
class AddIvaAplicadoToSales extends Migration
{
    /**
     * Ejecuta la migración.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            // Flag que indica si los precios de la venta incluyen IVA.
            $table->boolean('iva_aplicado')->default(1);
        });
    }

    /**
     * Revierte la migración.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('iva_aplicado');
        });
    }
}
