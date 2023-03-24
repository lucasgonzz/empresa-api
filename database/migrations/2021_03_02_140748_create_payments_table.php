<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->double('transaction_amount')->nullable();
            $table->string('token')->nullable();
            $table->string('description')->nullable();
            $table->string('email')->nullable();
            $table->integer('installments')->nullable();
            $table->string('payment_method_id')->nullable();
            $table->integer('issuer')->nullable();
            $table->string('doc_type')->nullable();
            $table->string('doc_number')->nullable();
            $table->string('status')->nullable();
            $table->string('status_detail')->nullable();
            $table->string('payment_id')->nullable();
            $table->integer('order_id')->unsigned()->nullable();
            $table->boolean('updated')->default(false);
            $table->string('customer_id')->nullable();
            $table->string('card_id')->nullable();
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
        Schema::dropIfExists('payments');
    }
}
