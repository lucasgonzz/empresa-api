<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMonedasToCompanyPerformancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('company_performances', function (Blueprint $table) {
            $table->decimal('total_vendido_usd', 20,2)->nullable();
            $table->decimal('total_pagado_mostrador_usd', 20,2)->nullable();
            $table->decimal('total_vendido_a_cuenta_corriente_usd', 20,2)->nullable();
            $table->decimal('total_pagado_a_cuenta_corriente_usd', 20,2)->nullable();
            $table->decimal('total_devolucion_usd', 20,2)->nullable();
            $table->decimal('total_ingresos_usd', 20,2)->nullable();
            $table->decimal('deuda_clientes_usd', 20,2)->nullable();
            $table->decimal('total_vendido_costos_usd', 20,2)->nullable();
            $table->decimal('deuda_proveedores', 20,2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('company_performances', function (Blueprint $table) {
            //
        });
    }
}
