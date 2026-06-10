<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamaño de letra uniforme para encabezados de columnas en PDF tabular de artículos.
 */
class AddTableHeaderFontSizeToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega table_header_font_size al perfil PDF (aplica a todos los th del listado de artículos).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            /**
             * Tamaño de fuente en puntos para la fila de encabezados; null = 8 pt por defecto en el PDF.
             */
            $table->unsignedTinyInteger('table_header_font_size')->nullable()->after('header_image_url');
        });
    }

    /**
     * Revierte la columna de tipografía del encabezado tabular.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            $table->dropColumn('table_header_font_size');
        });
    }
}
