<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategoryPriceTypeRangesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('category_price_type_ranges', function (Blueprint $table) {
            $table->id();

            $table->integer('category_id')->nullable();
            $table->integer('sub_category_id')->nullable();

            $table->integer('min')->nullable();
            $table->integer('max')->nullable();

            $table->integer('price_type_id');

            $table->integer('user_id');

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
        Schema::dropIfExists('category_price_type_ranges');
    }
}
