<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué mandó el dueño cada mensaje: escribiendo, por audio o una foto (misión
 * asistente-por-whatsapp, 16/9/2026).
 *
 * El `tipo` ya viajaba en el contrato de `POST admin-sync/asistente/mensajes` y se validaba, pero
 * después se tiraba: no quedaba en ninguna columna ni llegaba al loop. Eso dejaba un agujero
 * concreto: un audio que Kapso no pudo transcribir entra como el literal
 * "[Audio sin transcripción]" y quedaba guardado COMO SI EL DUEÑO LO HUBIERA ESCRITO, en el
 * historial, para siempre. Lo único que lo manejaba era un renglón del prompt, o sea que dependía
 * de que el modelo reconociera ese texto y se acordara de contestar bien.
 *
 * Con la columna, el caso se resuelve de forma determinista antes de gastar una llamada a la IA
 * (AdminSync\AsistenteController::respuesta_de_audio_sin_transcribir()).
 *
 * Default 'texto': todo mensaje que ya existe y todo mensaje del panel del chat —donde no hay ni
 * audios ni fotos— queda exactamente como está.
 */
class AddTipoToAiMessagesTable extends Migration
{
    /**
     * Agrega la columna, con guard hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_messages') || Schema::hasColumn('ai_messages', 'tipo')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {

            /* 'texto' | 'audio' | 'imagen' — con qué lo mandó la persona */
            $table->string('tipo', 20)->default('texto')->after('whatsapp_message_id');
        });
    }

    /**
     * Quita la columna.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_messages') || !Schema::hasColumn('ai_messages', 'tipo')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
}
