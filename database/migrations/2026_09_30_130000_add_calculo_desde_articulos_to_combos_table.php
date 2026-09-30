<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `combos.calcular_desde_articulos`, `combos.descuento_tipo` y `combos.descuento_valor` — el combo
 * que se calcula solo a partir de los artículos que lo componen (misión combos-calculados,
 * 30/9/2026).
 *
 * Hasta hoy `combos.cost` y `combos.price` eran dos números que la persona tipeaba a mano. Cuando
 * cambiaba el precio o el costo de un artículo, el combo quedaba con la cuenta vieja y nadie se
 * enteraba: el combo se vendía a un precio que ya no cerraba con sus componentes, y la ganancia de
 * la venta salía mal. Estas tres columnas son lo mínimo para que el sistema pueda hacer la cuenta
 * por su cuenta:
 *
 *  - `calcular_desde_articulos`: el interruptor. Prendido, `cost` y `price` los escribe el
 *    servidor (`ComboCalculadoHelper`) cada vez que se guarda el combo o cambia un componente;
 *    apagado, el combo es el de siempre, con los números que cargó la persona.
 *  - `descuento_tipo` ('porcentaje' | 'monto' | NULL) y `descuento_valor`: un descuento que se
 *    aplica SOLO al precio de venta del combo calculado, nunca al costo (el costo es lo que le
 *    cuesta al comercio, y un descuento comercial no lo baja).
 *
 * 🔴 EL DEFAULT DE `calcular_desde_articulos` ES 0 A PROPÓSITO, y no es una preferencia de estilo.
 * Hay clientes con combos ya cargados a mano, con precios que pusieron adrede (un precio "redondo"
 * de promoción, por ejemplo). Con default 1, el primer recálculo les pisaría el precio de todos los
 * combos sin que nadie lo decida. Con 0, desplegar esta migración no cambia el precio de ningún
 * combo existente: el dueño prende el check combo por combo, si quiere.
 *
 * `descuento_valor` es DECIMAL(12,2) NOT NULL DEFAULT 0 para que un SPA o una tienda que lean la
 * columna nunca reciban null; el "no hay descuento" lo dice `descuento_tipo` = NULL.
 *
 * Idempotente por columna (Schema::hasColumn) y sin índices ni foreign keys: son columnas de
 * presentación y de cálculo, nadie filtra por ellas. La otra punta (tienda-api, que no tiene
 * migraciones y comparte esta base) las lee detrás de una guarda de esquema.
 */
class AddCalculoDesdeArticulosToCombosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('combos', 'calcular_desde_articulos')) {
            Schema::table('combos', function (Blueprint $table) {
                $table->boolean('calcular_desde_articulos')->default(0);
            });
        }

        if (!Schema::hasColumn('combos', 'descuento_tipo')) {
            Schema::table('combos', function (Blueprint $table) {
                $table->string('descuento_tipo', 20)->nullable();
            });
        }

        if (!Schema::hasColumn('combos', 'descuento_valor')) {
            Schema::table('combos', function (Blueprint $table) {
                $table->decimal('descuento_valor', 12, 2)->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        foreach (['descuento_valor', 'descuento_tipo', 'calcular_desde_articulos'] as $columna) {
            if (Schema::hasColumn('combos', $columna)) {
                Schema::table('combos', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
