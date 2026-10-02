<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega a `addresses` el ajuste de precios de la sucursal (misión
 * sucursal-recargo-descuento, 2/10/2026).
 *
 * Una sucursal puede llevar un recargo o un descuento en porcentaje. Cuando el
 * vendedor elige esa sucursal en Vender, la SPA lo mete ADENTRO del precio de cada
 * artículo, combo y promoción (no se suma ni se resta al total de la venta). La API
 * solo persiste el dato: el cálculo del precio lo hace la SPA y la API guarda lo que
 * la SPA manda en cada renglón.
 *
 * Las dos columnas son un par con una invariante:
 *
 *  - ajuste_precio_tipo       VARCHAR(10) NULL: 'recargo', 'descuento' o NULL (sin ajuste).
 *  - ajuste_precio_porcentaje DECIMAL(8,2) NULL: mayor que 0. Un descuento tiene que ser
 *                             menor que 100; un recargo, a lo sumo 999.99. NULL si no hay ajuste.
 *
 * O las dos tienen un valor válido, o las dos son NULL. `AjusteDePreciosDeSucursalHelper`
 * es quien lo garantiza al escribir; la SPA trata cualquier otra combinación como "sin
 * ajuste".
 *
 * Por qué NULL por defecto: ninguna sucursal existente cambia de comportamiento. Sin
 * ajuste cargado, Vender funciona exactamente como hoy.
 *
 * Por qué VARCHAR y no ENUM: un ENUM obliga a un ALTER cada vez que se suma un tipo, y
 * el estilo del resto del schema es texto + validación en el Helper.
 *
 * Sin foreign key física, siguiendo el estilo del resto del schema. `tienda-api`
 * comparte esta tabla pero no lee ni escribe estas columnas: una columna nueva nula es
 * inocua para ella.
 */
class AddAjusteDePreciosToAddressesTable extends Migration
{
    /**
     * Ejecuta la migración: agrega cada columna si todavía no existe (guard
     * hasTable/hasColumn por columna, para que sea segura de re-ejecutar y de correr
     * sobre una base a medio migrar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('addresses')) {
            return;
        }

        if (! Schema::hasColumn('addresses', 'ajuste_precio_tipo')) {
            Schema::table('addresses', function (Blueprint $table) {
                /** 'recargo' | 'descuento' | NULL (sin ajuste). */
                $table->string('ajuste_precio_tipo', 10)->nullable();
            });
        }

        if (! Schema::hasColumn('addresses', 'ajuste_precio_porcentaje')) {
            Schema::table('addresses', function (Blueprint $table) {
                /** Porcentaje del ajuste, con dos decimales. NULL si no hay ajuste. */
                $table->decimal('ajuste_precio_porcentaje', 8, 2)->nullable();
            });
        }
    }

    /**
     * Revierte la migración: elimina cada columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('addresses')) {
            return;
        }

        if (Schema::hasColumn('addresses', 'ajuste_precio_porcentaje')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->dropColumn('ajuste_precio_porcentaje');
            });
        }

        if (Schema::hasColumn('addresses', 'ajuste_precio_tipo')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->dropColumn('ajuste_precio_tipo');
            });
        }
    }
}
