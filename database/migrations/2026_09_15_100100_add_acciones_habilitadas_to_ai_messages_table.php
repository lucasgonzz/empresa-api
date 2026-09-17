<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca por mensaje si el asistente puede PROPONER cargas al generar esa respuesta (misión
 * asistente-ia-acciones, 15/9/2026).
 *
 * Va en el mensaje del assistant y no en la conversación a propósito: la SPA nueva manda
 * `acciones: true` en cada POST, y una pestaña vieja (que no sabe pintar tarjetas) sigue mandando
 * mensajes sin el flag en la MISMA conversación. Con el flag por mensaje, esa respuesta sale de solo
 * lectura como siempre y nunca deja una tarjeta que nadie puede ver ni confirmar.
 *
 * Default false: todo mensaje que ya existe y todo POST sin el flag queda exactamente como hoy.
 */
class AddAccionesHabilitadasToAiMessagesTable extends Migration
{
    /**
     * Agrega la columna, con guard hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_messages') || Schema::hasColumn('ai_messages', 'acciones_habilitadas')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {

            /* true si la respuesta se generó con las herramientas de carga habilitadas */
            $table->boolean('acciones_habilitadas')->default(false)->after('error_mensaje');
        });
    }

    /**
     * Quita la columna.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_messages') || !Schema::hasColumn('ai_messages', 'acciones_habilitadas')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('acciones_habilitadas');
        });
    }
}
