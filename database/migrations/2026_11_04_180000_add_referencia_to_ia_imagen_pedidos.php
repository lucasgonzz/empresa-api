<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReferenciaToIaImagenPedidos extends Migration
{
    public function up()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('referencia_imagen_id')->nullable();
            $table->string('referencia_sha256', 64)->nullable();
            $table->string('referencia_path')->nullable();
            $table->unsignedBigInteger('publicada_image_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            $table->dropColumn(['article_id', 'referencia_imagen_id', 'referencia_sha256', 'referencia_path', 'publicada_image_id']);
        });
    }
}
