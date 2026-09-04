<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `origen` a article_discounts: QUIEN creo cada descuento.
 *
 * 🔴 POR QUE EXISTE. `article_discounts` no tenia forma de saber de donde salia cada fila: la
 * compra, el import de Excel, la ficha del proveedor y la carga a mano escriben todas con la misma
 * forma (`provider_id` seteado y `tipo = bonificacion_proveedor`). El codigo que propaga un cambio
 * de la ficha a los articulos tenia que ADIVINAR el origen mirando la forma del dato —si trae
 * porcentaje, si trae monto, si esta marcada como editada—, y esa inferencia tiene agujeros
 * combinatorios.
 *
 * Cuatro rondas de verificacion independiente encontraron NUEVE defectos, todos de la misma
 * familia: un descuento destruido, duplicado o pisado sin preguntar, en silencio. Cada arreglo
 * tapaba una combinacion y destapaba otra. Con el origen explicito la propagacion deja de adivinar:
 * toca lo que creo la ficha, y nada mas.
 *
 * Los valores viven en las constantes `ArticleDiscount::ORIGEN_*`.
 *
 * ## El backfill, y por que es CONSERVADOR
 *
 * A las filas que ya existen se les pone:
 *   - `manual` a las que no tienen proveedor (`provider_id` null): solo pudo cargarlas una persona.
 *   - `compra` a todas las tagueadas.
 *
 * Marcarlas como `compra` no es una afirmacion historica: es la eleccion segura. Hasta hoy las
 * unicas dos vias que dejaban un tagueado eran la compra y el import, y ninguna de las dos se puede
 * reponer desde la ficha del proveedor (la compra trae la bonificacion negociada de esa compra; el
 * import, la de la planilla). Al no ser `ficha_proveedor`, la propagacion no las toca — que es
 * exactamente lo que corresponde con un dato del que no tenemos registro cierto.
 *
 * Consecuencia practica, y hay que saberla: en un comercio que prenda la preferencia, los articulos
 * que YA tenian descuentos no entran en la propagacion. Entran los que se creen o a los que se les
 * asigne proveedor de ahi en adelante. Es coherente con lo que la opcion ya promete ("no es
 * retroactiva").
 *
 * `null` queda como "origen desconocido" para cualquier fila que se cuele sin declararlo, y la
 * propagacion tampoco la toca: sin saber quien la puso, no se rehace.
 *
 * La base es compartida con `tienda`, que lee `article_discounts` pero no esta columna. Migracion
 * aditiva, compatible hacia atras en las dos direcciones; nada se renombra ni se saca.
 */
class AddOrigenToArticleDiscountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('article_discounts', 'origen')) {
            return;
        }

        Schema::table('article_discounts', function (Blueprint $table) {
            $table->string('origen', 30)->nullable();
        });

        // Backfill conservador, ver el docblock. Dos UPDATE simples y sin JOIN: la tabla puede ser
        // grande y esto corre en el despliegue de cada cliente.
        DB::table('article_discounts')
            ->whereNull('provider_id')
            ->update(['origen' => 'manual']);

        DB::table('article_discounts')
            ->whereNotNull('provider_id')
            ->update(['origen' => 'compra']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('article_discounts', 'origen')) {
            return;
        }

        Schema::table('article_discounts', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
}
