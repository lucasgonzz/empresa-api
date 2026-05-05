<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de diseño para PDF de ofertas por artículo (mitad de página A4).
 */
class CreateArticlePdfsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_pdfs', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('nombre', 120);
            $table->string('titulo', 200)->nullable();
            $table->boolean('mostrar_precio_anterior')->default(0);
            $table->text('texto_personalizado')->nullable();
            $table->boolean('motrar_fecha_impresion')->default(0);
            $table->timestamps();

            $table->index('user_id', 'apdf_user_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article_pdfs');
    }
}
