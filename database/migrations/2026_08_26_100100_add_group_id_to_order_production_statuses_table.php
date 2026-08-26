<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El grupo al que pertenece un estado de produccion (mision produccion-v2-multinivel, 26/8/2026).
 *
 * 🔴 NULLABLE Y SIN BACKFILL, A PROPOSITO. `null` significa "estado sin grupo", y un estado sin
 * grupo se comporta exactamente como hasta hoy: aparece en todas las rutas y participa de la
 * cascada global del estado final. Todos los estados que ya existen en las bases de los clientes
 * quedan en null y nada cambia para ellos. Ponerle un default distinto de null, o backfillear un
 * grupo "General", obligaria a que cada cuenta revise 13 estados antes de poder seguir
 * trabajando.
 *
 * Sin foreign key fisica, igual que todo el schema de produccion.
 */
class AddGroupIdToOrderProductionStatusesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('order_production_statuses', 'order_production_status_group_id')) {
            return;
        }

        Schema::table('order_production_statuses', function (Blueprint $table) {
            /*
             * unsignedBigInteger porque `order_production_status_groups.id` es `$table->id()`,
             * o sea bigint unsigned. La columna espeja el tipo de su fuente.
             */
            $table->unsignedBigInteger('order_production_status_group_id')->nullable()->after('position');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('order_production_statuses', 'order_production_status_group_id')) {
            return;
        }

        Schema::table('order_production_statuses', function (Blueprint $table) {
            $table->dropColumn('order_production_status_group_id');
        });
    }
}
