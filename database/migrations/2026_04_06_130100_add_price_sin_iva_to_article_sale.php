<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPriceSinIvaToArticleSale extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_sale', function (Blueprint $table) {
            /**
             * Precio unitario sin IVA persistido para evitar recálculos posteriores.
             */
            $table->decimal('price_sin_iva', 25, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_sale', function (Blueprint $table) {
            //
        });
    }
}
