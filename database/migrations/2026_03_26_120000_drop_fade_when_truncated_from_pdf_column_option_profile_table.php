<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropFadeWhenTruncatedFromPdfColumnOptionProfileTable extends Migration
{
    /**
     * Elimina columna obsoleta de truncado difuminado en perfiles PDF.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pdf_column_option_profile', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_column_option_profile', 'fade_when_truncated')) {
                $table->dropColumn('fade_when_truncated');
            }
        });
    }

    /**
     * Revierte la eliminación de la columna de difuminado.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pdf_column_option_profile', function (Blueprint $table) {
            if (! Schema::hasColumn('pdf_column_option_profile', 'fade_when_truncated')) {
                $table->boolean('fade_when_truncated')->default(true)->after('wrap_content');
            }
        });
    }
}
