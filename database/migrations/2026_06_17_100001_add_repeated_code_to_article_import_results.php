<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a article_import_results los campos para registrar artículos creados
 * cuyo bar_code o provider_code ya existía en otro artículo de la BD.
 * Permite al usuario ver cuántos artículos quedaron con código repetido tras la importación.
 */
class AddRepeatedCodeToArticleImportResults extends Migration
{
    public function up()
    {
        Schema::table('article_import_results', function (Blueprint $table) {
            /* Cantidad de artículos nuevos que terminaron con un código repetido en BD. */
            $table->integer('created_with_repeated_code_count')->default(0);

            /* IDs de los artículos creados con código repetido, almacenados en JSON. */
            $table->json('created_with_repeated_code_ids')->nullable();
        });
    }

    public function down()
    {
        Schema::table('article_import_results', function (Blueprint $table) {
            $table->dropColumn(['created_with_repeated_code_count', 'created_with_repeated_code_ids']);
        });
    }
}
