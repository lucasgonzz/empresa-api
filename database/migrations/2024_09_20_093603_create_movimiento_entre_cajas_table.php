<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMovimientoEntreCajasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('movimiento_entre_cajas', function (Blueprint $table) {
            $table->id();
            $table->integer('num');

            $table->integer('from_caja_id');
            $table->integer('to_caja_id');
            $table->decimal('amount', 30,2);
            $table->integer('employee_id');

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
        Schema::dropIfExists('movimiento_entre_cajas');
    }
}
