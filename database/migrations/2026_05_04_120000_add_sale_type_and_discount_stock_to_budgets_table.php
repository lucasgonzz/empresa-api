<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega estado de venta (sale_status_id) y flag de descuento de stock al presupuesto,
 * alineados con los campos equivalentes de ventas para propagarlos al crear la Sale.
 */
class AddSaleTypeAndDiscountStockToBudgetsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('budgets', function (Blueprint $table) {
            // Referencia lógica a sale_statuses.id (sin FK en BD, convención del proyecto).
            $table->integer('sale_status_id')->nullable()->after('price_type_id');
            // Si es false, la venta generada no debe descontar stock de artículos/promos (coherente con Sale.discount_stock).
            $table->boolean('discount_stock')->default(true)->after('sale_status_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn(['sale_status_id', 'discount_stock']);
        });
    }
}
