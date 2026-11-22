<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHuellaDeArticuloToIaImagenPedidos extends Migration
{
    public function up()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('ia_imagen_pedidos', 'referencia_articulo_sha256')) {
                $table->string('referencia_articulo_sha256', 64)->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            $table->dropColumn('referencia_articulo_sha256');
        });
    }
}
