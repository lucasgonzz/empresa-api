<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `dimension_search_items`: una fila por artículo de cada búsqueda masiva de peso y medidas
 * (misión medidas-masivas-catalogo, 27/10/2026). Es lo que la SPA muestra en las tres solapas del
 * detalle de una corrida (Asignadas / A revisar / Sin datos) en Alertas → Catálogo → Medidas.
 *
 * Valores de `status`:
 *   pendiente | procesando | asignada | a_revisar | sin_datos | aprobada | rechazada | quitada |
 *   error_interno | sin_procesar.
 *
 * `motivo` es un código corto (sin_resultados, no_coincide, sin_medidas_en_la_pagina, variante_dudosa,
 * medidas_parciales, coincidencia_por_nombre, coincidencia_probable, ...) y `motivo_detalle` la frase
 * lista para mostrar.
 *
 * La PROPUESTA (`peso_kg`, `alto_cm`, `ancho_cm`, `profundidad_cm`) es lo que la búsqueda encontró y
 * validó, ya en la unidad de la base (peso en kg; alto, ancho y profundidad —el largo— en cm). Una
 * propuesta de un artículo "asignada" ya se escribió en el artículo; una "a_revisar" solo se escribe
 * al aprobarla. Por campo, `evidencia` guarda el valor, la unidad en la que estaba escrito en la
 * página, la cita literal, la URL y el alcance (envío o producto).
 *
 * `aplicado` (json) guarda qué campos se escribieron de verdad en el artículo y con qué valor: es lo
 * que permite "quitar" devolviendo SOLO lo que puso la búsqueda (y solo si el campo sigue valiendo eso).
 *
 * Snapshot de nombre y códigos: el artículo puede editarse o borrarse después, y el diagnóstico tiene
 * que seguir mostrando con qué datos se buscó.
 *
 * Sin foreign keys por convención del proyecto.
 */
class CreateDimensionSearchItemsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('dimension_search_items')) {
            return;
        }

        Schema::create('dimension_search_items', function (Blueprint $table) {
            $table->id();

            // Corrida a la que pertenece.
            $table->unsignedBigInteger('run_id');

            // Dueño del comercio (repetido acá para los filtros por dueño sin pasar por la corrida).
            $table->unsignedBigInteger('user_id');

            // Artículo buscado.
            $table->unsignedBigInteger('article_id');

            // Snapshot del artículo al crear la corrida.
            $table->string('article_name', 255)->nullable();
            $table->string('article_bar_code', 100)->nullable();
            $table->string('article_provider_code', 100)->nullable();

            // Orden de procesamiento dentro de la corrida (1..N): publicados en la tienda → con stock → el resto.
            $table->unsignedInteger('orden')->default(0);

            // Estado del artículo dentro de la corrida (ver el docblock de la clase).
            $table->string('status', 20)->default('pendiente');

            // Código del motivo principal y la frase lista para mostrar.
            $table->string('motivo', 40)->nullable();
            $table->text('motivo_detalle')->nullable();

            // codigo_de_barras | codigo_proveedor | nombre: con qué criterio de búsqueda se consiguió el dato.
            $table->string('criterio_usado', 20)->nullable();

            // La propuesta, en la unidad de la base: peso en kg, el resto en cm.
            $table->decimal('peso_kg', 30, 4)->nullable();
            $table->decimal('alto_cm', 30, 4)->nullable();
            $table->decimal('ancho_cm', 30, 4)->nullable();
            $table->decimal('profundidad_cm', 30, 4)->nullable();

            // Por campo: {valor, unidad_original, valor_original, convertido, cita, url, alcance}.
            $table->json('evidencia')->nullable();

            // Las páginas de las que salió el dato: [{url, titulo}].
            $table->json('fuentes')->nullable();

            // Frase corta de la IA cuando la variante o la presentación difiere ("la página es de la versión con espada de 35 cm").
            $table->text('nota_ia')->nullable();

            // Por plan de búsqueda: la consulta, los resultados, las páginas leídas y por qué se descartó cada una.
            $table->json('diagnostico')->nullable();

            // Lo que gastó este artículo.
            $table->unsignedTinyInteger('busquedas')->default(0);
            $table->unsignedTinyInteger('validaciones_ia')->default(0);

            // Veces que un tramo lo reclamó para procesarlo. Con 2 intentos fallidos queda como
            // error_interno: uno "venenoso" no puede trabar la corrida entera.
            $table->unsignedTinyInteger('intentos')->default(0);

            // La ficha del tramo (job) que lo reclamó (ver ProcessDimensionSearchRunJob::failed()).
            $table->string('tramo', 40)->nullable();

            // Qué campos se escribieron en el artículo y con qué valor (para poder quitarlos).
            $table->json('aplicado')->nullable();

            // Quién la aprobó / rechazó / quitó, y cuándo.
            $table->unsignedBigInteger('revisado_por')->nullable();
            $table->timestamp('revisado_at')->nullable();

            // Cuándo terminó el motor con este artículo.
            $table->timestamp('procesado_at')->nullable();

            $table->timestamps();

            // Solapas del detalle (por estado) y el próximo pendiente del tramo (por orden).
            $table->index(['run_id', 'status'], 'dsi_run_status_idx');
            $table->index(['run_id', 'orden'], 'dsi_run_orden_idx');

            // La selección del catálogo: "¿este artículo está a revisar o ya se buscó sin éxito?".
            $table->index(['user_id', 'article_id'], 'dsi_user_article_idx');

            // El badge de Alertas: cuántos artículos esperan revisión en todo el comercio.
            $table->index(['user_id', 'status'], 'dsi_user_status_idx');
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('dimension_search_items');
    }
}
