<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSheetTypesTable extends Migration
{
    /**
     * Crea catálogo de tipos de hoja reutilizable para perfiles PDF.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sheet_types', function (Blueprint $table) {
            /**
             * Clave primaria del tipo de hoja.
             */
            $table->id();
            /**
             * Nombre visible del tipo de hoja.
             */
            $table->string('name', 120);
            /**
             * Ancho de la hoja en milímetros.
             */
            $table->unsignedInteger('width');
            /**
             * Alto de la hoja en milímetros (nullable para tickets continuos).
             */
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla de tipos de hoja.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sheet_types');
    }
}
