<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tarjeta del asistente que mandó cada WhatsApp saliente (misión asistente-arreglos-segunda-prueba,
 * 19/10/2026, defecto N3).
 *
 * Un WhatsApp que Kapso aceptó puede volver rechazado por Meta minutos después (webhook
 * `whatsapp.message.failed`: número que no tiene WhatsApp, plantilla pausada, calidad del número). Hasta
 * hoy ese rechazo solo cambiaba `delivery_status` de la fila; con la tarjeta anotada acá,
 * `WhatsappChatHelper::handle_delivery_status_event()` la encuentra y registra el envío fallido para
 * avisarle al dueño que confirmó el envío (`ai_envios_fallidos`).
 *
 * La escribe SOLO el asistente al mandar (comprobante, recordatorio de cobro y promoción); el resto de
 * los mensajes la deja en null. Nullable, sin default y sin índice (se llega a la fila por su
 * `wa_message_id`, no por esto): agregar una columna al final de una tabla grande en MySQL 8 es
 * instantáneo y no la bloquea. Sin clave foránea: `tienda-api` comparte la base y no la usa.
 */
class AddAiMessageActionIdToWhatsappChatMessagesTable extends Migration
{
    /**
     * Agrega la columna si falta.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('whatsapp_chat_messages') && !Schema::hasColumn('whatsapp_chat_messages', 'ai_message_action_id')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('ai_message_action_id')->nullable();
            });
        }
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('whatsapp_chat_messages') && Schema::hasColumn('whatsapp_chat_messages', 'ai_message_action_id')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('ai_message_action_id');
            });
        }
    }
}
