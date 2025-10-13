<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncFromMeliArticleArticleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sync_from_meli_article_article', function (Blueprint $table) {
            $table->id();

            $table->integer('sync_from_meli_article_id');
            $table->integer('article_id');
            $table->string('status')->nullable();
            $table->text('error_code')->nullable();

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
        Schema::dropIfExists('sync_from_meli_article_article');
    }
}
