<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade umbral opcional de días por venta para alertas de cobro pendiente.
 * Si es NULL, la API usa la jerarquía global (empleado/admin/owner).
 */
class AddDiasAlertaVentaNoCobradaPersonalizadoToSalesTable extends Migration
{
    /**
     * Ejecuta la migración: columna nullable sin FK.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedInteger('dias_alerta_venta_no_cobrada_personalizado')->nullable()->after('log');
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
            $table->dropColumn('dias_alerta_venta_no_cobrada_personalizado');
        });
    }
}
