<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `category_proposal_items`: a qué categoría y subcategoría cae cada artículo en cada sistema
 * propuesto (misión categorizacion-tres-modelos, 5/10/2026). Hay una fila por (propuesta, artículo).
 *
 * `confianza` es lo que dice la skill: segura | dudosa | ninguna (no pudo ubicarlo).
 *
 * Valores de `estado`:
 *   propuesta   → todavía no se eligió el sistema (o se volvió atrás).
 *   aplicada    → el sistema se eligió y el artículo ya tiene su categoría y subcategoría.
 *   a_revisar   → el sistema se eligió pero el ítem era dudoso: el artículo queda SIN categoría
 *                 hasta que el dueño lo apruebe.
 *   sin_asignar → el sistema se eligió y la skill no pudo ubicar el artículo.
 *   aprobada    → el dueño aprobó un ítem a revisar: ya tiene su categoría.
 *   rechazada   → el dueño rechazó un ítem a revisar: el artículo sigue sin categoría.
 *
 * `prev_category_id` y `prev_sub_category_id` guardan lo que tenía el artículo antes de aplicar (o
 * de aprobar): es lo que permite volver atrás, porque el UPDATE masivo de `articles` no deja historial.
 * Una categoría "borrada" se escribe hoy como 0 en el sistema, por eso estas dos columnas pueden
 * traer NULL o 0.
 *
 * La unicidad (proposal_id, article_id) es de dos enteros, no de strings: permite el `upsert` por
 * lotes con el que la skill reenvía asignaciones sin duplicar.
 *
 * Sin foreign keys por convención del proyecto.
 */
class CreateCategoryProposalItemsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('category_proposal_items')) {
            return;
        }

        Schema::create('category_proposal_items', function (Blueprint $table) {
            $table->id();

            // La propuesta (tarjeta) y el dueño del comercio.
            $table->unsignedBigInteger('proposal_id');
            $table->unsignedBigInteger('user_id');

            // El artículo.
            $table->unsignedBigInteger('article_id');

            // Los nodos de la propuesta donde cae: categoría y (opcional) subcategoría.
            $table->unsignedBigInteger('node_id')->nullable();
            $table->unsignedBigInteger('sub_node_id')->nullable();

            // Lo que dijo la skill y la frase que explica una duda.
            $table->string('confianza', 10)->default('segura');
            $table->string('motivo', 255)->nullable();

            // Dónde está el ítem en el ciclo (ver el docblock de la clase).
            $table->string('estado', 15)->default('propuesta');

            // Lo que tenía el artículo antes de aplicar (para volver atrás).
            $table->unsignedBigInteger('prev_category_id')->nullable();
            $table->unsignedBigInteger('prev_sub_category_id')->nullable();

            // Quién aprobó o rechazó, y cuándo.
            $table->unsignedBigInteger('revisado_por')->nullable();
            $table->timestamp('revisado_at')->nullable();

            $table->timestamps();

            // Un ítem por artículo y propuesta (dos enteros: permite upsert).
            $table->unique(['proposal_id', 'article_id'], 'cpi_prop_art_uq');

            // Solapas de la revisión (por estado) y conteos por nodo.
            $table->index(['proposal_id', 'estado'], 'cpi_prop_estado_idx');
            $table->index(['proposal_id', 'node_id'], 'cpi_prop_node_idx');

            // Buscar los ítems de un artículo (por ejemplo para limpiar al borrarlo).
            $table->index('article_id', 'cpi_art_idx');
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('category_proposal_items');
    }
}
