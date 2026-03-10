<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddToCurrentAcountPaymentMethodExpense extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('expense_current_acount_payment_method', function (Blueprint $table) {
            $table->decimal('amount_cotizado', 30,2)->nullable();
            $table->decimal('cotizacion', 30,2)->nullable();
            $table->integer('moneda_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('expense_current_acount_payment_method', function (Blueprint $table) {
            //
        });
    }
}
