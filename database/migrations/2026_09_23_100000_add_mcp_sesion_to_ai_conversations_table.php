<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sesión MCP de una conversación del asistente (misión asistente-mcp, 22/9/2026).
 *
 * El servidor MCP de este API (POST api/mcp) le devuelve a cada cliente externo —Claude Desktop,
 * Claude Code, la API de Anthropic, cualquier cliente MCP— un `Mcp-Session-Id` al inicializar, y
 * el cliente lo repite en cada request. De este lado, esa sesión ES una AiConversation con origen
 * 'mcp': las tools de carga que pida el cliente dejan sus mensajes y sus tarjetas colgadas de ahí,
 * y el dueño las ve (y las puede confirmar) desde el panel del chat como cualquier otra.
 *
 * `mcp_sesion` guarda ese id: `bin2hex(random_bytes(20))`, o sea 40 caracteres (el largo 64 deja
 * margen). Null cuando el cliente cerró la sesión (DELETE api/mcp) o cuando la conversación la
 * abrió un cliente que no manda sesión. Se busca por él en cada request, por eso el índice.
 *
 * Sin foreign keys, como el resto del schema. Nullable y sin default: nada cambia para nadie que
 * no use el MCP (§4 del contrato: la tienda comparte la base y no lee esta tabla).
 */
class AddMcpSesionToAiConversationsTable extends Migration
{
    /**
     * Agrega la columna con su guard hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_conversations')) {
            return;
        }

        if (!Schema::hasColumn('ai_conversations', 'mcp_sesion')) {

            Schema::table('ai_conversations', function (Blueprint $table) {

                /* Mcp-Session-Id de la sesión MCP que abrió esta conversación; null si se cerró o si no la abrió un cliente MCP */
                $table->string('mcp_sesion', 64)->nullable()->after('contexto');

                /* Cada request MCP con sesión busca su conversación por acá */
                $table->index(['mcp_sesion'], 'ai_conv_mcp_sesion_idx');
            });
        }
    }

    /**
     * Quita la columna (el índice se va con ella, pero se saca antes por prolijidad en MySQL).
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_conversations') || !Schema::hasColumn('ai_conversations', 'mcp_sesion')) {
            return;
        }

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex('ai_conv_mcp_sesion_idx');
            $table->dropColumn('mcp_sesion');
        });
    }
}
