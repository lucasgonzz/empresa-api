<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de simulación del módulo de WhatsApp (misión whatsapp-agente).
 *
 * El endpoint `whatsapp-bot/simulate-inbound` inyecta un mensaje entrante como si lo
 * hubiera escrito el cliente, para que el dueño pueda probar el agente de punta a punta.
 * Hasta esta migración esa fila quedaba EXACTAMENTE igual que la de un mensaje real
 * (`direction = 'in'`, `source = 'cliente'`), y eso traía dos problemas serios:
 *
 *  1. El registro comercial quedaba falseado: un empleado que abriera el chat mañana leía
 *     un mensaje que el cliente nunca mandó y no tenía forma de distinguirlo. La única
 *     traza era una línea de log.
 *  2. `store_inbound_message()` setea `last_inbound_at = now()`, y esa columna es la ÚNICA
 *     fuente de verdad de la ventana de 24 h de Meta
 *     (`WhatsappChat::is_within_service_window()`). O sea que simulando un mensaje de un
 *     número que nunca escribió, el sistema quedaba convencido de que había una
 *     conversación abierta, el agente generaba la respuesta y se la mandaba DE VERDAD por
 *     Kapso. Meta rechaza esos mensajes de su lado, pero cada intento le baja la
 *     calificación al número de WhatsApp Business del negocio, y un número con mala
 *     calificación queda limitado o dado de baja. Es el activo que ComercioCity le
 *     gestiona al cliente.
 *
 * Las dos columnas que agrega esta migración son las que cierran ese agujero:
 *
 *  - `whatsapp_chat_messages.is_simulated`: marca por mensaje, entrante y saliente. Es lo
 *    que vuelve distinguible en la base un mensaje simulado de uno real, para siempre.
 *  - `whatsapp_chats.last_inbound_simulated`: marca por chat, denormalizada del último
 *    entrante. Es el freno: mientras esté prendida, `WhatsappBotSendService` no manda
 *    nada por Kapso que dependa de la ventana de 24 h (texto libre y documento por link).
 *    Se apaga sola apenas entra un mensaje real de ese teléfono, porque los dos caminos
 *    —webhook y simulación— pasan por el mismo `store_inbound_message()` y ahí se escribe
 *    el valor que corresponda. No hace falta ningún proceso de limpieza.
 *
 * Las dos defaultean a 0 (falso), que es lo que son todas las filas que ya existen: nada
 * de lo persistido hasta hoy fue simulado. La migración no le cambia el significado a
 * ninguna fila vieja.
 *
 * Guards idempotentes con `Schema::hasColumn` para poder re-correrla sin romper nada,
 * mismo criterio que la 2026_08_16_120300.
 */
class AddSimulationFlagsToWhatsappTables extends Migration
{
    /**
     * Agrega las columnas nuevas si todavía no existen.
     *
     * @return void
     */
    public function up()
    {
        // Marca por mensaje: este mensaje nació de una simulación, no de WhatsApp real.
        if (! Schema::hasColumn('whatsapp_chat_messages', 'is_simulated')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->boolean('is_simulated')->default(0);
            });
        }

        // Marca por chat: el último entrante fue simulado, así que la ventana de 24 h que
        // muestra este chat está forzada y no se puede confiar en ella para enviar.
        if (! Schema::hasColumn('whatsapp_chats', 'last_inbound_simulated')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->boolean('last_inbound_simulated')->default(0);
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
        if (Schema::hasColumn('whatsapp_chat_messages', 'is_simulated')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('is_simulated');
            });
        }

        if (Schema::hasColumn('whatsapp_chats', 'last_inbound_simulated')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->dropColumn('last_inbound_simulated');
            });
        }
    }
}
