<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTotalToMeliOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            $table->dropColumn('total_amount');
            $table->decimal('total', 22,2)->nullable();
            $table->integer('address_id')->nullable();
            $table->integer('seller_id')->nullable();
            $table->timestamp('fecha_entrega')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            //
        });
    }
}
