<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCotizarDesdeOtraMonedaToArticlePriceTypeMonedas extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_price_type_monedas', function (Blueprint $table) {
            $table->boolean('cotizar_desde_otra_moneda')
                ->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_price_type_monedas', function (Blueprint $table) {
            //
        });
    }
}
