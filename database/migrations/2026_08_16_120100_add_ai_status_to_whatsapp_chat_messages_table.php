<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_chat_messages` el eje de "confirmación humana" del agente de
 * WhatsApp (misión whatsapp-agente):
 *
 *  - `ai_status`: null (no aplica) | 'a_confirmar' | 'enviado'
 *  - `ai_auto_send_at`: cuándo se auto-envía si nadie lo confirma antes.
 *
 * 🔴 POR QUÉ UNA COLUMNA NUEVA Y NO UN VALOR MÁS EN `delivery_status`:
 * `delivery_status` responde a "¿qué dijo Meta de este mensaje?" y tiene un rankeo
 * anti-retroceso en `WhatsappChatHelper::$status_rank` (pendiente=0, enviado=1,
 * entregado=2, leido=3). Meter 'a_confirmar' ahí lo dejaría en rank 0 y el primer
 * evento de entrega que llegara lo pisaría; además mezcla dos ejes independientes
 * ("¿lo aprobó una persona?" contra "¿lo entregó Meta?") y la SPA, que pinta los
 * tildes según `delivery_status`, renderizaría un estado que no conoce.
 * Con columna aparte, NINGUNA fila existente cambia de significado: todas quedan con
 * `ai_status = null`, que es exactamente lo que son (mensajes que nunca pasaron por
 * este flujo). No mover esto a `delivery_status`.
 *
 * Guards idempotentes para poder re-correrla sin romper nada.
 */
class AddAiStatusToWhatsappChatMessagesTable extends Migration
{
    /**
     * Agrega las columnas y el índice si todavía no existen.
     *
     * @return void
     */
    public function up()
    {
        // Estado de confirmación del mensaje generado por la IA. null = no aplica (todo lo que ya existe).
        if (! Schema::hasColumn('whatsapp_chat_messages', 'ai_status')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->string('ai_status', 20)->nullable();
                $table->index('ai_status', 'wcm_ai_status_idx');
            });
        }

        // Momento en que el mensaje se envía solo si nadie lo confirmó. Lo consume el contador de la interfaz.
        if (! Schema::hasColumn('whatsapp_chat_messages', 'ai_auto_send_at')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->timestamp('ai_auto_send_at')->nullable();
            });
        }
    }

    /**
     * Revierte columnas e índice si existen.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('whatsapp_chat_messages', 'ai_status')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                // El índice se suelta ANTES que la columna: MySQL no deja dropear una columna indexada.
                $table->dropIndex('wcm_ai_status_idx');
                $table->dropColumn('ai_status');
            });
        }

        if (Schema::hasColumn('whatsapp_chat_messages', 'ai_auto_send_at')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('ai_auto_send_at');
            });
        }
    }
}
