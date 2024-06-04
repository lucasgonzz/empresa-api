<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCurrentAcountPaymentMethodSaleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('current_acount_payment_method_sale', function (Blueprint $table) {
            $table->id();

            $table->integer('current_acount_payment_method_id');
            $table->integer('sale_id');
            $table->decimal('amount', 8,2);

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
        Schema::dropIfExists('current_acount_payment_method_sale');
    }
}
