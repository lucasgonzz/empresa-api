<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMemoryColumnsToExportHistoriesTable extends Migration
{
    /**
     * Migración idempotente que agrega instrumentación de memoria por exportación, para poder
     * diagnosticar cuelgues por falta de memoria (OOM) en los jobs de exportación excel.
     *
     * Sin foreign keys (regla del workspace). Columnas nullable: los registros viejos quedan en
     * null sin problema, ya que la instrumentación solo aplica a exportaciones nuevas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('export_histories', function (Blueprint $table) {
            // Pico de memoria del proceso durante la exportación (MB). Null en registros viejos.
            if (!Schema::hasColumn('export_histories', 'peak_memory_mb')) {
                $table->decimal('peak_memory_mb', 8, 2)->nullable()->after('status');
            }

            // memory_limit vigente del worker al correr (MB). Null si no se pudo determinar.
            if (!Schema::hasColumn('export_histories', 'memory_limit_mb')) {
                $table->integer('memory_limit_mb')->nullable()->after('peak_memory_mb');
            }
        });
    }

    /**
     * Revierte la migración: elimina las columnas de instrumentación de memoria si existen.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('export_histories', function (Blueprint $table) {
            if (Schema::hasColumn('export_histories', 'memory_limit_mb')) {
                $table->dropColumn('memory_limit_mb');
            }

            if (Schema::hasColumn('export_histories', 'peak_memory_mb')) {
                $table->dropColumn('peak_memory_mb');
            }
        });
    }
}
