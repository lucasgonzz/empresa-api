<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Prompt 513 (Fase 2 — fix IVA factura de compra + costeo neto, base del grupo 124).
 *
 * Agrega `precios_incluyen_iva` a `provider_orders`: indica si los precios cargados en esa
 * compra puntual ya vienen con el IVA incluido por parte del proveedor. Es puramente aditiva
 * y retrocompatible: default `false` = comportamiento actual (el precio se interpreta como
 * neto y se le suma el IVA para armar la factura). Los ~40 clientes en producción no cambian
 * de comportamiento con esta migración; la lógica que lee este flag se implementa en el
 * prompt 514, no acá.
 */
class AddPreciosIncluyenIvaToProviderOrdersTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna `precios_incluyen_iva` (boolean, default false)
     * a `provider_orders`, después de `modo_facturacion` (columna relacionada con la
     * facturación de la orden).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('provider_orders', function (Blueprint $table) {
            // Si true, los precios cargados en esta orden ya incluyen IVA (los carga el
            // proveedor así). Si false (default = comportamiento actual), los precios son
            // netos y se les suma el IVA correspondiente al armar la factura de compra.
            $table->boolean('precios_incluyen_iva')->default(false)->after('modo_facturacion');
        });
    }

    /**
     * Revierte la migración: elimina la columna `precios_incluyen_iva`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('provider_orders', function (Blueprint $table) {
            $table->dropColumn('precios_incluyen_iva');
        });
    }
}
