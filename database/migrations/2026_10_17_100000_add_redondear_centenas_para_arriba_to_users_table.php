<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRedondearCentenasParaArribaToUsersTable extends Migration
{
    /**
     * Agrega la opción "redondear centenas hacia arriba" al usuario.
     *
     * Con `redondear_centenas_en_vender` prendido, el redondeo por centenas va a la centena MÁS
     * CERCANA (round). Con esta opción además prendida va siempre hacia ARRIBA (ceil), que es lo que
     * hacía el sistema antes de la Tarea 4 y lo que algunos clientes todavía esperan en sus precios.
     * Default 0: nadie cambia de comportamiento sin pedirlo. Sola, sin centenas, no hace nada.
     *
     * Guarda con hasColumn: la migración es idempotente por si la columna ya se agregó a mano en una
     * base de producción.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('users', 'redondear_centenas_para_arriba')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('redondear_centenas_para_arriba')->default(false);
            });
        }
    }

    /**
     * Revierte la columna agregada en users.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('users', 'redondear_centenas_para_arriba')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('redondear_centenas_para_arriba');
            });
        }
    }
}
