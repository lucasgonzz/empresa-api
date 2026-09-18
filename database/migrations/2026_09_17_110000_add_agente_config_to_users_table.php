<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se comporta el asistente de IA de cada negocio, configurable POR DUEÑO (misión
 * foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * `agente_confianza` es la autonomía del agente: 'cauteloso' pide confirmar cada carga (como
 * hasta hoy), 'resuelto' resuelve solo lo simple y sin riesgo —asignar la foto de una sucursal— y
 * avisa; lo que toca plata o borra, siempre pregunta. El default es 'resuelto' porque lo único que
 * auto-ejecuta es inocuo y da la experiencia "mandale la foto y listo" que pidió Lucas.
 *
 * `agente_pensamiento` es qué modelo usa: 'agil' (rápido y económico, el modelo actual) o
 * 'profundo' (mejores respuestas, más lento y más caro). El default es 'agil', o sea el modelo de
 * siempre: sin tocar la configuración, un negocio queda exactamente como está.
 *
 * Van como columnas en `users` del DUEÑO y no en `user_configurations` porque son del comercio, no
 * de la persona: el asistente contesta lo mismo (cobranzas, deudas, compras) sin importar quién lo
 * abra. `User` tiene guarded vacío y sin hidden para estas columnas, así que los dos valores viajan
 * solos en `GET api/user`.
 *
 * Sin foreign keys, con guard por columna para que sea segura de re-ejecutar.
 */
class AddAgenteConfigToUsersTable extends Migration
{
    /**
     * Agrega las dos columnas, cada una con su propio guard hasColumn.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'agente_confianza')) {
                /* 'cauteloso' | 'resuelto' — cuánta autonomía tiene el agente. Default 'resuelto'. */
                $table->string('agente_confianza', 20)->default('resuelto');
            }

            if (!Schema::hasColumn('users', 'agente_pensamiento')) {
                /* 'agil' | 'profundo' — qué modelo usa. Default 'agil' (el modelo actual). */
                $table->string('agente_pensamiento', 20)->default('agil');
            }
        });
    }

    /**
     * Elimina las columnas si existen.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'agente_confianza')) {
                $table->dropColumn('agente_confianza');
            }

            if (Schema::hasColumn('users', 'agente_pensamiento')) {
                $table->dropColumn('agente_pensamiento');
            }
        });
    }
}
