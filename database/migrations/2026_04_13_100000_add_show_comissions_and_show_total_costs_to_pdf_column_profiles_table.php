<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShowComissionsAndShowTotalCostsToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega flags para mostrar comisiones y total de costos en el pie del PDF.
     * Ambos defaults en false para no alterar perfiles existentes.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('pdf_column_profiles', 'show_comissions')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->boolean('show_comissions')->default(false)->after('show_totals_on_each_page');
            });
        }

        if (! Schema::hasColumn('pdf_column_profiles', 'show_total_costs')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->boolean('show_total_costs')->default(false)->after('show_comissions');
            });
        }
    }

    /**
     * Revierte los flags show_comissions y show_total_costs.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('pdf_column_profiles', 'show_total_costs')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('show_total_costs');
            });
        }

        if (Schema::hasColumn('pdf_column_profiles', 'show_comissions')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('show_comissions');
            });
        }
    }
}
