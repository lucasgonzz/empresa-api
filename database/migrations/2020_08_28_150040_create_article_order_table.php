<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleOrderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_order', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->integer('article_id')->unsigned();
            $table->integer('order_id')->unsigned();
            $table->decimal('cost')->nullable();
            $table->decimal('price');
            $table->integer('amount');
            $table->text('notes')->nullable();
            $table->bigInteger('variant_id')->nullable();
            $table->bigInteger('color_id')->nullable();
            $table->bigInteger('size_id')->nullable();
            $table->integer('address_id')->nullable();
            $table->decimal('with_dolar')->nullable();

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
        Schema::dropIfExists('article_order');
    }
}
