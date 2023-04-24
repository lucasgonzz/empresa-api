<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->integer('num')->nullable();
            $table->enum('status', ['unconfirmed', 'canceled', 'confirmed', 'finished', 'delivered']);
            $table->boolean('deliver');
            $table->integer('address_id')->unsigned()->nullable();
            $table->string('description')->nullable();
            $table->integer('payment_id')->nullable();
            $table->integer('payment_method_id')->unsigned()->nullable();
            $table->integer('delivery_zone_id')->unsigned()->nullable();
            $table->decimal('percentage_card', 8, 2)->nullable();
            $table->integer('cupon_id')->unsigned()->nullable();
            $table->integer('buyer_id')->unsigned();
            $table->integer('order_status_id')->unsigned();
            $table->decimal('payment_method_discount')->nullable();
            $table->decimal('payment_method_surchage')->nullable();
            $table->integer('user_id')->unsigned();

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
        Schema::dropIfExists('orders');
    }
}
