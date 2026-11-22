<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReferenciaToIaImagenPedidos extends Migration
{
    public function up()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('ia_imagen_pedidos', 'article_id')) {
                $table->unsignedBigInteger('article_id')->nullable();
            }
            if (!Schema::hasColumn('ia_imagen_pedidos', 'referencia_imagen_id')) {
                $table->unsignedBigInteger('referencia_imagen_id')->nullable();
            }
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('ia_imagen_pedidos', 'referencia_sha256')) {
                $table->string('referencia_sha256', 64)->nullable();
            }
            if (!Schema::hasColumn('ia_imagen_pedidos', 'referencia_path')) {
                $table->string('referencia_path')->nullable();
            }
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('ia_imagen_pedidos', 'publicada_image_id')) {
                $table->unsignedBigInteger('publicada_image_id')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            $table->dropColumn(['article_id', 'referencia_imagen_id', 'referencia_sha256', 'referencia_path', 'publicada_image_id']);
        });
    }
}
