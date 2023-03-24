<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommissionerSaleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('commissioner_sale', function (Blueprint $table) {
            $table->id();
            $table->integer('commissioner_id')->unsigned();
            $table->integer('sale_id')->unsigned();
            $table->double('percentage');
            $table->boolean('is_seller')->default(0);
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
        Schema::dropIfExists('commissioner_sale');
    }
}
