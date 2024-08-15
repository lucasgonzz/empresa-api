<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePendingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pendings', function (Blueprint $table) {
            $table->id();
            $table->string('detalle')->nullable();
            $table->timestamp('fecha_realizacion')->nullable();
            $table->boolean('es_recurrente')->nullable();
            $table->integer('unidad_frecuencia_id')->nullable();
            $table->integer('cantidad_frecuencia')->nullable();
            $table->text('notas')->nullable();
            $table->integer('expense_concept_id')->nullable();
            $table->decimal('expense_amount')->nullable();
            $table->boolean('completado')->nullable();
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
        Schema::dropIfExists('pendings');
    }
}
