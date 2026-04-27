<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega la columna log a la tabla sales.
 *
 * Guarda la auditoría detallada de acciones del módulo vender.
 * Se deja nullable para mantener compatibilidad con ventas históricas.
 */
class AddLogToSales extends Migration
{
    /**
     * Agrega la columna log (LONGTEXT nullable) en sales.
     */
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            // Auditoría completa serializada como JSON (puede crecer en tamaño).
            $table->longText('log')->nullable()->after('send_mail');
        });
    }

    /**
     * Elimina la columna log de sales.
     */
    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('log');
        });
    }
}
