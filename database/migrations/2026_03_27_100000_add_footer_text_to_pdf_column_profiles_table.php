<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFooterTextToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega columna de texto libre para el pie de página del PDF.
     * El texto se muestra debajo de los totales en cada página o solo en la última,
     * siguiendo la misma regla que show_totals_on_each_page.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            /**
             * Contenido libre del pie de página; se renderiza con MultiCell para soporte de texto largo.
             * Nullable para perfiles sin pie de página configurado.
             */
            $table->text('footer_text')->nullable()->after('show_totals_on_each_page');
        });
    }

    /**
     * Revierte la columna footer_text del perfil PDF.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            $table->dropColumn('footer_text');
        });
    }
}
