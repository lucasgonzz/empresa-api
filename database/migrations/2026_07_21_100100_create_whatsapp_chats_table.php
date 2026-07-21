<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `whatsapp_chats`: un chat por teléfono por empresa dentro del módulo de WhatsApp
 * (grupo 137, Prompt 01). `client_id` es NULLABLE porque puede haber conversaciones de
 * gente que todavía no está cargada como cliente en el ERP. `last_inbound_at` es la
 * única fuente de verdad de la ventana de 24 h de Meta (se consulta en los Prompts 02,
 * 04 y 05 vía `WhatsappChat::is_within_service_window()`).
 *
 * Sin foreign keys por convención del proyecto (ver CLAUDE.md): la relación lógica con
 * `users` y `clients` se resuelve en Eloquent, no en el motor. Los índices son manuales.
 */
class CreateWhatsappChatsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('whatsapp_chats')) {
            Schema::create('whatsapp_chats', function (Blueprint $table) {
                $table->id();

                // Dueño/empresa a la que pertenece el chat.
                $table->unsignedBigInteger('user_id');
                $table->index('user_id', 'wch_user_idx');

                // Cliente del negocio vinculado al chat. Nullable: puede no estar cargado todavía.
                $table->unsignedBigInteger('client_id')->nullable();
                $table->index('client_id', 'wch_client_idx');

                // Teléfono normalizado (solo dígitos). Longitud acotada por la regla de índices en string.
                $table->string('phone', 30);

                // Nombre que reporta WhatsApp o el que cargue el usuario manualmente.
                $table->string('display_name', 120)->nullable();

                // Respuesta automática de IA prendida/apagada para este chat en particular.
                $table->boolean('ai_enabled')->default(true);

                // Último mensaje (en cualquier dirección) del chat, para ordenar el listado.
                $table->timestamp('last_message_at')->nullable();

                // Último mensaje DEL cliente: define la ventana de 24 h de Meta para poder responder libremente.
                $table->timestamp('last_inbound_at')->nullable();

                // Cantidad de mensajes entrantes sin leer por el operador.
                $table->integer('unread_count')->default(0);

                $table->timestamps();

                // Un chat por teléfono por empresa. Índice compuesto simple (no `unique` con múltiples
                // columnas string largas), ya que `phone` está acotado a 30 caracteres.
                $table->unique(['user_id', 'phone'], 'wch_user_phone_uq');
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
        Schema::dropIfExists('whatsapp_chats');
    }
}
