<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanyPerformanceIngresosMostrador extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('company_performance_ingresos_mostrador', function (Blueprint $table) {
            $table->id();
            $table->integer('company_performance_id');
            $table->integer('current_acount_payment_method_id');
            $table->decimal('amount', 30,2);
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
        Schema::dropIfExists('company_performance_ingresos_mostrador');
    }
}
