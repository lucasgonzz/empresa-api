<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Prompt 516 (Fase 2 — fix IVA factura de compra + costeo neto, grupo 124).
 *
 * Agrega a `provider_order_afip_tickets` los campos `emisor_cuit` y `emisor_razon_social`.
 *
 * Contexto: un `provider_order` puede generar más de un comprobante de compra. Hasta ahora
 * todos se atribuían al proveedor de la orden (`provider_order->provider`). Con el prompt 516,
 * un costo extra `facturado = true` con `en_factura_compra = false` (ej. flete tercerizado)
 * genera un comprobante APARTE, emitido por un tercero distinto del proveedor (la empresa de
 * transporte, con su propio CUIT) — ese emisor se guarda acá.
 *
 * Si `emisor_cuit`/`emisor_razon_social` vienen NULL (comportamiento actual/default para
 * comprobantes existentes y para la factura principal de la compra), el comprobante se sigue
 * atribuyendo al proveedor de la orden como hasta ahora (ver
 * `AfipController::iva_compras_pdf`, que ahora prioriza estos campos si están cargados).
 *
 * Sin FK (regla del proyecto: no `foreign()`/`constrained()` en migraciones nuevas). Longitudes
 * acotadas, mismo criterio ya usado en `provider_order_extra_costs.emisor_cuit`/
 * `emisor_razon_social` (prompt 513).
 */
class AddEmisorFieldsToProviderOrderAfipTicketsTable extends Migration
{
    /**
     * Ejecuta la migración: agrega `emisor_cuit` y `emisor_razon_social` a
     * `provider_order_afip_tickets`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('provider_order_afip_tickets', function (Blueprint $table) {
            // CUIT del emisor real del comprobante, cuando es distinto del proveedor de la
            // orden (ej. transportista tercerizado). Nullable: la mayoría de los comprobantes
            // siguen atribuyéndose al proveedor de la orden.
            $table->string('emisor_cuit', 20)->nullable()->after('provider_order_id');

            // Razón social del emisor real del comprobante (mismo caso que emisor_cuit).
            $table->string('emisor_razon_social', 120)->nullable()->after('emisor_cuit');
        });
    }

    /**
     * Revierte la migración: elimina las columnas agregadas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('provider_order_afip_tickets', function (Blueprint $table) {
            $table->dropColumn(['emisor_cuit', 'emisor_razon_social']);
        });
    }
}
