<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleCommerceOrderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_commerce_order', function (Blueprint $table) {
            $table->id();
            $table->integer('article_id');
            $table->integer('commerce_order_id');
            $table->decimal('price', 12,2)->nullable();
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
        Schema::dropIfExists('article_commerce_order');
    }
}
