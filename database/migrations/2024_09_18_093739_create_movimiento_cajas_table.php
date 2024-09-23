<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMovimientoCajasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('movimiento_cajas', function (Blueprint $table) {
            $table->id();

            $table->integer('concepto_movimiento_caja_id')->nullable();

            $table->decimal('ingreso', 20,2)->nullable();
            $table->decimal('egreso', 20,2)->nullable();
            $table->decimal('saldo', 20,2)->nullable();

            $table->text('notas')->nullable();
            
            $table->integer('employee_id')->nullable();
            $table->integer('sale_id')->nullable();
            $table->integer('expense_id')->nullable();
            $table->integer('current_acount_id')->nullable();
            $table->integer('movimiento_entre_caja_id')->nullable();

            $table->integer('apertura_caja_id');
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
        Schema::dropIfExists('movimiento_cajas');
    }
}
