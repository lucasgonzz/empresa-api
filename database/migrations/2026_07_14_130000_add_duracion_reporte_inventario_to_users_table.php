<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDuracionReporteInventarioToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Minutos de vigencia del reporte de inventario (InventoryPerformance) antes de regenerarse.
            // Configurable por usuario porque el costo de generarlo depende de la cantidad de artículos
            // de cada cuenta (desde 2.000 hasta 400.000 artículos).
            $table->integer('duracion_reporte_inventario')->default(30);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
}
