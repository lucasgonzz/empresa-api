<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMostrarCatalogoCategoriasHomeToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega flag para mostrar u ocultar, debajo del banner del Inicio de la tienda, una tarjeta
     * por cada categoria (logo, nombre y descripcion). Distinto de mostrar_catalogo, que controla
     * el link "Catalogo" del navbar.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->boolean('mostrar_catalogo_categorias_home')->default(0)->nullable();
        });
    }

    /**
     * Revierte el flag de catalogo de categorias en el Inicio.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('mostrar_catalogo_categorias_home');
        });
    }
}
