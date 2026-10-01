<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPageLayoutToPdfColumnProfilesTable extends Migration
{
    /**
     * Diseño de la hoja de un diseño de PDF (misión diseno-pdf-configurable, 1/10/2026).
     *
     * - page_layout: JSON nullable con las cajas que van entre el encabezado y la tabla ("superior")
     *   y debajo de la tabla ("pie"), cada una con sus campos y su estilo. El esquema y sus límites
     *   viven en App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf.
     *   NULL = el diseño nunca se armó en el diseñador: el PDF sale EXACTAMENTE como siempre
     *   (NewSalePdf / ProfileDocumentPdf de antes, gobernados por los flags show_* del perfil).
     *   Por eso esta columna no se rellena con ningún seeder ni comando: los diseños que ya existen
     *   en los clientes no cambian hasta que alguien los abre en el diseñador y guarda.
     * - paper_height_mm: alto de la hoja en mm (nullable). Solo lo lee el PDF con page_layout;
     *   NULL = 297 (A4). El ancho sigue en paper_width_mm / printable_width_mm, que ya existían.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('pdf_column_profiles', 'page_layout')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->json('page_layout')->nullable()->after('catalog_header_layout');
            });
        }

        if (! Schema::hasColumn('pdf_column_profiles', 'paper_height_mm')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->unsignedSmallInteger('paper_height_mm')->nullable()->after('printable_width_mm');
            });
        }
    }

    /**
     * Revierte las dos columnas si existen (rollback seguro en entornos desalineados).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('pdf_column_profiles', 'page_layout')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('page_layout');
            });
        }

        if (Schema::hasColumn('pdf_column_profiles', 'paper_height_mm')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('paper_height_mm');
            });
        }
    }
}
