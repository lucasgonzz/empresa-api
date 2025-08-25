<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePriceTypeSurchagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('price_type_surchages', function (Blueprint $table) {
            $table->id();

            $table->string('name')->nullable();
            $table->decimal('percentage', 20,2)->nullable();
            $table->decimal('amount', 20,2)->nullable();
            $table->integer('position')->nullable();
            
            $table->integer('price_type_id')->nullable();
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
        Schema::dropIfExists('price_type_surchages');
    }
}
