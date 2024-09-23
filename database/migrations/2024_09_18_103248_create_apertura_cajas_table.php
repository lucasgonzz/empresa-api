<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAperturaCajasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('apertura_cajas', function (Blueprint $table) {
            $table->id();

            $table->decimal('saldo_apertura', 30,2);
            $table->decimal('saldo_cierre', 30,2)->nullable();

            $table->timestamp('cerrada_at')->nullable();
            
            $table->integer('apertura_employee_id')->nullable();
            $table->integer('cierre_employee_id')->nullable();

            $table->integer('caja_id');

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
        Schema::dropIfExists('apertura_cajas');
    }
}
