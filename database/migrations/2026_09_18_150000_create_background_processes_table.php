<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: crea background_processes, el registro único de los procesos en segundo plano
 * que el usuario puede ver (misión procesos-en-segundo-plano, 18/9/2026).
 *
 * Existe porque cada proceso largo llevaba su avance en una tabla propia y con su propio
 * aviso —import_statuses con ImportStatusUpdated, price_update_runs sin ningún aviso hasta el
 * final, masive_updates sin contador siquiera— y el usuario no tenía un solo lugar donde ver
 * "qué está corriendo ahora". Esta tabla NO reemplaza a ninguna de esas: es la capa de
 * presentación común, con un vínculo (referencia_type/referencia_id) al registro propio de cada
 * flujo para el detalle.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateBackgroundProcessesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('background_processes')) {
            return;
        }

        Schema::create('background_processes', function (Blueprint $table) {
            $table->id();

            /** Referencia estable para la SPA, independiente del id autoincremental. */
            $table->string('uuid', 36)->unique();

            /** Dueño del comercio: es el que da el canal de broadcast y el scope de los endpoints. */
            $table->integer('user_id')->unsigned()->index();

            /** Quién lo lanzó (dueño o empleado). Null cuando lo lanzó el sistema. */
            $table->integer('auth_user_id')->unsigned()->nullable();

            /**
             * importacion_articulos | recalculo_precios | actualizacion_masiva | reversion_masiva |
             * eliminacion_masiva | exportacion | analisis_excel | imagenes_automaticas |
             * descripciones_ia | ... Es el que elige el componente de detalle en la SPA.
             */
            $table->string('tipo', 50);

            /** Lo que lee el usuario: "Importación de artículos". */
            $table->string('titulo', 150);

            /** Segunda línea: "Proveedor Bulonera · 4.500 filas". */
            $table->string('detalle', 255)->nullable();

            /** pendiente | en_proceso | completado | fallo */
            $table->string('status', 20)->default('pendiente')->index();

            /**
             * Unidades totales (lotes, artículos, registros). NULL = no medible: la SPA muestra
             * una barra indeterminada en vez de un porcentaje inventado.
             */
            $table->integer('total')->unsigned()->nullable();
            $table->integer('procesados')->unsigned()->default(0);

            /** Se calcula al escribir; NULL mientras no haya total. */
            $table->tinyInteger('porcentaje')->unsigned()->nullable();

            /** "Lote 3 de 12", "Analizando el archivo". */
            $table->string('etapa', 120)->nullable();

            /** Nombre de la unidad para el texto: "lotes", "artículos", "registros". */
            $table->string('unidad', 30)->nullable();

            /** Vínculo al registro propio del flujo (ImportStatus, PriceUpdateRun, MasiveUpdate...). */
            $table->string('referencia_type', 120)->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();

            /** Números parciales/finales que muestra el detalle. */
            $table->longText('resultado_json')->nullable();

            $table->text('error_message')->nullable();

            /**
             * Última vez que se emitió por Pusher. Es el throttle: un loop de 3000 artículos
             * avanza varias veces por segundo y sin esto serían cientos de mensajes por proceso.
             */
            $table->timestamp('broadcast_at')->nullable();

            /** El usuario cerró la fila en el modal (solo aplica a terminados). */
            $table->timestamp('visto_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'background_processes_user_status_idx');
            $table->index(['referencia_type', 'referencia_id'], 'background_processes_referencia_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('background_processes');
    }
}
