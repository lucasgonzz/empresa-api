<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAlcanceAImportacionesDePrueba extends Migration
{
    public function up()
    {
        Schema::table('importaciones_de_prueba', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('importaciones_de_prueba', 'alcance')) {
                $table->string('alcance', 24)->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('importaciones_de_prueba', function (Blueprint $table) {
            $table->dropColumn('alcance');
        });
    }
}
