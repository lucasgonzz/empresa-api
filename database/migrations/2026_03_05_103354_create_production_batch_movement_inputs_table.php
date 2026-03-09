<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductionBatchMovementInputsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('production_batch_movement_inputs', function (Blueprint $table) {
            $table->id();

            $table->integer('production_batch_movement_id');
            $table->integer('article_id'); // insumo
            $table->integer('address_id')->nullable(); // depósito origen del insumo

            $table->decimal('planned_amount', 18, 4)->default(0);
            $table->decimal('actual_amount', 18, 4)->default(0);

            // opcional pero útil para auditoría (en qué estado estaba definido el insumo)
            $table->integer('order_production_status_id')->nullable();

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
        Schema::dropIfExists('production_batch_movement_inputs');
    }
}
