<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPreferenceTypeToTableColumnPreferences extends Migration
{
    public function up()
    {
        Schema::table('table_column_preferences', function (Blueprint $table) {
            $table->string('preference_type', 20)->default('table')->after('model_name');
        });

        Schema::table('table_column_preferences', function (Blueprint $table) {
            $table->dropUnique('table_column_preferences_user_id_model_name_unique');
            $table->index(['user_id', 'model_name', 'preference_type'], 'table_col_pref_user_model_type_idx');
        });
    }

    public function down()
    {
        Schema::table('table_column_preferences', function (Blueprint $table) {
            $table->dropIndex('table_col_pref_user_model_type_idx');
            $table->dropColumn('preference_type');
            $table->unique(['user_id', 'model_name'], 'table_column_preferences_user_id_model_name_unique');
        });
    }
}

