<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_bot_configs` el OBJETIVO DE LA CONVERSACIÓN del agente de WhatsApp (misión
 * objetivo-agente-whatsapp, 16/10/2026): qué tiene que buscar el agente además de asesorar —que el
 * cliente pase por el local, que compre en la tienda online, que deje el pedido por el chat, u otro
 * que escribe el dueño—.
 *
 * - `agent_objective`: la opción elegida (`visit_branch`, `buy_online`, `order_by_whatsapp`, `custom`;
 *   ver `WhatsappBotConfig::OBJETIVOS`). NULL = sin objetivo.
 * - `agent_objective_detail`: indicaciones libres del dueño para ese objetivo (en `custom`, el objetivo
 *   mismo).
 *
 * 🔴 LAS DOS NULLABLE Y SIN DEFAULT, A PROPÓSITO: NULL es "sin objetivo" y es como quedan todos los
 * negocios que ya existen. Con NULL, `WhatsappBotAiService::build_system_prompt()` no agrega la capa del
 * objetivo y el system prompt queda idéntico al de antes de esta columna.
 *
 * El largo de `agent_objective` es corto (40) porque guarda una clave fija, no texto del dueño.
 *
 * Guard idempotente con `Schema::hasColumn`, mismo criterio que
 * `2026_10_15_100000_add_envio_simulado_to_whatsapp_bot_configs_table`.
 */
class AddAgentObjectiveToWhatsappBotConfigsTable extends Migration
{
    /**
     * Agrega las dos columnas nuevas si todavía no existen.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('whatsapp_bot_configs', 'agent_objective')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->string('agent_objective', 40)->nullable();
            });
        }

        if (! Schema::hasColumn('whatsapp_bot_configs', 'agent_objective_detail')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->text('agent_objective_detail')->nullable();
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
        if (Schema::hasColumn('whatsapp_bot_configs', 'agent_objective_detail')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('agent_objective_detail');
            });
        }

        if (Schema::hasColumn('whatsapp_bot_configs', 'agent_objective')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('agent_objective');
            });
        }
    }
}
