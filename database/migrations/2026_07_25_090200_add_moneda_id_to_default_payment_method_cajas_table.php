<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migración Grupo 223 · Prompt 01 (tesorería — liquidación y comisiones, Capa 1).
 *
 * Agrega `moneda_id` a `default_payment_method_cajas`. Hoy la caja por defecto de un método
 * de pago se resolvía implícitamente en pesos; con la extensión de ventas en dólares hace
 * falta resolverla también por moneda. Se hace backfill de las filas existentes con
 * `moneda_id = 1` (pesos) en un único `update` masivo, para no romper el comportamiento
 * actual de ningún negocio ya configurado.
 *
 * Sin foreign key (regla del workspace): `moneda_id` se resuelve en Eloquent contra `monedas`.
 */
class AddMonedaIdToDefaultPaymentMethodCajasTable extends Migration
{
    /**
     * Ejecuta la migración: agrega `moneda_id` y hace el backfill a 1 (pesos) en un solo update.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('default_payment_method_cajas', function (Blueprint $table) {
            $table->unsignedBigInteger('moneda_id')->nullable()->after('current_acount_payment_method_id');
            $table->index('moneda_id', 'dpmc_moneda_idx');
        });

        // Backfill masivo: todas las filas existentes quedan en pesos (moneda_id = 1),
        // que es la moneda implícita con la que se venía resolviendo la caja por defecto.
        DB::table('default_payment_method_cajas')->update(['moneda_id' => 1]);
    }

    /**
     * Revierte la migración: elimina `moneda_id` de `default_payment_method_cajas`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('default_payment_method_cajas', function (Blueprint $table) {
            $table->dropIndex('dpmc_moneda_idx');
            $table->dropColumn('moneda_id');
        });
    }
}
