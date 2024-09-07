<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticlePriceTypeTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_price_type', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id');
            $table->integer('price_type_id');
            $table->decimal('percentage', 12,2)->nullable();
            $table->decimal('price', 30,2)->nullable();
            $table->decimal('final_price', 30,2)->nullable();            
            
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
        Schema::dropIfExists('article_price_type');
    }
}
