<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mueve a la base los dos tokens de debounce del agente de WhatsApp (misión
 * whatsapp-agente), que hasta acá vivían en caché:
 *
 *  - `whatsapp_chats.ai_schedule_token`: token por chat, el que agrupa los mensajes
 *    seguidos del cliente (`WhatsappAgentScheduler`).
 *  - `whatsapp_chat_messages.ai_auto_send_token`: token por mensaje, el que controla el
 *    auto-envío después de la ventana de confirmación (`WhatsappAiAutoSendScheduler`).
 *
 * 🔴 POR QUÉ EN LA BASE Y NO EN CACHÉ, que es como estaba y como está en admin-api:
 * el patrón se copió de `admin-api`, donde funciona porque ese proyecto corre con
 * `CACHE_DRIVER=file`, o sea una caché que persiste entre procesos. `empresa-api` corre
 * con `CACHE_DRIVER=array`: la caché es un array en memoria del proceso y muere con él.
 * Y estos tokens los escribe UN proceso (el request del webhook de Kapso) y los tiene que
 * leer OTRO (el worker de `queue:work`, porque `QUEUE_CONNECTION=database`). Con `array`,
 * el worker levantaba con la caché vacía, `Cache::get()` devolvía null, el token nunca
 * coincidía y el job se descartaba en silencio: con cualquier demora mayor a 0 la
 * respuesta del agente NO se generaba ni se enviaba jamás, y no quedaba rastro de error.
 * No alcanzaba con cambiar el driver: `.env` no está versionado y no controlamos el de
 * cada cliente. El token tiene que vivir donde cualquier proceso lo pueda leer, y eso es
 * la base.
 *
 * Los dos defaultean a 0 y las columnas son `unsignedInteger` porque son contadores que
 * solo suben. 0 = "nunca se programó nada", que es lo que son todas las filas que ya
 * existen, así que esta migración no le cambia el significado a ninguna.
 *
 * Guards idempotentes con `Schema::hasColumn` para poder re-correrla sin romper nada.
 */
class AddSchedulerTokensToWhatsappTables extends Migration
{
    /**
     * Agrega las columnas nuevas si todavía no existen.
     *
     * @return void
     */
    public function up()
    {
        // Token de debounce de la respuesta del agente, por chat. Lo incrementa cada mensaje entrante.
        if (! Schema::hasColumn('whatsapp_chats', 'ai_schedule_token')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->unsignedInteger('ai_schedule_token')->default(0);
            });
        }

        // Token de auto-envío, por mensaje. Se incrementa al programar y al cancelar (confirmado/descartado).
        if (! Schema::hasColumn('whatsapp_chat_messages', 'ai_auto_send_token')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->unsignedInteger('ai_auto_send_token')->default(0);
            });
        }
    }

    /**
     * Revierte las columnas agregadas si existen.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('whatsapp_chats', 'ai_schedule_token')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->dropColumn('ai_schedule_token');
            });
        }

        if (Schema::hasColumn('whatsapp_chat_messages', 'ai_auto_send_token')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('ai_auto_send_token');
            });
        }
    }
}
