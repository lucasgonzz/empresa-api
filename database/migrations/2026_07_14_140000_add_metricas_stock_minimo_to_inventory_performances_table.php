<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMetricasStockMinimoToInventoryPerformancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Agrega dos métricas nuevas al reporte de inventario, calculadas una sola vez
     * dentro del loop del job (InventoryPerformanceHelper::procesar_articulos), no en
     * cada request:
     * - stock_negativo: artículos con stock estrictamente menor a 0 (distinto de sin_stock,
     *   que ya cuenta los que están exactamente en 0). Un stock negativo suele indicar un
     *   error de carga o ventas sin ingreso de mercadería registrado.
     * - costo_reposicion_stock_minimo: costo estimado de reponer, para todos los artículos
     *   bajo el mínimo, la cantidad que falta para llegar al stock mínimo.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('inventory_performances', function (Blueprint $table) {

            // Cantidad de artículos con stock < 0 (no <= 0).
            $table->integer('stock_negativo')->nullable();

            // Costo estimado de reposición de todo lo que falta para llegar al stock mínimo.
            $table->decimal('costo_reposicion_stock_minimo', 30, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('inventory_performances', function (Blueprint $table) {
            $table->dropColumn(['stock_negativo', 'costo_reposicion_stock_minimo']);
        });
    }
}
