<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El historial de la memoria de contexto del agente (misión app-ia-y-memoria, 29/10/2026).
 *
 * Una fila por VERSIÓN de un archivo de `agente_contexto_archivos`, con su contenido completo: la
 * versión 1 (la plantilla) se registra al crear el archivo y cada escritura que cambia algo suma la
 * suya. Así cualquier versión se recupera tal cual estaba, y se sabe quién la escribió (`origen`),
 * por qué (`motivo`) y desde qué conversación (`ai_conversation_id`, null si no vino de una).
 *
 * El índice generado (`contexto/INDICE.md`) NO deja revisiones: se reconstruye entero desde los
 * demás archivos en cada escritura.
 *
 * Sin `updated_at`: una revisión no se edita nunca. Sin foreign keys físicas, siguiendo el estilo
 * del resto del schema, y con guard hasTable para que sea segura de re-ejecutar.
 */
class CreateAgenteContextoRevisionesTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('agente_contexto_revisiones')) {
            return;
        }

        Schema::create('agente_contexto_revisiones', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* Archivo al que pertenece (agente_contexto_archivos.id) */
            $table->unsignedBigInteger('archivo_id');

            /* Número de versión que guarda esta fila */
            $table->unsignedInteger('version');

            /* El contenido completo de esa versión */
            $table->longText('contenido');

            /* Por qué se escribió (lo dice el agente, el cuestionario o la app) */
            $table->string('motivo', 300)->nullable();

            /* Quién la escribió: sistema | cuestionario | agente | dueno */
            $table->string('origen', 20);

            /* Conversación desde la que se escribió, si vino de una */
            $table->unsignedBigInteger('ai_conversation_id')->nullable();

            $table->timestamp('created_at')->nullable();

            /* Las versiones de un archivo, en orden */
            $table->index(['archivo_id', 'version'], 'acr_archivo_version_idx');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agente_contexto_revisiones');
    }
}
