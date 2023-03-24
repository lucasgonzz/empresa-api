<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAddressesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->integer('num')->nullable();
            $table->string('street');
            $table->string('street_number')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('lat')->nullable();
            $table->string('lng')->nullable();
            $table->string('depto')->nullable();
            $table->string('description')->nullable();
            $table->integer('buyer_id')->unsigned()->nullable();
            $table->integer('user_id')->unsigned()->nullable();
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
        Schema::dropIfExists('addresses');
    }
}
