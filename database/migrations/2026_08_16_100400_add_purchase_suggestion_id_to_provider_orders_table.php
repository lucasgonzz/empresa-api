<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: trazabilidad orden↔sugerencia en provider_orders.
 *
 * purchase_suggestion_id: si esta orden se generó desde el botón "Generar
 * orden de compra" de una sugerencia, acá queda el id de esa sugerencia.
 * Nullable porque la enorme mayoría de las órdenes se siguen cargando a mano
 * como siempre: esta columna es puramente aditiva, ninguna orden existente
 * cambia de significado.
 *
 * Además de trazabilidad, esta columna es la que usa la retención del
 * comando compras:generar para NO borrar una sugerencia automática que ya
 * generó una orden real (whereDoesntHave('provider_orders')): perder esa
 * sugerencia sería perder la explicación de por qué existe la orden.
 *
 * Sin foreign key física, siguiendo el estilo del resto del schema.
 */
class AddPurchaseSuggestionIdToProviderOrdersTable extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna solo si no existe (guard
     * hasColumn para que sea segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('provider_orders', 'purchase_suggestion_id')) {
            Schema::table('provider_orders', function (Blueprint $table) {
                $table->integer('purchase_suggestion_id')->nullable();
            });
        }
    }

    /**
     * Revierte la migración: elimina la columna solo si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('provider_orders', 'purchase_suggestion_id')) {
            Schema::table('provider_orders', function (Blueprint $table) {
                $table->dropColumn('purchase_suggestion_id');
            });
        }
    }
}
