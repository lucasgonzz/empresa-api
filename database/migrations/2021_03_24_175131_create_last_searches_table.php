<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLastSearchesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('last_searches', function (Blueprint $table) {
            $table->id();

            $table->string('body');
            $table->unsignedBigInteger('buyer_id')->nullable();

            $table->foreign('buyer_id')->references('id')->on('buyers');

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
        Schema::dropIfExists('last_searches');
    }
}
