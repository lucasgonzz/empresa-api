<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a article_discounts las dos columnas que dicen DE CUAL descuento del proveedor salio cada
 * fila y como se llama (mision sincronizar-descuentos-proveedor, 17/9/2026).
 *
 *   - `provider_discount_id`: el `provider_discounts.id` puntual que lo origino. Hasta hoy solo se
 *     sabia el PROVEEDOR (`provider_id`, migracion 2026_07_08), no cual de sus bonificaciones.
 *   - `nombre`: copia del nombre de ese descuento, para mostrarlo en la ficha del articulo sin
 *     pagar un JOIN.
 *
 * ## 🔴 `provider_discount_id` es NULLABLE y NADIE puede asumir que este presente
 *
 * Todas las filas anteriores a esta migracion lo tienen en NULL, **incluidas las tagueadas a un
 * proveedor**. No hay backfill posible: de un `article_discount` viejo no se puede saber cual de
 * las bonificaciones del proveedor lo origino — todas comparten la forma (un porcentaje) y varias
 * pueden tener el mismo valor.
 *
 * 🔴 Por eso **ningun camino puede usar esta columna para decidir si un descuento es de
 * proveedor**. Eso lo sigue diciendo `origen` (migracion 2026_09_04_190000), que es la columna que
 * cerro nueve defectos de la familia "descuento destruido, duplicado o pisado en silencio".
 * `provider_discount_id` sirve para UNA sola cosa: saber a que fila de `provider_discounts`
 * corresponde este descuento, para poder renombrarlo en masa. Nada mas.
 *
 * ## Por que se guardan las dos cosas y no solo la relacion
 *
 * `Article::scopeWithAll()` ya arrastra mas de veinte relaciones y es el camino mas caliente del
 * sistema. Sumarle `article_discounts.provider_discount` para mostrar un texto es peso en el peor
 * lugar. Con el nombre copiado, el JSON ya lo trae sin una query mas.
 *
 * Y al reves: si el descuento del proveedor se borra, el articulo conserva el nombre (es una foto y
 * sobrevive) y `provider_discount_id` queda apuntando a nada — que es exactamente el dato correcto:
 * "esto vino de un descuento que ya no existe".
 *
 * 🔴 Cuando el descuento del proveedor se RENOMBRA, `ProviderDiscountController::update()` hace un
 * UPDATE masivo sobre esta columna. Es la unica razon por la que `provider_discount_id` existe, y
 * por eso lleva indice: ese UPDATE filtra por ella.
 *
 * Sin foreign key, como el resto del esquema: si el `provider_discount` se borra, la fila del
 * articulo se queda huerfana a proposito (ver arriba), y una FK con cascade la borraria.
 *
 * Guardas `hasColumn` por columna, independientes: hay ~40 bases de clientes en estados distintos y
 * una puede tener una de las dos si algo quedo a medias.
 *
 * La base es compartida con `tienda`, que lee `article_discounts` pero no estas columnas. Migracion
 * aditiva, compatible hacia atras en las dos direcciones; nada se renombra ni se saca.
 */
class AddProviderDiscountIdAndNombreToArticleDiscountsTable extends Migration
{
    /** Nombre corto y fijo del indice, para que la guarda y el down() miren exactamente el mismo. */
    const INDICE = 'article_discounts_provider_discount_id_idx';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('article_discounts', 'provider_discount_id')) {

            Schema::table('article_discounts', function (Blueprint $table) {
                $table->unsignedBigInteger('provider_discount_id')->nullable();
            });
        }

        // El indice va aparte de la columna: una base donde la columna quedo creada sin indice (un
        // upgrade interrumpido, un ALTER a mano) tiene que poder completarse sin tocar nada mas.
        if (!$this->existe_el_indice()) {

            Schema::table('article_discounts', function (Blueprint $table) {
                $table->index('provider_discount_id', self::INDICE);
            });
        }

        if (!Schema::hasColumn('article_discounts', 'nombre')) {

            Schema::table('article_discounts', function (Blueprint $table) {
                $table->string('nombre', 191)->nullable();
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
        if ($this->existe_el_indice()) {

            Schema::table('article_discounts', function (Blueprint $table) {
                $table->dropIndex(self::INDICE);
            });
        }

        if (Schema::hasColumn('article_discounts', 'provider_discount_id')) {

            Schema::table('article_discounts', function (Blueprint $table) {
                $table->dropColumn('provider_discount_id');
            });
        }

        if (Schema::hasColumn('article_discounts', 'nombre')) {

            Schema::table('article_discounts', function (Blueprint $table) {
                $table->dropColumn('nombre');
            });
        }
    }

    /**
     * ¿Ya existe el indice en la base conectada?
     *
     * @return bool
     */
    private function existe_el_indice()
    {
        if (!Schema::hasColumn('article_discounts', 'provider_discount_id')) {
            return false;
        }

        return count(DB::select(
            "SHOW INDEX FROM article_discounts WHERE Key_name = '" . self::INDICE . "'"
        )) > 0;
    }
}
