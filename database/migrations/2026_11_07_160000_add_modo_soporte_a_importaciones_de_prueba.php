<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddModoSoporteAImportacionesDePrueba extends Migration
{
    private const TABLAS = ['importaciones_de_prueba', 'ai_avisos_del_asistente'];

    public function up()
    {
        foreach (self::TABLAS as $tabla) {
            if (!Schema::hasColumn($tabla, 'modo_soporte')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->boolean('modo_soporte')->default(false);
                });
            }
        }
    }

    public function down()
    {
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasColumn($tabla, 'modo_soporte')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('modo_soporte');
                });
            }
        }
    }
}
