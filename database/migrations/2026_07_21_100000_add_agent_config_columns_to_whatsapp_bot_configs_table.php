<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_bot_configs` las columnas que necesita el agente de IA del módulo
 * de WhatsApp de empresa (grupo 137): personalidad configurable, si los chats nuevos
 * nacen con la respuesta automática prendida, y si se debe enviar el PDF de la venta
 * automáticamente. Usa guards idempotentes (`Schema::hasColumn`) para poder correr la
 * migración de forma segura aunque ya se haya aplicado parcialmente en algún entorno.
 */
class AddAgentConfigColumnsToWhatsappBotConfigsTable extends Migration
{
    /**
     * Agrega las columnas nuevas si todavía no existen.
     *
     * @return void
     */
    public function up()
    {
        // Personalidad del agente IA que define el dueño del negocio (la consume el Prompt 03: WhatsappBotAiService).
        if (! Schema::hasColumn('whatsapp_bot_configs', 'agent_personality')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->text('agent_personality')->nullable();
            });
        }

        // Si los chats nuevos nacen con la respuesta automática de IA prendida por defecto.
        if (! Schema::hasColumn('whatsapp_bot_configs', 'ai_enabled_default')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->boolean('ai_enabled_default')->default(true);
            });
        }

        // Si se debe enviar automáticamente el comprobante PDF de la venta por el agente (lo consume el Prompt 05).
        if (! Schema::hasColumn('whatsapp_bot_configs', 'auto_send_sale_pdf')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->boolean('auto_send_sale_pdf')->default(false);
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
        if (Schema::hasColumn('whatsapp_bot_configs', 'agent_personality')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('agent_personality');
            });
        }

        if (Schema::hasColumn('whatsapp_bot_configs', 'ai_enabled_default')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('ai_enabled_default');
            });
        }

        if (Schema::hasColumn('whatsapp_bot_configs', 'auto_send_sale_pdf')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('auto_send_sale_pdf');
            });
        }
    }
}
