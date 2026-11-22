<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCupoImagenesToPruebaConfigAndImageAssignmentItems extends Migration
{
    public function up()
    {
        Schema::table('prueba_config', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('prueba_config', 'imagenes_tope')) {
                $table->unsignedInteger('imagenes_tope')->default(30);
            }
            if (!Schema::hasColumn('prueba_config', 'imagenes_usadas')) {
                $table->unsignedInteger('imagenes_usadas')->default(0);
            }
        });

        Schema::table('image_assignment_items', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('image_assignment_items', 'consumio_cupo_de_prueba')) {
                $table->boolean('consumio_cupo_de_prueba')->default(false);
            }
        });
    }

    public function down()
    {
        Schema::table('image_assignment_items', function (Blueprint $table) {
            $table->dropColumn('consumio_cupo_de_prueba');
        });

        Schema::table('prueba_config', function (Blueprint $table) {
            $table->dropColumn(['imagenes_tope', 'imagenes_usadas']);
        });
    }
}
