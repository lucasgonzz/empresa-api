<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMarginMmToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega margen horizontal por lado para perfiles PDF.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            /**
             * Margen lateral en mm aplicado a izquierda y derecha.
             */
            $table->unsignedInteger('margin_mm')->default(5)->after('printable_width_mm');
        });
    }

    /**
     * Revierte la columna de margen lateral.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            $table->dropColumn('margin_mm');
        });
    }
}
