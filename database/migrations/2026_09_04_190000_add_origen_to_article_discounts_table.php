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
 * ## El backfill: solo se afirma lo que se sabe
 *
 * A las filas que ya existen se les pone `manual` SOLO a las que no tienen proveedor
 * (`provider_id` null): esas solo pudo cargarlas una persona desde la ficha del articulo, y de eso
 * si hay certeza.
 *
 * 🔴 Las tagueadas quedan en `null`, o sea "origen desconocido", y NO se marcan como `compra`. La
 * tentacion es etiquetarlas asi —hasta hoy las unicas dos vias que dejaban un tagueado eran la
 * compra y el import— pero seria afirmar un origen que no tenemos registrado. `null` da el mismo
 * tratamiento seguro (la propagacion no rehace lo que no sabe quien puso) sin inventar un dato, y
 * deja la puerta abierta a distinguir mañana "no se" de "es de una compra".
 *
 * Consecuencia practica, y hay que saberla: en un comercio que prenda la preferencia, los articulos
 * que YA tenian descuentos no entran en la propagacion. Entran los que se creen o a los que se les
 * asigne proveedor de ahi en adelante. Es coherente con lo que la opcion ya promete ("no es
 * retroactiva").
 *
 * ⚠️ Por que esto no le pisa el origen a nadie en produccion: la preferencia
 * `aplicar_descuentos_proveedor_al_asignar` nace en la migracion `2026_09_04_150000`, del MISMO
 * release que esta, y nace apagada. Cuando este backfill corre no puede existir todavia ninguna
 * fila creada por la ficha. En un entorno donde `develop` ya venia desplegado (un slot, la demo)
 * si podria haberlas, y quedarian como desconocidas: no se destruye nada, simplemente dejan de
 * actualizarse solas hasta que se les reasigne el proveedor.
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

        /*
         * Backfill de lo unico que se sabe con certeza, ver el docblock. Las tagueadas quedan en
         * null (origen desconocido) a proposito: no se les inventa un origen.
         *
         * Un UPDATE simple y sin JOIN: la tabla puede ser grande y esto corre en el despliegue de
         * cada cliente.
         */
        DB::table('article_discounts')
            ->whereNull('provider_id')
            ->update(['origen' => 'manual']);
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
