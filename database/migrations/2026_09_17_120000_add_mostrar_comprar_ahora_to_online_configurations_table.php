<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMostrarComprarAhoraToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega flag que decide si la ficha del articulo de la tienda muestra el boton
     * "Comprar ahora" (agrega el articulo al carrito y lleva directo a terminar la compra)
     * ademas de "Agregar al carrito". Arranca apagado: con el flag en 0 la ficha sigue
     * mostrando solo "Agregar al carrito" y el comprador puede seguir navegando, que es el
     * comportamiento que las tiendas ya tienen hoy.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->boolean('mostrar_comprar_ahora')->default(0)->nullable();
        });
    }

    /**
     * Revierte el flag del boton "Comprar ahora" de la ficha del articulo de la tienda.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('mostrar_comprar_ahora');
        });
    }
}
