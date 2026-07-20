<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLogoSizeAndDiscountDisplayModeToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega:
     * - logo_size_mm: tamaño del logo en mm por perfil (nullable; null = usar el global del dueño users.pdf_image_size).
     * - discount_display_mode: modo de listado de descuentos/recargos en el pie ('descriptivo' | 'simple').
     *   Default 'descriptivo' para mantener el comportamiento de los perfiles existentes.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('pdf_column_profiles', 'logo_size_mm')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                /**
                 * Nullable: cuando es null, el generador de PDF cae al tamaño global del dueño.
                 */
                $table->integer('logo_size_mm')->nullable()->after('margin_mm');
            });
        }

        if (! Schema::hasColumn('pdf_column_profiles', 'discount_display_mode')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                /**
                 * 'descriptivo' (default, comportamiento actual) o 'simple' (solo % + nombre).
                 */
                $table->string('discount_display_mode')->default('descriptivo')->after('show_subtotal_in_footer');
            });
        }
    }

    /**
     * Revierte ambas columnas si existen (rollback seguro en entornos desalineados).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('pdf_column_profiles', 'logo_size_mm')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('logo_size_mm');
            });
        }
        if (Schema::hasColumn('pdf_column_profiles', 'discount_display_mode')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('discount_display_mode');
            });
        }
    }
}
