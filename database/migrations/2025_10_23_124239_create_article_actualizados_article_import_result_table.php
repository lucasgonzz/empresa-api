<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleActualizadosArticleImportResultTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_actualizados_article_import_result', function (Blueprint $table) {
            $table->id();

            $table->integer('article_import_result_id');
            $table->integer('article_id');
            $table->json('updated_props')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article_actualizados_article_import_result');
    }
}
