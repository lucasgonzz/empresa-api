<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada vez que una tarea del asistente se despierta (o se omite) queda una fila acá (misión
 * agente-tareas-programadas, 21/11/2026): el turno que corrió, su resultado y si se entregó por WhatsApp.
 *
 * El unique (tarea_id, ocurrencia_at) es la garantía de fondo de que una ocurrencia corre UNA sola vez, aunque
 * dos procesos (dos frentes rotados sobre la misma base, un cron solapado) lleguen juntos. Es un entero más un
 * datetime: no es un unique compuesto de strings.
 *
 * Aditiva, sin claves foráneas y con guarda por tabla.
 */
class CreateAiTareasDelAsistenteEjecucionesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('ai_tareas_del_asistente_ejecuciones')) {
            return;
        }

        Schema::create('ai_tareas_del_asistente_ejecuciones', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('tarea_id');

            $table->unsignedInteger('owner_id');

            /* El momento PROGRAMADO (no el de la corrida). */
            $table->dateTime('ocurrencia_at');

            /* en_curso | lista | entregada | no_entregada | fallida | omitida */
            $table->string('estado', 20)->default('en_curso');

            /* vencida | tope_diario | tope_del_plan | sin_extension | error | colgada */
            $table->string('motivo', 40)->nullable();

            $table->unsignedInteger('ai_conversation_id')->nullable();

            /* El `assistant` del turno. */
            $table->unsignedInteger('ai_message_id')->nullable();

            /* El resultado del turno (≤ 3500). */
            $table->text('texto')->nullable();

            $table->dateTime('entregada_at')->nullable();

            $table->string('whatsapp_message_id', 128)->nullable();

            /*
             * Enmienda 1 (22/11/2026): cómo salió el resultado cuando el admin lo selló. `texto` = el texto completo
             * (ventana de 24 h abierta); `plantilla` = sólo una plantilla, y el resultado se le entrega al dueño en
             * su próximo turno de WhatsApp. Lo manda el admin nuevo (`via`) o lo deduce la empresa.
             */
            $table->string('entregada_via', 20)->nullable();

            /* Cuándo se le puso el resultado delante de la respuesta de un turno del dueño (una sola vez). */
            $table->dateTime('mostrada_at')->nullable();

            $table->timestamps();

            $table->index('owner_id', 'atdae_owner_idx');
            $table->unique(['tarea_id', 'ocurrencia_at'], 'atdae_tarea_ocur_uq');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_tareas_del_asistente_ejecuciones');
    }
}
