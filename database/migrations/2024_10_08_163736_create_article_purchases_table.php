<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticlePurchasesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_purchases', function (Blueprint $table) {
            $table->id();

            $table->integer('client_id')->nullable();
            $table->integer('category_id')->nullable();
            $table->integer('sale_id');
            $table->integer('article_id');
            $table->decimal('amount', 12,2);
            $table->decimal('price', 30,2);
            $table->decimal('cost', 30,2)->nullable();

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
        Schema::dropIfExists('article_purchases');
    }
}
