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
            $table->integer('provider_order_status_id')->unsigned()->default(1);
            $table->integer('provider_id')->unsigned();
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
