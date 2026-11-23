<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las tareas propias del asistente personal (misión agente-tareas-programadas, 21/11/2026): «el viernes a la
 * tarde preguntame por la logística», «todos los días a primera hora contame cómo vienen las ventas».
 *
 * A diferencia de una tarea de la Agenda (`pendings`: un texto fijo PARA el dueño), esta es una instrucción
 * PARA el modelo: a su hora el asistente se despierta, corre un turno con esa instrucción y el resultado le
 * llega al dueño por WhatsApp. Por eso tiene su propia tabla, con estado de ejecuciones, intentos y resultado.
 *
 * Aditiva, sin claves foráneas y con guarda por tabla (correrla dos veces no rompe).
 */
class CreateAiTareasDelAsistenteTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('ai_tareas_del_asistente')) {
            return;
        }

        Schema::create('ai_tareas_del_asistente', function (Blueprint $table) {
            $table->increments('id');

            /* El dueño del negocio (nunca un empleado). */
            $table->unsignedInteger('owner_id');

            /* Quién la pidió (el dueño; columna para no migrar si algún día pide otra persona). */
            $table->unsignedInteger('auth_user_id')->nullable();

            /* Área del negocio a la que pertenece (previsto para los sub-agentes por área). */
            $table->string('area', 40)->default('general');

            $table->string('titulo', 120);

            /* Lo que el asistente tiene que hacer a esa hora (≤ 1000 caracteres, lo controla el helper). */
            $table->text('instruccion');

            /* una_vez | diaria | dias_semana | semanal */
            $table->string('recurrencia', 20)->default('una_vez');

            /* Días ISO (1 = lunes … 7 = domingo) separados por coma: "1,3,5". */
            $table->string('dias', 20)->nullable();

            /* "HH:MM" resuelta (de la hora dicha, de la franja o de la preferida del dueño). */
            $table->string('hora', 5)->default('09:00');

            /* manana | mediodia | tarde | noche, si la dijo así (para mostrarla). */
            $table->string('franja', 15)->nullable();

            /* una_vez: el día; recurrente: desde cuándo («recién el viernes»). */
            $table->date('fecha_inicio')->nullable();

            $table->date('fecha_fin')->nullable();

            $table->string('zona_horaria', 40)->default('America/Argentina/Buenos_Aires');

            $table->dateTime('proxima_ejecucion_at')->nullable();

            $table->dateTime('ultima_ejecucion_at')->nullable();

            /* activa | pausada | cancelada | terminada */
            $table->string('estado', 20)->default('activa');

            $table->unsignedInteger('ejecuciones')->default(0);

            /* Fallos seguidos: con 3 la tarea pasa a pausada. */
            $table->unsignedTinyInteger('fallos_seguidos')->default(0);

            /* lista | entregada | fallida | omitida:<motivo> */
            $table->string('ultimo_resultado', 255)->nullable();

            /* La conversación donde se pidió. */
            $table->unsignedInteger('ai_conversation_id')->nullable();

            $table->timestamps();

            $table->index('owner_id', 'atda_owner_idx');
            $table->index(['estado', 'proxima_ejecucion_at'], 'atda_estado_prox_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_tareas_del_asistente');
    }
}
