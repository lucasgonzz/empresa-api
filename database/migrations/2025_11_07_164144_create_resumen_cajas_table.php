<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateResumenCajasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resumen_cajas', function (Blueprint $table) {
            $table->id();

            $table->integer('address_id');
            $table->integer('turno_caja_id');
            $table->date('fecha'); // fecha "operativa" del turno
            $table->decimal('total_ingresos', 14, 2)->default(0);
            $table->decimal('total_egresos', 14, 2)->default(0);
            $table->decimal('saldo_apertura', 14, 2)->default(0); // suma de saldos snapshot de cada caja
            $table->decimal('saldo_cierre', 14, 2)->default(0); // suma de saldos snapshot de cada caja
            $table->decimal('saldo_cuenta_corriente', 14, 2)->default(0); // suma de saldos snapshot de cada caja
            // $table->json('meta')->nullable(); // opcional para guardar info adicional (ej: cajas abiertas/cerradas)
            $table->integer('employee_id')->nullable(); 
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
        Schema::dropIfExists('resumen_cajas');
    }
}
