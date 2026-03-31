<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración para agregar la columna discount_stock a la tabla sales.
 *
 * Por defecto en true (1): el comportamiento existente se mantiene para todas las ventas históricas.
 * Permite que el usuario indique si una venta debe o no descontar stock al crearse o actualizarse.
 */
class AddDiscountStockToSales extends Migration
{
    /**
     * Ejecuta la migración.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            // Indica si la venta debe descontar stock. Default 1 para preservar comportamiento histórico.
            $table->boolean('discount_stock')->default(1);
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
            $table->dropColumn('discount_stock');
        });
    }
}
