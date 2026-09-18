<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCatalogHeaderLayoutToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega:
     * - catalog_header_layout: JSON nullable con el diseño del encabezado del PDF del catálogo de
     *   artículos (logo, nombre del negocio y renglones libres o tomados de los datos del negocio,
     *   en dos columnas, con la regla de en qué hojas sale cada cosa). Lo normaliza y lo lee
     *   CatalogHeaderLayoutHelper; lo dibuja ArticleTablePdf.
     *
     *   NO se reusa header_layout: esa columna guarda el esquema emisor/receptor que lee
     *   AfipPdfHelper para los PDF de venta, y un perfil puede cambiar de model_name por update();
     *   si compartieran columna, el render de venta se encontraría con un esquema ajeno.
     *
     *   Null = perfil sin diseño: el render del catálogo queda byte-idéntico al de antes de esta
     *   columna (misión catalogo-pdf-encabezado, 18/9/2026).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('pdf_column_profiles', 'catalog_header_layout')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                /**
                 * Nullable: perfil sin diseño de encabezado => el catálogo se imprime como hasta ahora.
                 */
                $table->json('catalog_header_layout')->nullable()->after('header_layout');
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
        if (Schema::hasColumn('pdf_column_profiles', 'catalog_header_layout')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('catalog_header_layout');
            });
        }
    }
}
