<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticlePriceRangesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_price_ranges', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id');
            
            // Igual o Mayor o igual
            $table->string('modo');
            
            $table->decimal('amount', 10,2);
            $table->decimal('price', 20,2)->nullable();
            $table->string('temporal_id')->nullable();
            
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
        Schema::dropIfExists('article_price_ranges');
    }
}
