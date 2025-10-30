<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientHomesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_homes', function (Blueprint $table) {
            $table->id();

            $table->string('company_name')->nullable();
            $table->text('image_url')->nullable();
            $table->text('online')->nullable();
            $table->text('instragram')->nullable();
            $table->text('ciudad')->nullable();
            $table->text('provincia')->nullable();

            $table->integer('home_position')->nullable();

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
        Schema::dropIfExists('client_homes');
    }
}
