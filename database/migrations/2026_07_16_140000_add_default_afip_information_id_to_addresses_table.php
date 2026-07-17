<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega la columna default_afip_information_id a addresses.
 *
 * Objetivo: cada sucursal (address) puede tener asociado un afip_information
 * por defecto, usado para resolver la identidad fiscal (razón social, CUIT,
 * domicilio, etc.) en ventas en negro (remitos sin facturación especificada)
 * realizadas desde esa sucursal. No se define como foreign key física
 * (siguiendo el estilo del resto del schema, sin constraints de FK); la
 * relación se implementa en Eloquent (Address::default_afip_information()).
 */
class AddDefaultAfipInformationIdToAddressesTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna si todavía no existe (guard
     * hasColumn para que sea segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('addresses', 'default_afip_information_id')) {
            Schema::table('addresses', function (Blueprint $table) {
                /**
                 * afip_information por defecto para ventas en negro desde esta sucursal
                 * (cuando no se especifica facturacion). Nullable: si es null, se cae a user->afip_information.
                 */
                $table->integer('default_afip_information_id')->unsigned()->nullable()->after('id');
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
        if (Schema::hasColumn('addresses', 'default_afip_information_id')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->dropColumn('default_afip_information_id');
            });
        }
    }
}
