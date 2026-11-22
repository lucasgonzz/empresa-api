<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La valoración de cada respuesta del agente de WhatsApp que atiende a los clientes del comercio (misión
 * asistente-reacciones-wa, 22/11/2026).
 *
 * La escribe la reacción del cliente final a esa respuesta (👍 = me gusta, 👎 o una cara enojada = no me
 * gusta) que llega por el webhook de Kapso; no hay valoración desde ninguna pantalla, así que toda valoración
 * de esta tabla es de reacción. La lee el análisis diario para mejorar al agente.
 *
 * Todo nullable y sin foreign keys: un mensaje sin valorar queda en null, como todos los existentes. Con guard
 * para que sea segura de re-ejecutar.
 */
class AddValoracionToWhatsappChatMessagesTable extends Migration
{
    /**
     * Agrega las tres columnas y el índice por fecha de valoración.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_chat_messages', 'valoracion')) {
                /* me_gusta | no_me_gusta | null (sin valorar) */
                $table->string('valoracion', 15)->nullable();
            }
            if (!Schema::hasColumn('whatsapp_chat_messages', 'valoracion_comentario')) {
                /* «reacción <emoji> por WhatsApp» */
                $table->text('valoracion_comentario')->nullable();
            }
            if (!Schema::hasColumn('whatsapp_chat_messages', 'valorado_at')) {
                /* Cuándo reaccionó el cliente (el análisis diario lee lo posterior a su cursor). */
                $table->timestamp('valorado_at')->nullable();
                $table->index('valorado_at', 'wcm_valorado_at_idx');
            }
        });
    }

    /**
     * Elimina el índice y las columnas si existen.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_chat_messages', 'valorado_at')) {
                $table->dropIndex('wcm_valorado_at_idx');
            }
            foreach (['valoracion', 'valoracion_comentario', 'valorado_at'] as $columna) {
                if (Schema::hasColumn('whatsapp_chat_messages', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
