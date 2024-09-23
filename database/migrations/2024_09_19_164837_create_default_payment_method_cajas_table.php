<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDefaultPaymentMethodCajasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('default_payment_method_cajas', function (Blueprint $table) {
            $table->id();

            $table->integer('current_acount_payment_method_id');
            $table->integer('caja_id');
            $table->integer('address_id')->nullable();

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
        Schema::dropIfExists('default_payment_method_cajas');
    }
}
