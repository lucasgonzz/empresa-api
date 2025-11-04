<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAcopioArticleDeliveryArticleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('acopio_article_delivery_article', function (Blueprint $table) {
            $table->id();

            $table->integer('acopio_article_delivery_id');
            $table->integer('article_id');
            $table->decimal('amount', 12,2)->nullable();

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
        Schema::dropIfExists('acopio_article_delivery_article');
    }
}
