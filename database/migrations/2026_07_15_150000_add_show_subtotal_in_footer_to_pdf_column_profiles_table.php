<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShowSubtotalInFooterToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega flag para mostrar u ocultar la línea "Sub Total" en el pie del PDF de ventas.
     * Default true para mantener compatibilidad con perfiles existentes (hoy el Sub Total
     * se muestra siempre que haya descuentos o recargos).
     *
     * @return void
     */
    public function up()
    {
        /**
         * En algunos entornos la columna puede existir (hotfix/manual/merge).
         * Se valida antes de aplicar el alter para evitar error de migración.
         */
        if (! Schema::hasColumn('pdf_column_profiles', 'show_subtotal_in_footer')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                /**
                 * Controla si la línea "Sub Total: ..." se imprime en el pie.
                 * Solo tiene efecto cuando hay descuentos/recargos (total_bruto != total).
                 */
                $table->boolean('show_subtotal_in_footer')->default(true)->after('show_total_in_footer');
            });
        }
    }

    /**
     * Revierte la columna show_subtotal_in_footer del perfil PDF.
     *
     * @return void
     */
    public function down()
    {
        /**
         * Solo revierte si existe, para permitir rollbacks seguros en entornos desalineados.
         */
        if (Schema::hasColumn('pdf_column_profiles', 'show_subtotal_in_footer')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('show_subtotal_in_footer');
            });
        }
    }
}
