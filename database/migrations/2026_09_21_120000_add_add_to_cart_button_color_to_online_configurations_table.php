<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAddToCartButtonColorToOnlineConfigurationsTable extends Migration
{
    /**
     * Color propio y opcional para el boton "Agregar al carrito" de la tienda online.
     * Sin default: NULL significa "todavia hereda el color secundario", que es el
     * comportamiento actual del boton. Asi el default se mantiene vivo -si el comercio
     * despues cambia su color secundario, el boton lo sigue- en vez de congelar una copia.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->string('add_to_cart_button_color', 20)->nullable();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('add_to_cart_button_color');
        });
    }
}
