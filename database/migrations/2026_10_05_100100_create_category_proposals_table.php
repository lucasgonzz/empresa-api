<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `category_proposals`: una tarjeta de la solapa Categorías, o sea un sistema de categorías
 * propuesto dentro de una corrida (misión categorizacion-tres-modelos, 5/10/2026).
 *
 * Valores de `tipo`:
 *   nueva    → un árbol nuevo que armó la skill (sus nodos viven en `category_proposal_nodes`).
 *   mantener → "Mantener las mías": el árbol son las categorías que el dueño ya tiene (el servidor
 *              arma los nodos con las existentes) y la skill solo propone dónde caen los artículos
 *              que hoy no tienen categoría.
 * `clave` identifica la tarjeta dentro de la corrida: `A`, `B`, `C` o `mantener`.
 *
 * `resumen` es la línea corta de la tarjeta y `descripcion` explica en qué se basó el sistema y para
 * qué negocio sirve (es lo que Lucas le cuenta al dueño de cada opción).
 *
 * Sin foreign keys por convención del proyecto.
 */
class CreateCategoryProposalsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('category_proposals')) {
            return;
        }

        Schema::create('category_proposals', function (Blueprint $table) {
            $table->id();

            // La corrida a la que pertenece.
            $table->unsignedBigInteger('run_id');
            $table->index('run_id', 'cp_run_idx');

            // Dueño del comercio (repetido para filtrar por dueño sin pasar por la corrida).
            $table->unsignedBigInteger('user_id');

            // Identificación y tipo de la tarjeta (ver el docblock de la clase).
            $table->string('clave', 12);
            $table->string('tipo', 10)->default('nueva');

            // Lo que ve el dueño en la tarjeta.
            $table->string('nombre', 120);
            $table->string('resumen', 255)->nullable();
            $table->text('descripcion')->nullable();

            // Orden de las tarjetas (1, 2, 3, ...).
            $table->unsignedTinyInteger('orden')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('category_proposals');
    }
}
