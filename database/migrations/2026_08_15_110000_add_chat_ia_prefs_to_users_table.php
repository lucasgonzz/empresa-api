<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de UI del chat con el asistente de IA, POR PERSONA
 * (misión chat-ia-y-modulo-ia, D1).
 *
 * Van como columnas en `users` y no en `user_configurations` a propósito:
 * UserConfigurationController resuelve con el DUEÑO, así que guardar ahí le
 * pisaría la preferencia del dueño a cada empleado. En cambio `GET api/user`
 * devuelve la fila de la PERSONA y `User` tiene guarded vacío sin hidden para
 * estas columnas: los dos valores viajan solos en el arranque, con cero
 * requests extra.
 *
 * `chat_ia_fab_position` guarda "left,top" en px como string corto (D3: sin
 * casts JSON, parse trivial con explode y guard de dos enteros 0..20000).
 * `chat_ia_sidebar_width` es el ancho elegido de la sidebar del panel, en px.
 *
 * Sin foreign keys, con guard por columna para que sea segura de re-ejecutar.
 */
class AddChatIaPrefsToUsersTable extends Migration
{
    /**
     * Agrega las dos columnas, cada una con su propio guard hasColumn.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'chat_ia_fab_position')) {
                /* Posición del botón flotante: "left,top" en px de la persona */
                $table->string('chat_ia_fab_position', 60)->nullable();
            }

            if (!Schema::hasColumn('users', 'chat_ia_sidebar_width')) {
                /* Ancho de la sidebar del panel del chat, en px (la SPA clampa 180..420) */
                $table->unsignedInteger('chat_ia_sidebar_width')->nullable();
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
            if (Schema::hasColumn('users', 'chat_ia_fab_position')) {
                $table->dropColumn('chat_ia_fab_position');
            }

            if (Schema::hasColumn('users', 'chat_ia_sidebar_width')) {
                $table->dropColumn('chat_ia_sidebar_width');
            }
        });
    }
}
