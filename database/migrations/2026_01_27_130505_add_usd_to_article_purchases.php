<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUsdToArticlePurchases extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_purchases', function (Blueprint $table) {
            $table->decimal('cost_dolar', 30,2)->nullable();
            $table->decimal('price_dolar', 30,2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_purchases', function (Blueprint $table) {
            //
        });
    }
}
