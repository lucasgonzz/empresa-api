<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de consumo de tokens de cada llamada a la API de Anthropic
 * (misión chat-ia-y-modulo-ia). Greenfield total: hasta esta misión ningún
 * servicio leía el `usage` de la respuesta.
 *
 * Sin UI en esta misión: la tabla existe para que Lucas decida DESPUÉS con
 * números reales (hoy la IA va incluida en la suscripción). Se guardan las
 * dos columnas de caché porque el system prompt del chat viaja con
 * cache_control y sin ellas el costo real quedaría mal medido.
 *
 * auth_user_id es null cuando la llamada la disparó un proceso automático
 * (p. ej. el resumen de una sugerencia generada por comando, sin persona).
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateAiTokenUsagesTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_token_usages')) {
            return;
        }

        Schema::create('ai_token_usages', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* ID del dueño de la cuenta que paga el consumo */
            $table->unsignedBigInteger('user_id');

            /* Persona que disparó la llamada; null si fue un job automático */
            $table->unsignedBigInteger('auth_user_id')->nullable();

            /* 'chat_mensaje' | 'chat_titulo' | 'resumen_sugerencia_stock' | ... */
            $table->string('proceso', 40);

            /* Modelo usado, tal como lo devolvió la API (p. ej. claude-sonnet-4-5) */
            $table->string('modelo', 80);

            /* Contadores del bloque usage de la respuesta */
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_creation_input_tokens')->default(0);
            $table->unsignedInteger('cache_read_input_tokens')->default(0);

            /* Conversación del chat asociada, si aplica */
            $table->unsignedBigInteger('ai_conversation_id')->nullable();

            /* ID del registro de origen del proceso (p. ej. stock_suggestions.id), si aplica */
            $table->unsignedBigInteger('referencia_id')->nullable();

            $table->timestamps();

            /* Consumo de una cuenta en un rango de fechas (la consulta que va a decidir el cobro) */
            $table->index(['user_id', 'created_at'], 'ai_token_usages_user_created_index');

            /* Desglose por tipo de proceso */
            $table->index(['proceso'], 'ai_token_usages_proceso_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_token_usages');
    }
}
