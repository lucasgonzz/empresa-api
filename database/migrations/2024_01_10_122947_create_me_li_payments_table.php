<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMeLiPaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('me_li_payments', function (Blueprint $table) {
            $table->id();
            $table->string('me_li_payment_id')->nullable();
            $table->decimal('transaction_amount', 12,2)->nullable();
            $table->string('status')->nullable();
            $table->timestamp('date_created')->nullable();
            $table->timestamp('date_last_modified')->nullable();
            $table->integer('me_li_order_id')->nullable();
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
        Schema::dropIfExists('me_li_payments');
    }
}
