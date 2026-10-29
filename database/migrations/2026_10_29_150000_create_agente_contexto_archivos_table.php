<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La memoria de contexto del agente de IA, por negocio (misión app-ia-y-memoria, 29/10/2026).
 *
 * Calca la estructura del conocimiento de ComercioCity (`claude-comerciocity/contexto/`): un
 * contexto maestro corto que el agente lee SIEMPRE, un índice generado y archivos por tema
 * (dueño, negocio, áreas, decisiones, aprendizajes) que se abren a demanda. Decisión de Lucas del
 * 29/10: vive en la BASE DEL CLIENTE, no en un repositorio — si alguien quiere leerla, la lee de acá.
 *
 * Una fila por archivo. `ruta` es la ruta lógica («contexto/contexto_maestro.md», «areas/stock.md»)
 * y es única por dueño. `user_id` es siempre el DUEÑO (users.id con owner_id null): la memoria es
 * del comercio, no de quien escribe.
 *
 * `origen` dice quién escribió la versión actual: `sistema` (la plantilla y el índice generado),
 * `cuestionario` (el cuestionario de inicio, determinista), `agente` (el agente con
 * guardar_en_contexto) o `dueno` (el dueño editando desde la app). Cada escritura deja su foto en
 * `agente_contexto_revisiones`: nada se pierde.
 *
 * softDeletes por si algún día se borra un archivo: el borrado tiene que ser reversible. El único
 * (user_id, ruta) incluye las filas borradas a propósito: un archivo borrado se restaura, no se
 * duplica.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema, y con guard hasTable para que
 * sea segura de re-ejecutar.
 */
class CreateAgenteContextoArchivosTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('agente_contexto_archivos')) {
            return;
        }

        Schema::create('agente_contexto_archivos', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* Dueño de la cuenta (users.id con owner_id null): de quién es la memoria */
            $table->unsignedBigInteger('user_id');

            /* Ruta lógica del archivo, por ejemplo «areas/stock.md» */
            $table->string('ruta', 150);

            /* Título legible del archivo (lo muestra el índice y la app) */
            $table->string('titulo', 150);

            /* Resumen de una línea, para el índice generado */
            $table->string('resumen', 300)->nullable();

            /* El contenido en markdown */
            $table->longText('contenido');

            /* Versión actual: arranca en 1 y sube con cada escritura que cambia algo */
            $table->unsignedInteger('version')->default(1);

            /* Quién escribió la versión actual: sistema | cuestionario | agente | dueno */
            $table->string('origen', 20)->default('sistema');

            $table->timestamps();
            $table->softDeletes();

            /* Un archivo por ruta y por dueño (incluye los borrados: se restauran, no se duplican) */
            $table->unique(['user_id', 'ruta'], 'aca_user_ruta_unique');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agente_contexto_archivos');
    }
}
