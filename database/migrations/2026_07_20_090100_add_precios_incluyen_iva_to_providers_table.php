<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Prompt 513 (Fase 2 — fix IVA factura de compra + costeo neto, base del grupo 124).
 *
 * Agrega `precios_incluyen_iva` a `providers`: default a nivel proveedor que pre-completa el
 * flag homónimo de `provider_orders` al elegir ese proveedor en una nueva orden (la convención
 * de facturación suele ser estable por proveedor). El flag de la orden siempre manda sobre
 * este; este solo pre-carga un valor inicial. Aditiva y retrocompatible: default `false` no
 * cambia el comportamiento actual de ningún proveedor existente.
 */
class AddPreciosIncluyenIvaToProvidersTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna `precios_incluyen_iva` (boolean, default false)
     * a `providers`, después de `price_from_cost_mas_iva` (columna relacionada con IVA/costo).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('providers', function (Blueprint $table) {
            // Default del proveedor: si true, al crear una nueva orden de compra a este
            // proveedor se pre-carga `precios_incluyen_iva = true` en la orden (el operador
            // puede cambiarlo igual). Default false = comportamiento actual.
            $table->boolean('precios_incluyen_iva')->default(false)->after('price_from_cost_mas_iva');
        });
    }

    /**
     * Revierte la migración: elimina la columna `precios_incluyen_iva`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('precios_incluyen_iva');
        });
    }
}
