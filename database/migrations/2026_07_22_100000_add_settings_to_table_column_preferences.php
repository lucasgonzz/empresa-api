<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `settings` (JSON, nullable) a table_column_preferences.
 *
 * A diferencia de `columns` (que guarda la config por columna: visible, order, width, etc.),
 * `settings` guarda configuración a nivel del modelo/preference_type completo — por ahora,
 * el modo de búsqueda del buscador general (ej: { "conector": "or" }). Se agrega después de
 * `columns` para mantener el orden lógico de las columnas de la tabla.
 *
 * Migración aditiva y reversible, sin foreign keys (regla del workspace).
 */
class AddSettingsToTableColumnPreferences extends Migration
{
    public function up()
    {
        Schema::table('table_column_preferences', function (Blueprint $table) {
            // Columna nullable: las filas existentes quedan sin settings hasta que se guarde algo explícito.
            $table->json('settings')->nullable()->after('columns');
        });
    }

    public function down()
    {
        Schema::table('table_column_preferences', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
}
