<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Prompt 513 (Fase 2 — fix IVA factura de compra + costeo neto, base del grupo 124).
 *
 * Agrega a `provider_order_extra_costs` (los costos extra de una orden de compra: flete,
 * seguro, etc.) los campos necesarios para saber si cada costo extra vino facturado, con qué
 * alícuota, y si esa factura es la misma de la compra o una aparte (caso típico: flete
 * tercerizado por otra empresa de transporte, facturado por separado).
 *
 * Nota sobre `iva_id`: NO se agrega como foreign key (regla del proyecto: sin `foreign()`/
 * `constrained()` en migraciones nuevas). Se agrega como `unsignedBigInteger` nullable simple,
 * mismo criterio ya usado en `provider_order_afip_ticket_ivas.iva_id`; la relación lógica con
 * `ivas` se resuelve en Eloquent.
 *
 * Todos los defaults preservan el comportamiento actual (aditiva y retrocompatible): un costo
 * extra existente queda con `facturado = false`, `en_factura_compra = true`, `iva_id = null`.
 * No hay lógica de negocio en este prompt (eso es 514-516); solo esquema.
 */
class AddFacturacionFieldsToProviderOrderExtraCostsTable extends Migration
{
    /**
     * Ejecuta la migración: agrega `facturado`, `iva_id`, `en_factura_compra`, `emisor_cuit` y
     * `emisor_razon_social` a `provider_order_extra_costs`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('provider_order_extra_costs', function (Blueprint $table) {
            // Si el costo extra vino facturado (genera IVA crédito fiscal) o no.
            // Default false = comportamiento actual (no se considera facturado).
            $table->boolean('facturado')->default(false)->after('tipo');

            // Alícuota de IVA con la que se facturó el costo extra (solo aplica si
            // `facturado` = true). Sin FK (regla del proyecto); nullable porque la mayoría de
            // los costos extra existentes no tienen alícuota cargada.
            $table->unsignedBigInteger('iva_id')->nullable()->after('facturado');

            // Si el costo va dentro de la misma factura de la compra (true, comportamiento
            // actual/default) o en una factura aparte emitida por otro emisor (false; ej.
            // flete tercerizado facturado por la empresa de transporte).
            $table->boolean('en_factura_compra')->default(true)->after('iva_id');

            // CUIT del emisor de la factura aparte (solo aplica cuando `en_factura_compra` =
            // false y `facturado` = true). Longitud acotada por convención del proyecto para
            // columnas string.
            $table->string('emisor_cuit', 20)->nullable()->after('en_factura_compra');

            // Razón social del emisor de la factura aparte (mismo caso que `emisor_cuit`).
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
        Schema::table('provider_order_extra_costs', function (Blueprint $table) {
            $table->dropColumn(['facturado', 'iva_id', 'en_factura_compra', 'emisor_cuit', 'emisor_razon_social']);
        });
    }
}
