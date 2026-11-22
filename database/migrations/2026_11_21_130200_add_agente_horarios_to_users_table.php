<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los horarios que el dueño prefiere para las tareas del asistente (misión agente-tareas-programadas,
 * 21/11/2026): a qué hora es «a la mañana», «a la tarde», y qué días no quiere mensajes. JSON en `users` del
 * DUEÑO (como `agente_confianza`): {"manana":"09:00","tarde":"18:30","dias_sin_mensajes":[7]}. Null = los de
 * fábrica, que viven en el código. No se usa `ia_preferencias` porque otro helper la reescribe entera.
 *
 * Aditiva, nullable, sin claves foráneas y con guarda por columna.
 */
class AddAgenteHorariosToUsersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'agente_horarios')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('agente_horarios')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('users', 'agente_horarios')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('agente_horarios');
            });
        }
    }
}
