<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_bot_configs` la columna `agent_skills`: las habilidades del agente de
 * IA del módulo de WhatsApp de empresa (misión personalizacion-agente-whatsapp), texto libre
 * definido por el dueño que describe de qué sabe el agente (rubro, vocabulario, qué preguntar).
 * Usa guard idempotente (`Schema::hasColumn`) para poder correr la migración de forma segura
 * aunque ya se haya aplicado parcialmente en algún entorno.
 */
class AddAgentSkillsToWhatsappBotConfigsTable extends Migration
{
    /**
     * Agrega la columna nueva si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        // Habilidades del agente IA que define el dueño del negocio (las consume el prompt de
        // sistema en WhatsappBotAiService, como una capa más aparte de la personalidad).
        if (! Schema::hasColumn('whatsapp_bot_configs', 'agent_skills')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->text('agent_skills')->nullable();
            });
        }
    }

    /**
     * Revierte la columna agregada si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('whatsapp_bot_configs', 'agent_skills')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('agent_skills');
            });
        }
    }
}
