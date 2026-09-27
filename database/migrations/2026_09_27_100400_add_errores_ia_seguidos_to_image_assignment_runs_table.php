<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `image_assignment_runs.errores_ia_seguidos` (misión imagenes-catalogo-completo, ajustes del
 * chequeo independiente del 27/9/2026, plan §13): artículos seguidos en los que se le pidió algo a
 * la IA y NINGUNA llamada respondió (clave vencida, sin saldo, Anthropic caído).
 *
 * Con 5 seguidos la asignación queda `fallida` y se puede reanudar: sin IA nada se asigna solo, y
 * seguir buscando sería pagar búsquedas para dejar todo "a revisar". Vive en la asignación y no en
 * el tramo por la misma razón que `errores_proveedor_seguidos`: con la IA colgada (25 s de timeout
 * por llamada) entran uno o dos artículos por tramo, y un contador del tramo nunca llegaría a 5.
 *
 * Migración aparte (y no en la de creación de la tabla) para las bases que ya corrieron esa.
 */
class AddErroresIaSeguidosToImageAssignmentRunsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('image_assignment_runs') || Schema::hasColumn('image_assignment_runs', 'errores_ia_seguidos')) {
            return;
        }

        Schema::table('image_assignment_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('errores_ia_seguidos')->default(0)->after('errores_proveedor_seguidos');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('image_assignment_runs') || !Schema::hasColumn('image_assignment_runs', 'errores_ia_seguidos')) {
            return;
        }

        Schema::table('image_assignment_runs', function (Blueprint $table) {
            $table->dropColumn('errores_ia_seguidos');
        });
    }
}
