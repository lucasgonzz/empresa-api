<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductionBatchStatusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('production_batch_statuses', function (Blueprint $table) {
            $table->id();
            
            $table->string('name'); // "Abierto", "Cerrado", "Cancelado"
            $table->string('slug')->unique(); // open, closed, canceled

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
        Schema::dropIfExists('production_batch_statuses');
    }
}
