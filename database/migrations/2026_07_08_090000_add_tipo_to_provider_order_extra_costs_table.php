<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Prompt 264 (Fase 2 — precios/costos: flete prorrateado).
 *
 * Agrega la columna `tipo` a `provider_order_extra_costs` para que el usuario clasifique cada
 * costo extra de la orden de compra (flete, seguro, arancel de importación, u otro). Mismo
 * enum que `article_surchages.tipo` (prompt 260), para que el helper de la orden de compra
 * sepa a qué tipo de recargo de artículo mapear cada costo extra al prorratearlo.
 *
 * Es aditiva: columna nullable, sin backfill. NULL (o 'otro') mantiene el comportamiento
 * retrocompatible actual: el costo extra solo suma al total de la orden, sin materializarse
 * como recargo de artículo (ver NewProviderOrderHelper::aplicar_costos_extra_a_recargos_articulos()).
 */
class AddTipoToProviderOrderExtraCostsTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna `tipo` (nullable, sin backfill) a
     * provider_order_extra_costs.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('provider_order_extra_costs', function (Blueprint $table) {
            // Naturaleza contable del costo extra (transporte, seguro, arancel_importacion, otro).
            // NULL = comportamiento actual (no se materializa como recargo de artículo).
            $table->string('tipo', 60)->nullable()->after('value');
        });
    }

    /**
     * Revierte la migración: elimina la columna `tipo`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('provider_order_extra_costs', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
}
