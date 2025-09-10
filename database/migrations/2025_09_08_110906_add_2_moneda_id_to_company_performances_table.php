<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Add2MonedaIdToCompanyPerformancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('company_performances', function (Blueprint $table) {

            $table->decimal('total_comprado_usd', 20,2)->nullable();
            $table->decimal('total_pagado_a_proveedores_usd', 20,2)->nullable();

            // $table->decimal('deuda_clientes_usd', 20,2)->nullable();
            $table->decimal('deuda_proveedores_usd', 20,2)->nullable();

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
