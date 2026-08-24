<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes de las conversaciones del asistente de IA (misión chat-ia-y-modulo-ia).
 *
 * El mensaje del assistant nace 'pendiente' y con contenido null (el POST guarda
 * y contesta rápido; la respuesta la genera un job en cola) y termina 'listo'
 * con el texto, o 'error' con un contenido amigable visible y el detalle
 * técnico en error_mensaje. Nunca queda un globo vacío en la conversación.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateAiMessagesTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_messages')) {
            return;
        }

        Schema::create('ai_messages', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* Conversación a la que pertenece (ai_conversations.id) */
            $table->unsignedBigInteger('ai_conversation_id');

            /* 'user' | 'assistant' */
            $table->string('rol', 20);

            /* Texto del mensaje; null mientras el assistant está 'pendiente' */
            $table->longText('contenido')->nullable();

            /* 'listo' | 'pendiente' | 'error' (ver docblock del modelo) */
            $table->string('estado', 20)->default('listo');

            /* Detalle técnico de la falla cuando estado = 'error' (el usuario ve `contenido`, no esto) */
            $table->text('error_mensaje')->nullable();

            $table->timestamps();

            /* El hilo de una conversación en orden de llegada (id crece con el tiempo) */
            $table->index(['ai_conversation_id', 'id'], 'ai_messages_conversation_id_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_messages');
    }
}
