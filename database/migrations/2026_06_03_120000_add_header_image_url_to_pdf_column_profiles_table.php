<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imagen de cabecera opcional por plantilla PDF (p. ej. listado de artículos).
 */
class AddHeaderImageUrlToPdfColumnProfilesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('pdf_column_profiles', 'header_image_url')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->string('header_image_url', 500)->nullable();
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('pdf_column_profiles', 'header_image_url')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('header_image_url');
            });
        }
    }
}
