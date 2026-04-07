<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShowTotalInFooterToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega flag para ocultar o mostrar el total general en el footer del PDF.
     * Default true para mantener compatibilidad con perfiles existentes.
     *
     * @return void
     */
    public function up()
    {
        /**
         * En algunos entornos la columna puede existir (hotfix/manual/merge).
         * Se valida antes de aplicar el alter para evitar error de migración.
         */
        if (! Schema::hasColumn('pdf_column_profiles', 'show_total_in_footer')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                /**
                 * Controla si el bloque "Total: ..." se imprime en el pie (o en la última hoja cuando aplica).
                 */
                $table->boolean('show_total_in_footer')->default(true)->after('footer_text');
            });
        }
    }

    /**
     * Revierte la columna show_total_in_footer del perfil PDF.
     *
     * @return void
     */
    public function down()
    {
        /**
         * Solo revierte si existe, para permitir rollbacks seguros en entornos desalineados.
         */
        if (Schema::hasColumn('pdf_column_profiles', 'show_total_in_footer')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('show_total_in_footer');
            });
        }
    }
}

