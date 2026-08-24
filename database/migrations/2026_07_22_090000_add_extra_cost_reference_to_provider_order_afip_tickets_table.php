<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Prompt 610 (Grupo 183 — IVA por condición de contribuyente).
 *
 * Agrega `provider_order_extra_cost_id` a `provider_order_afip_tickets`: referencia (sin FK,
 * regla del proyecto) al `provider_order_extra_cost` que originó automáticamente este
 * comprobante "aparte" (costo extra facturado con `en_factura_compra = false`, ej. flete
 * tercerizado facturado por otro emisor).
 *
 * Racional (idempotencia): antes del 610, cada vez que se guardaba/confirmaba la compra se
 * borraban TODOS los comprobantes menos el primero y se recreaban desde cero (ver
 * `ModoFacturacionHelper::calcular_iva()`), lo que hacía perder el número de comprobante/fecha
 * que el usuario ya le hubiera cargado a mano al comprobante aparte. Con esta columna,
 * `ModoFacturacionHelper` puede buscar "¿ya existe el comprobante de ESTE costo extra?" antes de
 * crear uno nuevo, y actualizar el existente en vez de duplicarlo.
 *
 * `NULL` (default) = comprobante principal de la compra o comprobante cargado a mano por el
 * usuario (comportamiento actual, sin cambios). Solo se completa en los comprobantes "aparte"
 * generados automáticamente por un costo extra.
 */
class AddExtraCostReferenceToProviderOrderAfipTicketsTable extends Migration
{
    /**
     * Ejecuta la migración: agrega `provider_order_extra_cost_id` (sin FK) e índice por prefijo.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('provider_order_afip_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_order_extra_cost_id')->nullable()->after('provider_order_id');
            $table->index('provider_order_extra_cost_id', 'poat_extra_cost_idx');
        });
    }

    /**
     * Revierte la migración: elimina el índice y la columna agregada.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('provider_order_afip_tickets', function (Blueprint $table) {
            $table->dropIndex('poat_extra_cost_idx');
            $table->dropColumn('provider_order_extra_cost_id');
        });
    }
}
