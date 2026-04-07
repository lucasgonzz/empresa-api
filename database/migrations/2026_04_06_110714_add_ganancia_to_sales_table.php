<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGananciaToSalesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            /**
             * Columna persistida para guardar la ganancia final de la venta.
             * Se calcula como total - total_cost desde el flujo de guardado.
             */
            $table->decimal('ganancia', 30, 2)->nullable()->after('total_cost');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            /** Se revierte la columna agregada para mantener rollback limpio. */
            $table->dropColumn('ganancia');
        });
    }
}
