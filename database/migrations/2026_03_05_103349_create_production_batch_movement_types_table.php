<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductionBatchMovementTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('production_batch_movement_types', function (Blueprint $table) {
            $table->id();
            
            $table->string('name'); // "Inicio", "Avance", "Envío a proveedor", etc.
            $table->string('slug')->unique(); // start, advance, send_to_provider, receive_from_provider, reject, adjust

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
        Schema::dropIfExists('production_batch_movement_types');
    }
}
