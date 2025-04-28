<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRoadMapsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('road_maps', function (Blueprint $table) {
            $table->id();

            $table->integer('num');
            $table->integer('employee_id')->nullable();
            $table->timestamp('fecha_entrega')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('terminada')->default(0)->nullable();
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
        Schema::dropIfExists('road_maps');
    }
}
