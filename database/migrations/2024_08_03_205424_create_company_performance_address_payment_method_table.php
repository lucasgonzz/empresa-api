<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanyPerformanceAddressPaymentMethodTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('company_performance_address_payment_method', function (Blueprint $table) {
            $table->id();
            $table->integer('company_performance_id');
            $table->integer('current_acount_payment_method_id');
            $table->integer('amount');
            $table->integer('address_id');
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
        Schema::dropIfExists('company_performance_address_payment_method');
    }
}
