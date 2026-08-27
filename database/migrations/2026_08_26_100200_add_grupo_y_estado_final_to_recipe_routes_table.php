<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El grupo de estados y el estado final propios de una ruta de receta
 * (mision produccion-v2-multinivel, 26/8/2026).
 *
 * Las dos columnas son NULLABLE Y SIN BACKFILL, y de eso depende que esta mision no rompa a
 * ningun cliente que ya use el modulo:
 *
 *   - `order_production_status_group_id` null = la ruta no limita los estados. Los selects
 *     muestran todos los estados de la cuenta, como hasta hoy.
 *
 *   - `end_order_production_status_id` null = la ruta no declara en que estado la unidad queda
 *     terminada, asi que el motor cae a la cascada: primero el estado de mayor position DENTRO
 *     del grupo de la ruta, y si la ruta tampoco tiene grupo, el estado de mayor position de
 *     toda la cuenta — que es exactamente el unico criterio que existia antes de esta mision.
 *
 * Toda ruta ya cargada tiene las dos en null y cae al comportamiento de siempre. Por eso no hay
 * backfill: no hay nada que rellenar.
 *
 * Sin foreign keys fisicas, igual que todo el schema de produccion.
 */
class AddGrupoYEstadoFinalToRecipeRoutesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('recipe_routes', 'order_production_status_group_id')) {
            Schema::table('recipe_routes', function (Blueprint $table) {
                /* Espeja el tipo de `order_production_status_groups.id` (bigint unsigned). */
                $table->unsignedBigInteger('order_production_status_group_id')->nullable();
            });
        }

        if (!Schema::hasColumn('recipe_routes', 'end_order_production_status_id')) {
            Schema::table('recipe_routes', function (Blueprint $table) {
                /* Espeja el tipo de `order_production_statuses.id` (bigint unsigned). */
                $table->unsignedBigInteger('end_order_production_status_id')->nullable();
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('recipe_routes', 'order_production_status_group_id')) {
            Schema::table('recipe_routes', function (Blueprint $table) {
                $table->dropColumn('order_production_status_group_id');
            });
        }

        if (Schema::hasColumn('recipe_routes', 'end_order_production_status_id')) {
            Schema::table('recipe_routes', function (Blueprint $table) {
                $table->dropColumn('end_order_production_status_id');
            });
        }
    }
}
