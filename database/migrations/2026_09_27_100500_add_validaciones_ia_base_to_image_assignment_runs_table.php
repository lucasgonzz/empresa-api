<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `image_assignment_runs.validaciones_ia_base` (misión imagenes-catalogo-completo, sexta pasada del
 * 27/9/2026): las validaciones con IA que ya NO cuentan para el techo de la asignación.
 *
 * El techo de validaciones con IA de una asignación (el mayor entre
 * ARTICLE_IMAGE_VALIDATION_MAX_CALLS_BATCH y 4 por artículo) se compara contra
 * `validaciones_ia - validaciones_ia_base`. Cuando se alcanza, la asignación queda `fallida` y se
 * puede reanudar; al reanudar, la base pasa a ser lo que ya se validó, así el tramo nuevo arranca
 * con el techo entero en vez de volver a cortar en el primer artículo. `validaciones_ia` no se toca:
 * es lo que se pagó y lo que se muestra.
 *
 * Migración aparte (y no en la de creación de la tabla) para las bases que ya corrieron esa. Columna
 * nueva con default y sin claves foráneas, como pide el workspace.
 */
class AddValidacionesIaBaseToImageAssignmentRunsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('image_assignment_runs') || Schema::hasColumn('image_assignment_runs', 'validaciones_ia_base')) {
            return;
        }

        Schema::table('image_assignment_runs', function (Blueprint $table) {
            $table->unsignedInteger('validaciones_ia_base')->default(0)->after('validaciones_ia');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('image_assignment_runs') || !Schema::hasColumn('image_assignment_runs', 'validaciones_ia_base')) {
            return;
        }

        Schema::table('image_assignment_runs', function (Blueprint $table) {
            $table->dropColumn('validaciones_ia_base');
        });
    }
}
