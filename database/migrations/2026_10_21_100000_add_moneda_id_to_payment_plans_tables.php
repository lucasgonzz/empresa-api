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
 * un UPDATE con JOIN por `sale_id` del plan, sin recorrer filas en PHP: las tablas de planes son chicas en
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

        // Antes de rellenar, frenar si los dos vínculos resuelven ventas distintas.
        if (Schema::hasColumn('payment_plan_cuotas', 'payment_plan_id') && Schema::hasColumn('payment_plan_cuotas', 'sale_id')) {
            $conflictos = DB::selectOne('SELECT COUNT(*) AS total FROM payment_plan_cuotas c INNER JOIN payment_plans p ON p.id = c.payment_plan_id INNER JOIN sales sp ON sp.id = p.sale_id INNER JOIN sales sd ON sd.id = c.sale_id WHERE sp.id <> sd.id');
            if ((int) $conflictos->total > 0) {
                throw new RuntimeException('Hay cuotas con ventas distintas en el plan y el vínculo directo; revisar antes de migrar.');
            }
        }

        // Relleno: los planes y cuotas de ventas en dólares pasan a moneda 2.
        DB::statement('UPDATE payment_plans INNER JOIN sales ON sales.id = payment_plans.sale_id SET payment_plans.moneda_id = 2 WHERE sales.moneda_id = 2');
        // Algunas instalaciones históricas vinculan las cuotas a la venta solo a través del plan.
        if (Schema::hasColumn('payment_plan_cuotas', 'payment_plan_id')) {
            DB::statement('UPDATE payment_plan_cuotas INNER JOIN payment_plans ON payment_plans.id = payment_plan_cuotas.payment_plan_id INNER JOIN sales ON sales.id = payment_plans.sale_id SET payment_plan_cuotas.moneda_id = 2 WHERE sales.moneda_id = 2');
            // Si el plan no resuelve una venta, usar el vínculo directo cuando exista.
            if (Schema::hasColumn('payment_plan_cuotas', 'sale_id')) {
                DB::statement('UPDATE payment_plan_cuotas c LEFT JOIN payment_plans p ON p.id = c.payment_plan_id LEFT JOIN sales sp ON sp.id = p.sale_id INNER JOIN sales sd ON sd.id = c.sale_id SET c.moneda_id = 2 WHERE sp.id IS NULL AND sd.moneda_id = 2');
            }
        } elseif (Schema::hasColumn('payment_plan_cuotas', 'sale_id')) {
            DB::statement('UPDATE payment_plan_cuotas INNER JOIN sales ON sales.id = payment_plan_cuotas.sale_id SET payment_plan_cuotas.moneda_id = 2 WHERE sales.moneda_id = 2');
        } else {
            throw new RuntimeException('Las cuotas no tienen vínculo con su plan ni con la venta.');
        }
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
