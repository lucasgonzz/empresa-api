<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la SEÑAL DE SEGUIMIENTO del agente de WhatsApp (misión
 * senal-pedidos-y-consultas-del-agente, 16/10/2026): cuando el agente arma un pedido o promete
 * «lo consulto y te confirmo», el chat queda marcado hasta que una persona lo atienda. Sin esto el
 * pedido o la promesa vivían solo en el texto de la respuesta: el chat dejaba de estar en rojo y no
 * quedaba ningún aviso.
 *
 * En `whatsapp_chat_messages` (lo que el modelo marcó en ESE mensaje; viaja con el borrador
 * `a_confirmar` hasta que sale):
 *  - `ai_follow_up`: 'pedido' | 'consulta' | NULL.
 *  - `ai_follow_up_note`: el detalle que escribió el modelo, acotado (qué productos, qué averiguar).
 *
 * En `whatsapp_chats` (el estado del chat; se escribe CUANDO EL MENSAJE EFECTIVAMENTE SALE, no cuando
 * se genera, y lo apaga una persona al escribir o con el botón «Ya lo atendí»):
 *  - `follow_up`: 'pedido' | 'consulta' | NULL.
 *  - `follow_up_note`: el detalle del último mensaje que lo marcó.
 *  - `follow_up_at`: desde cuándo espera (no se pisa mientras siga el mismo tipo).
 *
 * 🔴 TODAS NULLABLE Y SIN DEFAULT, A PROPÓSITO: NULL es «nada para atender» y es como quedan todos los
 * chats y mensajes que ya existen. Con NULL el módulo se ve y se comporta exactamente como antes.
 *
 * Es un eje APARTE de `ai_status` (¿lo aprobó una persona?) y de `delivery_status` (¿lo entregó Meta?):
 * mezclarlo en cualquiera de los dos rompería la máquina de estados de `WhatsappChatHelper`.
 *
 * Sin foreign keys (convención del proyecto). El índice de `follow_up` tiene nombre corto
 * (`wc_follow_up_idx`) y sirve a la cuenta del número del menú (`GET whatsapp-chats/resumen`).
 *
 * Guards idempotentes con `Schema::hasColumn`, mismo criterio que
 * `2026_10_16_120000_add_agent_objective_to_whatsapp_bot_configs_table`.
 */
class AddFollowUpToWhatsappTables extends Migration
{
    /**
     * Agrega las columnas e índice que todavía no existan.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('whatsapp_chat_messages', 'ai_follow_up')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->string('ai_follow_up', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('whatsapp_chat_messages', 'ai_follow_up_note')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->string('ai_follow_up_note', 255)->nullable();
            });
        }

        if (! Schema::hasColumn('whatsapp_chats', 'follow_up')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->string('follow_up', 20)->nullable();
                $table->index('follow_up', 'wc_follow_up_idx');
            });
        }

        if (! Schema::hasColumn('whatsapp_chats', 'follow_up_note')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->string('follow_up_note', 255)->nullable();
            });
        }

        if (! Schema::hasColumn('whatsapp_chats', 'follow_up_at')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->timestamp('follow_up_at')->nullable();
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
        if (Schema::hasColumn('whatsapp_chats', 'follow_up_at')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->dropColumn('follow_up_at');
            });
        }

        if (Schema::hasColumn('whatsapp_chats', 'follow_up_note')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->dropColumn('follow_up_note');
            });
        }

        if (Schema::hasColumn('whatsapp_chats', 'follow_up')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                // El índice se suelta ANTES que la columna: MySQL no deja dropear una columna indexada.
                $table->dropIndex('wc_follow_up_idx');
                $table->dropColumn('follow_up');
            });
        }

        if (Schema::hasColumn('whatsapp_chat_messages', 'ai_follow_up_note')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('ai_follow_up_note');
            });
        }

        if (Schema::hasColumn('whatsapp_chat_messages', 'ai_follow_up')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('ai_follow_up');
            });
        }
    }
}
