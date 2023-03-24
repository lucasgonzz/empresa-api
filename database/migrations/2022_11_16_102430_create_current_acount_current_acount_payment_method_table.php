<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCurrentAcountCurrentAcountPaymentMethodTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('current_acount_current_acount_payment_method', function (Blueprint $table) {
            $table->id();
            $table->integer('current_acount_id')->unsigned();
            $table->integer('current_acount_payment_method_id')->unsigned();
            $table->integer('credit_card_id')->nullable()->unsigned();
            $table->integer('credit_card_payment_plan_id')->nullable()->unsigned();
            $table->decimal('amount', 14,2)->nullable();
            $table->string('bank')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->string('num')->nullable();
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
        Schema::dropIfExists('current_acount_current_acount_payment_method');
    }
}
