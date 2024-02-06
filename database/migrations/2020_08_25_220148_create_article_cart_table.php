<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleCartTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_cart', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->integer('cart_id')->unsigned();
            $table->integer('article_id')->unsigned();
            $table->double('amount');
            $table->double('amount_insuficiente')->nullable();
            $table->double('price');
            $table->bigInteger('variant_id')->nullable();
            $table->text('notes')->nullable();
            // $table->bigInteger('color_id')->nullable();
            // $table->bigInteger('size_id')->nullable();

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
        Schema::dropIfExists('article_cart');
    }
}
