<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateColumnPositionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('column_positions', function (Blueprint $table) {
            $table->id();

            $table->string('name'); // Nombre del preset, ej: "Proveedor X"
            $table->string('model_name'); // Modelo relacionado, ej: "Articulo"
            $table->integer('start_row');
            $table->json('positions'); // Ej: {"name": "F", "price": "D"}
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
        Schema::dropIfExists('column_positions');
    }
}
