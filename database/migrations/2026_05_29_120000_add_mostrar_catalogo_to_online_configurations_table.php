<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMostrarCatalogoToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega flag para mostrar u ocultar la sección Catálogo en el navbar de la tienda.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->boolean('mostrar_catalogo')->default(0)->nullable();
        });
    }

    /**
     * Revierte el flag de visibilidad de la sección Catálogo en la tienda.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('mostrar_catalogo');
        });
    }
}
