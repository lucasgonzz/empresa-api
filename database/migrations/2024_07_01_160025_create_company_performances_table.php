<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanyPerformancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('company_performances', function (Blueprint $table) {
            $table->id();
            $table->integer('month')->nullable();
            $table->integer('year')->nullable();
            $table->integer('day')->nullable();
            
            $table->decimal('total_vendido', 20,2)->nullable();

            $table->decimal('total_pagado_mostrador', 20,2)->nullable();

            $table->decimal('total_vendido_a_cuenta_corriente', 20,2)->nullable();
            $table->decimal('total_pagado_a_cuenta_corriente', 20,2)->nullable();

            $table->decimal('total_devolucion', 20,2)->nullable();

            $table->decimal('total_ingresos', 20,2)->nullable();

            $table->integer('cantidad_ventas')->nullable();

            $table->decimal('total_gastos', 20,2)->nullable();

            $table->decimal('total_comprado', 20,2)->nullable();

            $table->decimal('deuda_clientes', 30,2)->nullable();

            $table->decimal('total_vendido_costos', 30,2)->nullable();

            $table->decimal('ingresos_netos', 30,2)->nullable();
            
            $table->decimal('rentabilidad', 30,2)->nullable();

            $table->decimal('total_facturado', 30,2)->nullable();

            $table->decimal('total_pagado_a_proveedores', 30,2)->nullable();
            
            $table->decimal('total_iva_comprado', 30,2)->nullable();

            $table->boolean('from_today')->default(0);
            
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
        Schema::dropIfExists('company_performances');
    }
}
