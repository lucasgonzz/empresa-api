<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommissionersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('commissioners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->double('percentage')->nullable();
            $table->bigInteger('seller_id')->unsigned()->nullable();
            $table->integer('user_id')->unsigned();
            
            // $table->foreign('seller_id')
            //         ->references('id')->on('sellers');
            // $table->foreign('user_id')
            //         ->references('id')->on('users');
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
        Schema::dropIfExists('commissioners');
    }
}
