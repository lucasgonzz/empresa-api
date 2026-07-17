<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHeaderLayoutToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega:
     * - header_layout: JSON nullable con la ubicación/orden de los campos del header por cuadrante
     *   (emisor.izquierda, emisor.derecha, receptor.izquierda). Null = el render (prompt 439) usa
     *   un default por código según PdfColumnProfile::default_header_layout($is_afip).
     *   El tamaño del logo NO va acá: sigue viviendo en logo_size_mm (columna escalar existente).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('pdf_column_profiles', 'header_layout')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                /**
                 * Nullable: perfil sin diseño custom de header => el render aplica el default por código.
                 */
                $table->json('header_layout')->nullable()->after('logo_size_mm');
            });
        }
    }

    /**
     * Revierte la columna si existe (rollback seguro en entornos desalineados).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('pdf_column_profiles', 'header_layout')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('header_layout');
            });
        }
    }
}
