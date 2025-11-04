<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStockSuggestionArticlesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stock_suggestion_articles', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('stock_suggestion_id');
            $table->foreignId('article_id');
            $table->integer('from_address_id')->nullable();
            $table->integer('to_address_id')->nullable();
            $table->integer('suggested_amount')->nullable();

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
        Schema::dropIfExists('stock_suggestion_articles');
    }
}
