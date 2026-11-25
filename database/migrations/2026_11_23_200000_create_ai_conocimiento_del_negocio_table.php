<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El estado por tema del onboarding conversacional (misión onboarding-conversacional-whatsapp, 23/11/2026):
 * qué temas del negocio ya le contó el dueño al asistente (`sabido`) y cuáles no quiso contestar (`omitido`).
 * Una fila por tema marcado; «falta» es no tener fila y «viejo» se calcula con la vigencia del tema (no se guarda).
 *
 * Aditiva, sin claves foráneas ni únicos (el helper hace updateOrCreate dentro de una transacción; si una
 * carrera dejara dos filas de la misma clave, se lee la de `marcado_at` mayor) y con guarda por tabla.
 */
class CreateAiConocimientoDelNegocioTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('ai_conocimiento_del_negocio')) {
            return;
        }

        Schema::create('ai_conocimiento_del_negocio', function (Blueprint $table) {
            $table->increments('id');

            /* El dueño del negocio (nunca un empleado). */
            $table->unsignedInteger('owner_id');

            /* La clave del tema en el catálogo (ConocimientoDelNegocioIaHelper::catalogo()). */
            $table->string('clave', 60);

            /* sabido | omitido */
            $table->string('estado', 15)->default('sabido');

            /* agente | cuestionario | dueno */
            $table->string('fuente', 20)->default('agente');

            /* La conversación donde lo contó (null si vino del formulario). */
            $table->unsignedInteger('ai_conversation_id')->nullable();

            $table->dateTime('marcado_at');

            $table->timestamps();

            $table->index('owner_id', 'acn_owner_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_conocimiento_del_negocio');
    }
}
