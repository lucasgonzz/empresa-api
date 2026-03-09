<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductionBatchMovementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('production_batch_movements', function (Blueprint $table) {
            $table->id();

            $table->integer('production_batch_id')->nullable();
            $table->integer('production_batch_movement_type_id')->nullable();

            // FROM es opcional: por tu regla lo vas a usar solo en advance (y quizá reject/receive si más adelante querés)
            $table->integer('from_order_production_status_id')->nullable();
            $table->integer('to_order_production_status_id')->nullable();

            $table->decimal('amount', 18, 4)->default(0);

            // proveedor solo si aplica (send_to_provider / receive_from_provider)
            $table->integer('provider_id')->nullable();

            // opcional: depósito destino/origen para el producto del batch (si lo necesitás)
            $table->integer('address_id')->nullable();
            $table->integer('to_address_id')->nullable();

            $table->text('notes')->nullable();

            $table->json('meta')->nullable();
            $table->integer('employee_id')->nullable();

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
        Schema::dropIfExists('production_batch_movements');
    }
}
