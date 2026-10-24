<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moneda de los planes de pago y de sus cuotas (misión planes-de-pago-saldo-y-dolares, 21/10/2026).
 *
 * Un plan de pago se arma sobre una venta, y la venta puede estar en pesos o en dólares: el débito
 * de una venta en dólares vive en la cuenta corriente USD del cliente con `debe = sale.total`
 * (CurrentAcountFromSaleHelper), así que el importe del plan y el de cada cuota ya estaban en
 * dólares — lo único que faltaba era decirlo. Sin esta columna la pantalla de cuotas (Clientes →
 * Planes de pago) y el mostrador las mostraban y sumaban como pesos.
 *
 * Default 1 (pesos), igual que `Sale::EXPRESION_EN_PESOS`: NULL, 0 y 1 son pesos, 2 es USD.
 *
 * El RELLENO de abajo marca con 2 los planes y cuotas que ya existen sobre ventas en dólares. Es
 * un UPDATE con JOIN por `sale_id`, sin recorrer filas en PHP: las tablas de planes son chicas en
 * todos los clientes, y si una venta se borró el plan queda en pesos (no hay de dónde leer otra
 * cosa). Sin foreign keys (convención del repo). Idempotente con `hasColumn`.
 */
class AddMonedaIdToPaymentPlansTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('payment_plans', 'moneda_id')) {
            Schema::table('payment_plans', function (Blueprint $table) {
                $table->unsignedInteger('moneda_id')->nullable()->default(1);
            });
        }

        if (!Schema::hasColumn('payment_plan_cuotas', 'moneda_id')) {
            Schema::table('payment_plan_cuotas', function (Blueprint $table) {
                $table->unsignedInteger('moneda_id')->nullable()->default(1);
            });
        }

        // Relleno: los planes y cuotas de ventas en dólares pasan a moneda 2.
        DB::statement('UPDATE payment_plans INNER JOIN sales ON sales.id = payment_plans.sale_id SET payment_plans.moneda_id = 2 WHERE sales.moneda_id = 2');
        DB::statement('UPDATE payment_plan_cuotas INNER JOIN sales ON sales.id = payment_plan_cuotas.sale_id SET payment_plan_cuotas.moneda_id = 2 WHERE sales.moneda_id = 2');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('payment_plan_cuotas', 'moneda_id')) {
            Schema::table('payment_plan_cuotas', function (Blueprint $table) {
                $table->dropColumn('moneda_id');
            });
        }

        if (Schema::hasColumn('payment_plans', 'moneda_id')) {
            Schema::table('payment_plans', function (Blueprint $table) {
                $table->dropColumn('moneda_id');
            });
        }
    }
}
