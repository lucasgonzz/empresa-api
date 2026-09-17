<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `combos.online` — el interruptor que publica un combo en el ecommerce
 * (misión combos-y-rangos-de-precio, 16/9/2026).
 *
 * Hasta hoy un combo era una receta de uso interno: 8 columnas desde 2022, sin nada de
 * presentación ni de estado. No tiene imagen, ni descripción, ni slug, ni stock propio — a
 * diferencia de `promocion_vinotecas`, que sí nació como artículo virtual publicable y por eso
 * tiene su `online`. Esta columna es lo mínimo que hace falta para que la tienda pueda preguntar
 * "¿cuáles de estos combos quiere vender el dueño?".
 *
 * 🔴 El default es 0 A PROPÓSITO, y no es una preferencia de estilo. Hay clientes con combos ya
 * cargados y armados para uso interno (el combo es también una forma de agrupar la carga de una
 * venta). Si el default fuera 1, el primer release publicaría en el ecommerce de cada uno de esos
 * clientes combos que nadie decidió vender, sin que ningún aviso lo denuncie. El dueño prende los
 * que quiere, uno por uno, desde el ABM.
 *
 * Compatible en las dos direcciones: con la columna presente y una empresa-spa vieja (sin el check
 * en el ABM) todos los combos quedan en 0 y nada se publica; con la columna ausente y una tienda
 * nueva, el lector la consulta con Schema::hasColumn antes de tocarla y devuelve vacío.
 */
class AddOnlineToCombosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('combos', 'online')) {
            Schema::table('combos', function (Blueprint $table) {
                $table->boolean('online')->default(0);
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
        if (Schema::hasColumn('combos', 'online')) {
            Schema::table('combos', function (Blueprint $table) {
                $table->dropColumn('online');
            });
        }
    }
}
