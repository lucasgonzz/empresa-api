<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEmbeddingGeneratedAtToArticlesTable extends Migration
{
    /**
     * Agrega la columna embedding_generated_at a la tabla articles.
     *
     * Esta columna registra cuándo se generó por última vez el embedding vectorial
     * de cada artículo. El scheduler la usa para detectar artículos modificados
     * después de la última generación (updated_at > embedding_generated_at).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            // Timestamp nullable: NULL indica que el artículo aún no tiene embedding generado.
            $table->timestamp('embedding_generated_at')->nullable()->after('embedding');
        });
    }

    /**
     * Elimina la columna embedding_generated_at de la tabla articles.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('embedding_generated_at');
        });
    }
}
