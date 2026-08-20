<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ancho del PANEL ENTERO del chat de IA, por persona (pedido de Lucas, 19/8/2026).
 *
 * Es una columna aparte de `chat_ia_sidebar_width` y no un reemplazo: son dos medidas
 * independientes que el usuario arrastra por separado. `chat_ia_sidebar_width` es la
 * columna de conversaciones ADENTRO del modal; ésta es el ancho del modal completo, que
 * hasta ahora estaba clavado en 820px en el CSS.
 *
 * Misma decisión que la migración de 2026_08_15_110000: va en `users` y no en
 * `user_configurations` porque esa tabla resuelve con el DUEÑO, y guardar ahí le pisaría
 * la preferencia del dueño a cada empleado. `GET api/user` devuelve la fila de la PERSONA
 * y `User` tiene guarded vacío sin hidden para estas columnas, así que el valor viaja solo
 * en el arranque, sin requests extra.
 *
 * Rango: la SPA clampa 720..1600 y el endpoint valida el MISMO rango. Si divergieran, el
 * usuario podría arrastrar hasta un ancho que la SPA acepta y el PUT rechaza con 422 — y
 * como savePreferences() se traga el error en un console.log, el ancho se perdería en
 * silencio recién al recargar.
 *
 * Nullable a propósito: null significa "nunca lo tocó" y la SPA cae al default de 984px.
 * Sin foreign keys, con guard por columna para que sea segura de re-ejecutar.
 */
class AddChatIaPanelWidthToUsersTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'chat_ia_panel_width')) {
                /* Ancho del panel entero del chat, en px (la SPA y el endpoint clampan 720..1600) */
                $table->unsignedInteger('chat_ia_panel_width')->nullable();
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'chat_ia_panel_width')) {
                $table->dropColumn('chat_ia_panel_width');
            }
        });
    }
}
