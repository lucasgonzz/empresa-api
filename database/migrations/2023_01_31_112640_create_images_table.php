<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            // Nombre de columna fijo y canónico: antes dependía de la variable de entorno
            // IMAGE_URL_PROP_NAME, lo que generó instalaciones inconsistentes en la flota
            // (algunas quedaron con `image_url`, otras con `hosting_url`). Grupo 215, prompt 01.
            $table->string('hosting_url');
            $table->integer('imageable_id')->nullable();
            $table->string('imageable_type');
            $table->integer('color_id')->nullable();
            $table->string('temporal_id')->nullable();
            $table->integer('tiendanube_image_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('images');
    }
}
