<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePriceTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('price_types', function (Blueprint $table) {
            $table->id();
            $table->integer('num')->nullable();
            $table->string('name');
            $table->decimal('percentage')->nullable();
            $table->integer('position')->nullable();
            $table->boolean('ocultar_al_publico')->nullable();
            $table->boolean('incluir_en_lista_de_precios_de_excel')->nullable();
            $table->boolean('setear_precio_final')->nullable();

            $table->boolean('se_usa_en_tienda_nube')->nullable();

            $table->integer('user_id');
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
        Schema::dropIfExists('price_types');
    }
}
