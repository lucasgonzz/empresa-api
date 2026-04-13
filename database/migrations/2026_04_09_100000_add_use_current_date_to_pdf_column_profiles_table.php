<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUseCurrentDateToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega flag para imprimir la fecha actual en lugar de la fecha del comprobante.
     * Default false para mantener compatibilidad con perfiles existentes.
     *
     * @return void
     */
    public function up()
    {
        /**
         * Se valida antes de aplicar el alter para evitar error en entornos
         * donde la columna ya pueda existir por hotfix o merge manual.
         */
        if (! Schema::hasColumn('pdf_column_profiles', 'use_current_date')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                /**
                 * Cuando es true, el PDF imprime la fecha del día en lugar de la fecha
                 * en que se generó el comprobante (Sale o AfipTicket).
                 */
                $table->boolean('use_current_date')->default(false)->after('show_total_in_footer');
            });
        }
    }

    /**
     * Revierte la columna use_current_date del perfil PDF.
     *
     * @return void
     */
    public function down()
    {
        /**
         * Solo revierte si existe, para permitir rollbacks seguros en entornos desalineados.
         */
        if (Schema::hasColumn('pdf_column_profiles', 'use_current_date')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('use_current_date');
            });
        }
    }
}
