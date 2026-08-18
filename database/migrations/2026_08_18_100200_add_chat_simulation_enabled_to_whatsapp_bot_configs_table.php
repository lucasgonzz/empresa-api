<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_bot_configs` la columna `chat_simulation_enabled`: el toggle que
 * habilita el botón de simular un mensaje del cliente dentro de la conversación (y en la
 * bandeja), misión personalizacion-agente-whatsapp, addendum Parte B. Default `false`: si
 * naciera en `true`, a los negocios existentes les aparecería de golpe un botón nuevo que no
 * pidieron, mismo criterio que `ai_vision_enabled`. Usa guard idempotente
 * (`Schema::hasColumn`) para poder correr la migración de forma segura aunque ya se haya
 * aplicado parcialmente en algún entorno.
 */
class AddChatSimulationEnabledToWhatsappBotConfigsTable extends Migration
{
    /**
     * Agrega la columna nueva si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        // Si el dueño habilitó el botón de simular mensajes del cliente dentro de la conversación
        // (y en la bandeja). Lo consume el guard de `simulate_inbound()`, no solo la UI.
        if (! Schema::hasColumn('whatsapp_bot_configs', 'chat_simulation_enabled')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->boolean('chat_simulation_enabled')->default(false);
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
        if (Schema::hasColumn('whatsapp_bot_configs', 'chat_simulation_enabled')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('chat_simulation_enabled');
            });
        }
    }
}
