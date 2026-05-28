<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía preference_type para soportar claves de relación belongs_to_many (ej: btm_provider_order_articles).
 * Recrea el índice compuesto con prefijo en model_name para respetar el límite de MySQL.
 */
class ExtendPreferenceTypeOnTableColumnPreferences extends Migration
{
    public function up()
    {
        Schema::table('table_column_preferences', function (Blueprint $table) {
            $table->dropIndex('table_col_pref_user_model_type_idx');
        });

        Schema::table('table_column_preferences', function (Blueprint $table) {
            $table->string('preference_type', 60)->default('table')->change();
        });

        DB::statement(
            'CREATE INDEX table_col_pref_user_model_type_idx ON table_column_preferences (user_id, model_name(60), preference_type)'
        );
    }

    public function down()
    {
        Schema::table('table_column_preferences', function (Blueprint $table) {
            $table->dropIndex('table_col_pref_user_model_type_idx');
        });

        Schema::table('table_column_preferences', function (Blueprint $table) {
            $table->string('preference_type', 20)->default('table')->change();
        });

        Schema::table('table_column_preferences', function (Blueprint $table) {
            $table->index(['user_id', 'model_name', 'preference_type'], 'table_col_pref_user_model_type_idx');
        });
    }
}
