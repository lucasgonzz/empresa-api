<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentCardInfosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payment_card_infos', function (Blueprint $table) {
            $table->id();
            $table->string('token');
            $table->string('bin')->nullable();
            $table->integer('installments');
            $table->string('payment_id')->nullable(); 
            $table->string('card_brand')->nullable(); 
            $table->string('status')->nullable(); 
            $table->string('num_ticket')->nullable();
            $table->integer('payment_method_id')->nullable();
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
        Schema::dropIfExists('payment_card_infos');
    }
}
