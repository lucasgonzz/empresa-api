<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticlePriceTypeMonedasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_price_type_monedas', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id');
            $table->integer('price_type_id');
            $table->integer('moneda_id');

            $table->decimal('final_price', 22, 2)->nullable();
            $table->decimal('percentage', 8, 2)->nullable();
            $table->boolean('setear_precio_final')->default(false);

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
        Schema::dropIfExists('article_price_type_monedas');
    }
}
