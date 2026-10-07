<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `category_proposal_nodes`: el árbol de cada sistema propuesto, categorías y subcategorías
 * (misión categorizacion-tres-modelos, 5/10/2026).
 *
 * `parent_id` es NULL en las categorías y el id de la categoría (otro nodo de la misma propuesta) en
 * las subcategorías. Solo hay dos niveles.
 *
 * `clave_nombre` es el nombre normalizado para comparar (minúsculas, sin acentos con Str::ascii,
 * espacios colapsados, trim): con ella se resuelven los nombres que manda la skill en las asignaciones
 * y se reutiliza una categoría que el dueño ya tenía con el mismo nombre.
 *
 * Los `existing_*` solo se llenan en las propuestas `mantener` (a qué categoría o subcategoría real
 * corresponde el nodo). Los `real_*` se llenan al aplicar la elección: a qué fila real de
 * `categories` / `sub_categories` quedó atado el nodo, y `real_creado` dice si la creó el aplicar
 * (1) o la reutilizó (0): al volver atrás solo se mandan a la papelera las creadas.
 *
 * No lleva columnas llamadas `category_id`, `sub_category_id` ni `brand_id` a propósito: el catálogo
 * del asistente de IA cuenta las tablas con esas columnas como "referencias colgadas" al proponer
 * borrar una categoría, y estos ids apuntan a nodos de una propuesta, no a categorías.
 *
 * Sin foreign keys por convención del proyecto.
 */
class CreateCategoryProposalNodesTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('category_proposal_nodes')) {
            return;
        }

        Schema::create('category_proposal_nodes', function (Blueprint $table) {
            $table->id();

            // La propuesta (tarjeta) a la que pertenece.
            $table->unsignedBigInteger('proposal_id');

            // Dueño del comercio.
            $table->unsignedBigInteger('user_id');

            // NULL = categoría; id del nodo-categoría = subcategoría.
            $table->unsignedBigInteger('parent_id')->nullable();

            // Nombre tal cual lo propuso la skill y su versión normalizada para comparar.
            $table->string('nombre', 128);
            $table->string('clave_nombre', 128);

            // Orden dentro de su nivel.
            $table->unsignedSmallInteger('orden')->default(0);

            // Solo propuestas `mantener`: la categoría / subcategoría real que representa el nodo.
            $table->unsignedBigInteger('existing_category_id')->nullable();
            $table->unsignedBigInteger('existing_sub_category_id')->nullable();

            // Se llenan al aplicar la elección (ver el docblock de la clase).
            $table->unsignedBigInteger('real_category_id')->nullable();
            $table->unsignedBigInteger('real_sub_category_id')->nullable();
            $table->boolean('real_creado')->default(false);

            $table->timestamps();

            // Armar el árbol de una propuesta, y resolver un nombre dentro de ella.
            $table->index(['proposal_id', 'parent_id'], 'cpn_prop_parent_idx');
            $table->index(['proposal_id', 'clave_nombre'], 'cpn_prop_clave_idx');
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('category_proposal_nodes');
    }
}
