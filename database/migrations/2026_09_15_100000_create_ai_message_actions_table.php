<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargas que el asistente de IA PROPONE y la persona confirma (misión asistente-ia-acciones,
 * 15/9/2026).
 *
 * Una fila es una "tarjeta" del chat: el asistente arma un gasto, un pago, una tarea nueva, cambios
 * en una tarea o marcar una tarea como hecha, y la persona la confirma o la cancela desde la SPA.
 * Nada se escribe en el sistema hasta ese clic: `datos` guarda el payload interno con el que se va a
 * ejecutar la carga (nunca viaja a la SPA) y `presentacion` lo que la tarjeta le muestra a la persona.
 *
 * `clave` identifica "la misma carga" dentro de una conversación (por ejemplo `gasto:<subcategoría>`)
 * para que una corrección reemplace a la tarjeta anterior en vez de dejar dos confirmables.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateAiMessageActionsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_message_actions')) {
            return;
        }

        Schema::create('ai_message_actions', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* Conversación a la que pertenece la tarjeta (ai_conversations.id) */
            $table->unsignedBigInteger('ai_conversation_id');

            /* Mensaje del assistant que la propuso (ai_messages.id) */
            $table->unsignedBigInteger('ai_message_id');

            /* Dueño de la cuenta (users.id con owner_id null) */
            $table->unsignedBigInteger('user_id');

            /* Persona que charla con el asistente y la única que puede confirmarla */
            $table->unsignedBigInteger('auth_user_id');

            /* gasto | pago | tarea_nueva | tarea_editar | tarea_completar */
            $table->string('tipo', 30);

            /* Identidad de la carga para el reemplazo por corrección (ver AccionesIaHelper) */
            $table->string('clave', 100);

            /* propuesta | confirmada | cancelada | reemplazada | vencida | descartada */
            $table->string('estado', 20)->default('propuesta');

            /* Payload interno de ejecución, en JSON. No viaja a la SPA */
            $table->longText('datos');

            /* Título, renglones y aviso de la tarjeta, en JSON */
            $table->longText('presentacion');

            /* Texto y ruta del resultado cuando se confirmó, en JSON */
            $table->longText('resultado')->nullable();

            /* Último error de negocio al intentar confirmar (texto para la persona) */
            $table->text('error_mensaje')->nullable();

            /* pendings.updated_at al armar la tarjeta de cambios en una tarea */
            $table->timestamp('referencia_updated_at')->nullable();

            /* Cuándo dejó de estar propuesta (confirmada, cancelada, reemplazada, vencida o descartada) */
            $table->timestamp('resuelta_at')->nullable();

            $table->timestamps();

            /* Las tarjetas de un mensaje, para pintarlas debajo de su texto */
            $table->index(['ai_message_id'], 'aima_message_idx');

            /* Las propuestas vivas de una conversación, para el reemplazo por clave */
            $table->index(['ai_conversation_id', 'estado'], 'aima_conv_estado_idx');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_message_actions');
    }
}
