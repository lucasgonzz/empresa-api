<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProviderOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('provider_orders', function (Blueprint $table) {
            $table->id();
            $table->integer('num')->nullable();
            $table->boolean('total_with_iva')->nullable()->default(0);
            $table->boolean('total_from_provider_order_afip_tickets')->nullable()->default(0);
            $table->boolean('update_stock')->default(1);
            $table->boolean('update_prices')->default(1);
            $table->boolean('generate_current_acount')->default(1);

            $table->integer('provider_order_status_id')->unsigned()->default(1);
            $table->integer('provider_id')->unsigned();
            $table->integer('days_to_advise')->nullable();
            $table->integer('address_id')->nullable();

            $table->decimal('total', 30,2)->nullable();
            $table->decimal('total_iva', 30,2)->nullable();
            $table->decimal('sub_total', 30,2)->nullable();
            $table->decimal('total_descuento', 30,2)->nullable();

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
        Schema::dropIfExists('provider_orders');
    }
}
