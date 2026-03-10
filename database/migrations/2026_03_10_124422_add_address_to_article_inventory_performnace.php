<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAddressToArticleInventoryPerformnace extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_inventory_performance', function (Blueprint $table) {
            $table->integer('address_id')->nullable();
            $table->decimal('stock_address', 12,2)->nullable();
            $table->decimal('stock_min_address', 12,2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_inventory_performance', function (Blueprint $table) {
            //
        });
    }
}
