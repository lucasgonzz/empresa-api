<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversaciones del asistente de IA del negocio (misión chat-ia-y-modulo-ia).
 *
 * Cada conversación pertenece a una PERSONA (auth_user_id), no a la cuenta:
 * las respuestas pueden traer saldos de clientes y un empleado no puede leer
 * las conversaciones del dueño ni al revés. user_id (el dueño) viaja aparte
 * porque las tools de consulta filtran datos por cuenta, y porque el job de
 * respuesta corre sin sesión y necesita resolver el owner desde la fila.
 *
 * `origen` distingue las conversaciones que abrió el usuario ('usuario') de
 * las creadas automáticamente por una sugerencia ('sugerencia_stock', y a
 * futuro compras/ofertas); `referencia_id` apunta al registro de origen
 * (p. ej. stock_suggestions.id) y `contexto` guarda el bloque de datos ya
 * calculados que se inyecta como system extra en cada pedido a la IA.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateAiConversationsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_conversations')) {
            return;
        }

        Schema::create('ai_conversations', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* ID del dueño de la cuenta (users con owner_id null) */
            $table->unsignedBigInteger('user_id');

            /* ID de la persona dueña de la conversación (puede ser un empleado) */
            $table->unsignedBigInteger('auth_user_id');

            /* Título inferido del primer mensaje; null mientras se infiere (la SPA muestra "Nueva conversación") */
            $table->string('titulo', 150)->nullable();

            /* 'usuario' | 'sugerencia_stock' | ... (ver docblock del modelo) */
            $table->string('origen', 30)->default('usuario');

            /* ID del registro que originó la conversación (p. ej. stock_suggestions.id); null si la abrió el usuario */
            $table->unsignedBigInteger('referencia_id')->nullable();

            /* Bloque de datos ya calculados que se inyecta como contexto de fondo en cada pedido */
            $table->longText('contexto')->nullable();

            /* Última actividad, para ordenar la sidebar */
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            /* La sidebar de una persona: sus conversaciones por actividad */
            $table->index(['auth_user_id', 'last_message_at'], 'ai_conversations_auth_user_last_message_index');

            /* Barrido por cuenta */
            $table->index(['user_id'], 'ai_conversations_user_index');

            /* Guard de idempotencia contra regenerar_resumen (una conversación por sugerencia) */
            $table->index(['origen', 'referencia_id'], 'ai_conversations_origen_referencia_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_conversations');
    }
}
