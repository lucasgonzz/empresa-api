<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `whatsapp_chat_messages`: cada mensaje individual (entrante o saliente) de un
 * `whatsapp_chat`, con su origen (`source`) y estado de entrega para poder matchear los
 * webhooks de estado que manda Kapso/Meta (grupo 137, Prompt 01).
 *
 * Sin foreign keys por convención del proyecto: la relación con `whatsapp_chats` y
 * `users` (empleado que mandó el mensaje) se resuelve en Eloquent.
 */
class CreateWhatsappChatMessagesTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('whatsapp_chat_messages')) {
            Schema::create('whatsapp_chat_messages', function (Blueprint $table) {
                $table->id();

                // Chat al que pertenece el mensaje.
                $table->unsignedBigInteger('whatsapp_chat_id');

                // Dirección del mensaje: 'in' (del cliente) | 'out' (de la empresa/agente).
                $table->string('direction', 10);

                // Origen del mensaje: 'cliente' | 'manual' | 'ia' | 'plantilla' | 'sistema' (comprobantes automáticos).
                $table->string('source', 20);

                // Empleado que envió el mensaje manualmente (aplica a source 'manual'/'plantilla'). Nullable.
                $table->unsignedBigInteger('sent_by_user_id')->nullable();

                // Texto del mensaje.
                $table->text('body')->nullable();

                // Tipo de adjunto si el mensaje trae media: 'image' | 'document' | 'audio' | 'video'.
                $table->string('media_type', 20)->nullable();

                // URL del adjunto (propia o de Kapso/Meta).
                $table->text('media_url')->nullable();

                // Id del mensaje en Meta/Kapso, para matchear los webhooks de estado de entrega.
                $table->string('wa_message_id', 80)->nullable();
                $table->index('wa_message_id', 'wcm_wa_message_id_idx');

                // Estado de entrega (solo aplica a mensajes 'out'): pendiente | enviado | entregado | leido | fallido.
                $table->string('delivery_status', 20)->default('pendiente');

                // Detalle del error si el envío falló.
                $table->text('send_error')->nullable();

                // Nombre de la plantilla de Meta usada, si el mensaje salió como plantilla.
                $table->string('template_meta_name', 120)->nullable();

                $table->timestamps();

                // Índice compuesto para listar los mensajes de un chat ordenados cronológicamente.
                $table->index(['whatsapp_chat_id', 'created_at'], 'wcm_chat_created_idx');
            });
        }
    }

    /**
     * Elimina la tabla si existe (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('whatsapp_chat_messages');
    }
}
