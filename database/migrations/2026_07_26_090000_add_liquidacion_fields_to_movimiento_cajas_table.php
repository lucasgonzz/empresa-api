<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Grupo 223 · Prompt 02 (tesorería — lógica de liquidación y comisión, Capa 2).
 *
 * Agrega a `movimiento_cajas` los campos calculados por `CajaLiquidacionHelper` para que cada
 * ingreso de cobro sepa cuándo va a estar disponible y cuánto se descontó de comisión.
 *
 * Todas las columnas son nullable a propósito: los movimientos históricos y los que no aplican
 * liquidación (ver flag `aplica_liquidacion` en `MovimientoCajaHelper::crear_movimiento()`) quedan
 * exactamente como están hoy.
 *
 * 🔴 La columna se llama `comision_expense_id`, NO `expense_id`: esa columna ya existe en esta
 * tabla y tiene otro significado (el `Expense` que originó un movimiento de egreso, usado por
 * `ExpenseCajaHelper::editar_movimiento_caja()`). Reusarla corrompería los movimientos de venta.
 *
 * Sin foreign keys (regla del workspace): `comision_expense_id` se resuelve por Eloquent.
 */
class AddLiquidacionFieldsToMovimientoCajasTable extends Migration
{
    /**
     * Ejecuta la migración: suma las columnas de liquidación/comisión a `movimiento_cajas`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('movimiento_cajas', function (Blueprint $table) {
            // Fecha en la que se estima que el ingreso está disponible (fecha del movimiento + días
            // de liquidación resueltos por la cascada). null = liquidación inmediata / no aplica.
            $table->date('fecha_liquidacion_estimada')->nullable()->after('saldo');

            // Monto neto estimado del movimiento (ingreso o egreso, ver tarea 06 del prompt) ya
            // descontada la comisión. Misma precisión que `ingreso`/`egreso` (decimal 20,2).
            $table->decimal('monto_neto_estimado', 20, 2)->nullable()->after('fecha_liquidacion_estimada');

            // Comisión calculada sobre el monto bruto de este movimiento. null/0 = sin comisión.
            $table->decimal('comision_calculada', 20, 2)->nullable()->after('monto_neto_estimado');

            // Id del Expense (gasto automático) generado por la comisión de este movimiento.
            // Distinta de `expense_id` (que ya existe y apunta al gasto que ORIGINÓ un egreso).
            $table->unsignedBigInteger('comision_expense_id')->nullable()->after('comision_calculada');
            $table->index('comision_expense_id', 'mvc_comision_expense_idx');
        });
    }

    /**
     * Revierte la migración: elimina las columnas agregadas a `movimiento_cajas`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('movimiento_cajas', function (Blueprint $table) {
            $table->dropIndex('mvc_comision_expense_idx');
            $table->dropColumn([
                'fecha_liquidacion_estimada',
                'monto_neto_estimado',
                'comision_calculada',
                'comision_expense_id',
            ]);
        });
    }
}
