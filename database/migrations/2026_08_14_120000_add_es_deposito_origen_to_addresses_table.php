<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega la columna es_deposito_origen a addresses.
 *
 * Marca una sucursal como depósito de origen preferente para las sugerencias de
 * traslado de stock (v2 del módulo): cuando el comercio designa uno o más
 * depósitos, obtenerOrigen() los prefiere como origen de la reposición antes que
 * cualquier otra sucursal.
 *
 * Por qué NO se llama is_central: el código viejo leía `$address->is_central`,
 * pero esa columna nunca existió en `addresses` — la rama estaba muerta. Se
 * reemplaza con un nombre que dice lo que hace (origen preferente de
 * reposición) y que admite VARIAS sucursales marcadas, no una sola "central".
 *
 * Por qué no reusa default_address: esa columna ya significa otra cosa
 * ("depósito por defecto" preselecciona el DESTINO en el form de movimientos de
 * stock, ver empresa-spa stock-movement/Form.vue), y mezclar los dos conceptos
 * haría que marcar el depósito de origen cambiara el destino sugerido.
 *
 * Sin foreign key física, siguiendo el estilo del resto del schema.
 */
class AddEsDepositoOrigenToAddressesTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna si todavía no existe (guard
     * hasColumn para que sea segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('addresses', 'es_deposito_origen')) {
            Schema::table('addresses', function (Blueprint $table) {
                /**
                 * Default 0: ninguna sucursal existente queda designada sola.
                 * Sin designados, el cálculo se comporta exactamente como hoy.
                 */
                $table->boolean('es_deposito_origen')->default(0);
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
        if (Schema::hasColumn('addresses', 'es_deposito_origen')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->dropColumn('es_deposito_origen');
            });
        }
    }
}
