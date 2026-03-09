<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductionBatchesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id')->nullable();
            $table->integer('recipe_id')->nullable();

            // Usamos status por relación
            $table->integer('production_batch_status_id')->nullable();

            // Ruta elegida para esta producción (internal/external, y futuras)
            $table->integer('recipe_route_id')->nullable();
            // $table->string('recipe_route_code')->nullable(); // alternativa simple si preferís code

            $table->decimal('planned_amount', 18, 4)->nullable();
            $table->text('notes')->nullable();

            // opcional: quién creó el lote
            $table->integer('employee_id')->nullable();
            $table->integer('user_id')->nullable();

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
        Schema::dropIfExists('production_batches');
    }
}
