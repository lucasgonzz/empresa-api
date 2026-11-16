<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMedidasEnvioToPromocionesYCombos extends Migration
{
    public function up()
    {
        foreach (['promocion_vinotecas', 'combos'] as $tabla) {
            foreach (['peso', 'alto', 'ancho', 'profundidad'] as $campo) {
                if (!Schema::hasColumn($tabla, $campo)) {
                    Schema::table($tabla, function (Blueprint $table) use ($campo) {
                        $table->decimal($campo, 30, 4)->nullable();
                    });
                }
            }
        }
    }

    public function down()
    {
        foreach (['promocion_vinotecas', 'combos'] as $tabla) {
            foreach (['peso', 'alto', 'ancho', 'profundidad'] as $campo) {
                if (Schema::hasColumn($tabla, $campo)) {
                    Schema::table($tabla, function (Blueprint $table) use ($campo) {
                        $table->dropColumn($campo);
                    });
                }
            }
        }
    }
}
