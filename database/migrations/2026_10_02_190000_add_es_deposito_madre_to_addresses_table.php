<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega la columna es_deposito_madre a addresses (misión deposito-madre, 2/10/2026).
 *
 * Marca LA sucursal desde la que salen primero las sugerencias de traslado de stock: con un
 * depósito madre, StockSuggestionService reparte desde el madre hacia las sucursales en déficit
 * (priorizando a las que más venden) y recién lo que el madre no cubre lo completa desde otras
 * sucursales a las que les sobra.
 *
 * Por qué una columna nueva y no reusar es_deposito_origen: esa admite VARIAS sucursales y
 * significa "origen preferente"; el madre es UNO solo y además manda sobre los designados. Con
 * madre, es_deposito_origen pasa a ordenar solo los orígenes de respaldo. La unicidad (una sola
 * por comercio) la garantiza el hook `saved` de App\Models\Address, no la base: un índice único
 * no sirve porque todas las demás filas tienen el mismo valor 0.
 *
 * 🔴 `addresses` la escribe TAMBIÉN tienda-api (domicilios de compradores, con buyer_id), que
 * comparte la base: por eso NOT NULL con default 0, para que tienda no tenga que conocerla.
 *
 * Sin foreign key física, siguiendo el estilo del resto del schema.
 */
class AddEsDepositoMadreToAddressesTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna si todavía no existe (guard hasColumn para que sea
     * segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('addresses', 'es_deposito_madre')) {
            Schema::table('addresses', function (Blueprint $table) {
                /**
                 * Default 0: ninguna sucursal existente queda como madre sola. Sin madre, las
                 * sugerencias se calculan exactamente como hasta hoy.
                 */
                $table->boolean('es_deposito_madre')->default(0)->after('es_deposito_origen');
            });
        }
    }

    /**
     * Revierte la migración: elimina la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('addresses', 'es_deposito_madre')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->dropColumn('es_deposito_madre');
            });
        }
    }
}
