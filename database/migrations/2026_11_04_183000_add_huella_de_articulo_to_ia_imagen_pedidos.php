<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHuellaDeArticuloToIaImagenPedidos extends Migration
{
    public function up()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            $table->string('referencia_articulo_sha256', 64)->nullable();
        });
    }

    public function down()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            $table->dropColumn('referencia_articulo_sha256');
        });
    }
}
