<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleArticlesPreImportTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_articles_pre_import', function (Blueprint $table) {
            $table->id();
            $table->integer('article_id');
            $table->integer('articles_pre_import_id');
            $table->decimal('costo_actual', 18,2)->nullable();
            $table->decimal('costo_nuevo', 18,2)->nullable();
            $table->boolean('actualizado')->default(0)->nullable();
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
        Schema::dropIfExists('article_articles_pre_import');
    }
}
