<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCajaCajaResumenTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('caja_caja_resumen', function (Blueprint $table) {
            $table->id();

            $table->integer('resumen_caja_id');
            $table->integer('caja_id');
            $table->decimal('saldo_apertura', 14, 2)->default(0); // snapshot actual al momento de generar
            $table->decimal('saldo_cierre', 14, 2)->default(0); // snapshot actual al momento de generar
            $table->decimal('total_ingresos', 14, 2)->default(0);
            $table->decimal('total_egresos', 14, 2)->default(0);

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
        Schema::dropIfExists('caja_caja_resumen');
    }
}
