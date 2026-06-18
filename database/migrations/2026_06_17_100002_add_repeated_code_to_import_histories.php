<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a import_histories el contador acumulado de artículos creados con código repetido.
 * Se incrementa de forma atómica por cada chunk que termine de procesar.
 */
class AddRepeatedCodeToImportHistories extends Migration
{
    public function up()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            /* Total acumulado de artículos creados con código repetido en todos los chunks. */
            $table->integer('created_with_repeated_code_count')->default(0);
        });
    }

    public function down()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            $table->dropColumn('created_with_repeated_code_count');
        });
    }
}
