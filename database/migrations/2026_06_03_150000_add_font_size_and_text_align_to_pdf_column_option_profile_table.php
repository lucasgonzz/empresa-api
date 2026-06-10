<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipografía por columna en perfiles PDF (p. ej. listado tabular de artículos).
 */
class AddFontSizeAndTextAlignToPdfColumnOptionProfileTable extends Migration
{
    /**
     * Agrega tamaño de letra y alineación horizontal configurables por columna.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pdf_column_option_profile', function (Blueprint $table) {
            /**
             * Tamaño de fuente en puntos para la columna; null = default del PDF (8 pt).
             */
            $table->unsignedTinyInteger('font_size')->nullable()->after('wrap_content');
            /**
             * Alineación horizontal: left, center, right; null = reglas automáticas legacy.
             */
            $table->string('text_align', 10)->nullable()->after('font_size');
        });
    }

    /**
     * Revierte columnas de tipografía del pivot.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pdf_column_option_profile', function (Blueprint $table) {
            $table->dropColumn(['font_size', 'text_align']);
        });
    }
}
