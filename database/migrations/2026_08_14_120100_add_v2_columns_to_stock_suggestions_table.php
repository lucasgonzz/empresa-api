<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: columnas de la v2 en stock_suggestions.
 *
 * - origen_generacion: 'manual' (botón del usuario) o 'automatica' (comando
 *   sugerencias:generar). Sin esta marca no hay forma de explicar en la vista
 *   una sugerencia que nadie pidió. Default 'manual': todas las filas viejas
 *   quedan correctamente marcadas sin backfill.
 * - resumen_ia / resumen_ia_estado / resumen_ia_error: el resumen en criollo
 *   escrito por la IA sobre el resultado ya calculado. Estados: null (no se
 *   pidió — p.ej. sin ANTHROPIC_API_KEY), 'pendiente', 'listo', 'error'.
 * - error_mensaje: detalle cuando la corrida entera falla (status 'error');
 *   sin esto una corrida caída quedaba 'pendiente' para siempre y sin pista.
 *
 * Todas las columnas nuevas son aditivas: ninguna fila existente cambia de
 * significado. Sin foreign keys, longitudes explícitas en los string.
 */
class AddV2ColumnsToStockSuggestionsTable extends Migration
{
    /**
     * Ejecuta la migración: agrega cada columna solo si no existe (guards
     * hasColumn para que sea segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('stock_suggestions', 'origen_generacion')) {
            Schema::table('stock_suggestions', function (Blueprint $table) {
                $table->string('origen_generacion', 20)->default('manual');
            });
        }

        if (! Schema::hasColumn('stock_suggestions', 'resumen_ia')) {
            Schema::table('stock_suggestions', function (Blueprint $table) {
                $table->text('resumen_ia')->nullable();
            });
        }

        if (! Schema::hasColumn('stock_suggestions', 'resumen_ia_estado')) {
            Schema::table('stock_suggestions', function (Blueprint $table) {
                $table->string('resumen_ia_estado', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('stock_suggestions', 'resumen_ia_error')) {
            Schema::table('stock_suggestions', function (Blueprint $table) {
                $table->text('resumen_ia_error')->nullable();
            });
        }

        if (! Schema::hasColumn('stock_suggestions', 'error_mensaje')) {
            Schema::table('stock_suggestions', function (Blueprint $table) {
                $table->text('error_mensaje')->nullable();
            });
        }
    }

    /**
     * Revierte la migración: elimina cada columna solo si existe.
     *
     * @return void
     */
    public function down()
    {
        $columnas = [
            'origen_generacion',
            'resumen_ia',
            'resumen_ia_estado',
            'resumen_ia_error',
            'error_mensaje',
        ];

        foreach ($columnas as $columna) {
            if (Schema::hasColumn('stock_suggestions', $columna)) {
                Schema::table('stock_suggestions', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
