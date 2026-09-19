<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candado de sesión única endurecido por pestaña (misión candado-sesion-por-pestana,
 * 19/9/2026). Se lee del OWNER, nunca del empleado (mismo patrón que `activity_minutes`,
 * ver AuthHelper::get_activity_minutes()). Default false: para todo el parque existente el
 * comportamiento no cambia -pestañas del mismo navegador siguen conviviendo- hasta que Lucas
 * lo prenda a mano desde el admin, cliente por cliente.
 */
class AddBloquearPestanasDuplicadasToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('bloquear_pestanas_duplicadas')->default(false)->after('activity_minutes');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bloquear_pestanas_duplicadas');
        });
    }
}
