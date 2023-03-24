<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleProviderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_provider', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->bigInteger('article_id')->unsigned();
            $table->bigInteger('provider_id')->unsigned();
            $table->integer('amount')->nullable();
            $table->integer('cost')->nullable();
            $table->integer('price')->nullable();

            // $table->foreign('article_id')
            //         ->references('id')->on('articles');
            // $table->foreign('provider_id')
            //         ->references('id')->on('providers');

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
        Schema::dropIfExists('article_provider');
    }
}
