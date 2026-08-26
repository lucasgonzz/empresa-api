<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los grupos de estados de produccion (mision produccion-v2-multinivel, 26/8/2026).
 *
 * Un grupo junta los estados que corresponden a una etapa del proceso. En la fabrica de sillas
 * de Quino: los estados por los que pasa una pata ("Cortado", "Doblado", "Lijado") son un grupo,
 * y los de la estructura ya armada son otro. Sin grupos, los 13 estados de la cuenta aparecen
 * mezclados en cada select y en la solapa "Cantidades en cada Estado" de cualquier lote.
 *
 * El grupo se elige en la RUTA de la receta, no en la receta: una ruta tercerizada puede pasar
 * por otros estados que la interna del mismo producto (decision de Lucas).
 *
 * 🔴 NADA VALIDA QUE UN ESTADO ELEGIDO PERTENEZCA AL GRUPO DE LA RUTA. El grupo filtra selects
 * en la interfaz; no es una restriccion de la API. Es a proposito: una validacion 422 romperia
 * las rutas ya cargadas que mezclan estados, y la regla del modulo es que el sistema controla
 * el saldo por estado, no el orden de la cadena. Si algun dia se quiere dura, es aditiva.
 *
 * Sin foreign keys fisicas, igual que todo el schema de produccion.
 */
class CreateOrderProductionStatusGroupsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('order_production_status_groups')) {
            return;
        }

        Schema::create('order_production_status_groups', function (Blueprint $table) {

            /* Clave primaria autoincremental (bigint unsigned) */
            $table->id();

            /* El ABM necesita un `is_title` para la fila del listado */
            $table->string('name', 191);

            /*
             * Orden con el que el grupo se muestra en la lista. Nullable porque
             * `order_production_statuses.position` tambien lo es y este ABM lo espeja: un grupo
             * recien creado sin posicion no puede quedar fuera del listado.
             */
            $table->integer('position')->nullable();

            /*
             * El comercio dueno del grupo. unsignedInteger y no unsignedBigInteger porque
             * `users.id` es increments() -> int unsigned. La columna espeja el tipo de su fuente:
             * una columna derivada mas angosta (o mas ancha) que aquello a lo que apunta es la
             * clase de error que despues aparece como un join que no matchea.
             */
            $table->unsignedInteger('user_id');

            $table->timestamps();

            /* El index es por user_id porque toda lectura del ABM filtra por comercio. */
            $table->index(['user_id'], 'ops_groups_user_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_production_status_groups');
    }
}
