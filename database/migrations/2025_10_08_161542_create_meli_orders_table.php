<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMeliOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('meli_orders', function (Blueprint $table) {
            $table->id();

            $table->string('meli_order_id')->unique();
            $table->timestamp('meli_created_at')->nullable();
            $table->timestamp('meli_closed_at')->nullable();
            $table->string('status')->nullable();
            $table->string('status_detail')->nullable();
            $table->decimal('total_amount', 20, 2)->default(0);
            $table->decimal('shipping_cost', 20, 2)->nullable();
            $table->integer('meli_buyer_id')->nullable();
            $table->integer('user_id')->nullable();

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
        Schema::dropIfExists('meli_orders');
    }
}
