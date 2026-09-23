<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMostrarSubcategoriasAlClickCategoriaToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega flag para que, en el sidebar de la tienda, tocar el nombre de una categoria
     * despliegue sus subcategorias (en vez de navegar directo a todos sus articulos).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->boolean('mostrar_subcategorias_al_click_categoria')->default(0)->nullable();
        });
    }

    /**
     * Revierte el flag de despliegue de subcategorias al tocar el nombre en el sidebar.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('mostrar_subcategorias_al_click_categoria');
        });
    }
}
