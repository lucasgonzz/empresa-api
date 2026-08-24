<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_bot_configs` los dos tiempos de espera del agente de WhatsApp
 * (misión whatsapp-agente):
 *
 *  - `ai_reply_delay_seconds`: cuánto espera el agente después de un mensaje entrante
 *    antes de generar la respuesta. Sirve para agrupar los mensajes que el cliente
 *    manda seguidos y contestarlos de una sola vez, en vez de disparar una respuesta
 *    por cada uno.
 *  - `ai_confirm_delay_seconds`: cuánto espera el mensaje ya generado a que una persona
 *    lo confirme antes de enviarse solo.
 *
 * 🔴 Los dos defaultean a 0 A PROPÓSITO, y eso no es una decisión estética: en 0 el
 * comportamiento es EXACTAMENTE el de antes de esta misión (el agente responde al
 * instante y el mensaje sale sin pasar por ninguna confirmación). O sea que correr esta
 * migración sola, sin que nadie configure nada, no le cambia el comportamiento a ningún
 * negocio que ya tenga el bot andando. No cambiar estos defaults.
 *
 * Guards idempotentes con `Schema::hasColumn` para poder re-correrla sin romper nada.
 */
class AddTimingColumnsToWhatsappBotConfigsTable extends Migration
{
    /**
     * Agrega las columnas nuevas si todavía no existen.
     *
     * @return void
     */
    public function up()
    {
        // Segundos de espera antes de generar la respuesta. 0 = responde al instante (comportamiento previo).
        if (! Schema::hasColumn('whatsapp_bot_configs', 'ai_reply_delay_seconds')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->unsignedInteger('ai_reply_delay_seconds')->default(0);
            });
        }

        // Segundos que el mensaje generado espera confirmación humana. 0 = se envía solo (comportamiento previo).
        if (! Schema::hasColumn('whatsapp_bot_configs', 'ai_confirm_delay_seconds')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->unsignedInteger('ai_confirm_delay_seconds')->default(0);
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
        if (Schema::hasColumn('whatsapp_bot_configs', 'ai_reply_delay_seconds')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('ai_reply_delay_seconds');
            });
        }

        if (Schema::hasColumn('whatsapp_bot_configs', 'ai_confirm_delay_seconds')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('ai_confirm_delay_seconds');
            });
        }
    }
}
