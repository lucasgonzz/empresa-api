<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDesToProviderOrders extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('provider_orders', function (Blueprint $table) {
            $table->decimal('descuentos_individuales', 30,2)->nullable();
            $table->decimal('descuentos_compra', 30,2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('provider_orders', function (Blueprint $table) {
            //
        });
    }
}
