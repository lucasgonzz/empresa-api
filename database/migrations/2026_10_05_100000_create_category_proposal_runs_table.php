<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `category_proposal_runs`: una corrida de la skill /categorizar sobre el catálogo de un dueño
 * (misión categorizacion-tres-modelos, 5/10/2026). Agrupa los tres sistemas de categorías que armó la
 * skill (más, si el comercio ya tenía categorías, la tarjeta "Mantener las mías") y lleva el ciclo de
 * vida: la skill la carga, un humano da el ok para publicarla, el dueño elige un sistema desde
 * Alertas → Catálogo → Categorías y recién ahí se tocan `categories` y `articles`.
 *
 * Valores de `estado`:
 *   preparando (la skill está cargando propuestas y asignaciones) · lista (el dueño la ve y puede
 *   elegir) · aplicando (transitorio, bajo candado, mientras se aplica la elección) · elegida ·
 *   descartada (la reemplazó otra corrida, o la descartó quien la preparó).
 * A lo sumo UNA corrida no descartada por dueño.
 *
 * `categorias_eliminadas` (json) guarda lo que el aplicar mandó a la papelera
 * (`[{"tipo":"categoria"|"subcategoria","id":N}]`) para poder restaurarlo al volver atrás; `resultado`
 * (json) guarda el resumen del aplicar (cuántas categorías creó, cuántos artículos asignó, etc.).
 *
 * `revision_iniciada_at` se llena con la primera aprobación o rechazo posterior a la elección: es la
 * marca de la regla "cambiar de sistema solo mientras nadie haya aprobado ni editado nada".
 * `visto_at` se llena cuando el dueño abre la solapa con la corrida lista (apaga el badge).
 *
 * Sin foreign keys por convención del proyecto.
 */
class CreateCategoryProposalRunsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('category_proposal_runs')) {
            return;
        }

        Schema::create('category_proposal_runs', function (Blueprint $table) {
            $table->id();

            // Dueño del comercio (users.id con owner_id NULL).
            $table->unsignedBigInteger('user_id');

            // Ciclo de vida (ver el docblock de la clase) y quién la originó.
            $table->string('estado', 15)->default('preparando');
            $table->string('origen', 20)->default('skill');

            // Cuántos artículos vivos del dueño había al crearla.
            $table->unsignedInteger('articulos_total')->default(0);

            // La elección del dueño: qué propuesta, cuándo, quién (users.id de la sesión) y si fue
            // con la sesión del acceso maestro. `eliminar_categorias_vacias` es lo que pidió al elegir.
            $table->unsignedBigInteger('propuesta_elegida_id')->nullable();
            $table->timestamp('elegida_at')->nullable();
            $table->unsignedBigInteger('elegida_por')->nullable();
            $table->boolean('elegida_con_acceso_maestro')->default(false);
            $table->boolean('eliminar_categorias_vacias')->default(false);

            // json: lo que el aplicar mandó a la papelera (para volver atrás) y el resumen del aplicar.
            $table->text('categorias_eliminadas')->nullable();
            $table->text('resultado')->nullable();

            // Primera aprobación o rechazo después de elegir (regla de "volver atrás").
            $table->timestamp('revision_iniciada_at')->nullable();

            // El dueño ya abrió la solapa con la corrida lista.
            $table->timestamp('visto_at')->nullable();
            $table->timestamp('descartada_at')->nullable();

            $table->timestamps();

            // El badge de Alertas y la búsqueda de "la corrida vigente del dueño".
            $table->index(['user_id', 'estado'], 'cpr_user_estado_idx');
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('category_proposal_runs');
    }
}
