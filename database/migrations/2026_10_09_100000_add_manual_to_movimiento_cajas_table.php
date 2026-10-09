<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: la marca `manual` de cada movimiento de caja (misión movimientos-caja-manuales,
 * 9/10/2026).
 *
 * Hasta hoy no había forma de saber si un movimiento lo cargó una persona desde Tesorería →
 * Movimientos o lo generó el sistema (una venta, un gasto, un pago, una transferencia, una
 * compensación, el pago de una comisión): `MovimientoCajaHelper::crear_movimiento()` es el mismo
 * para todos, y los pagos de cuenta corriente ni siquiera guardan su `current_acount_id`. Con esta
 * columna, Lucas decidió (9/10/2026) que lo nuevo se decide por la marca y lo viejo por concepto.
 *
 * - `true`: lo cargó una persona (solo `MovimientoCajaController::store()` lo pide).
 * - `false`: lo generó el sistema (todo otro llamador de `crear_movimiento()`).
 * - NULL: fila anterior a esta columna. NO lleva default a propósito: un default `false` haría que
 *   todos los movimientos manuales viejos pasaran a "del sistema" y no se pudieran corregir más.
 *   Para esas filas decide `MovimientoCaja::origen_automatico()` por concepto.
 *
 * La base es compartida con `tienda`, pero `tienda-api` no escribe `movimiento_cajas`. Es aditiva
 * y NULL: ninguna fila existente cambia. Sin FK ni índice.
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos y una que ya la
 * tenga no puede tumbar la migración. El código que la escribe pregunta antes si existe
 * (`MovimientoCaja::hay_columna_manual()`), para la ventana del deploy en la que todavía no está.
 */
class AddManualToMovimientoCajasTable extends Migration
{
    /**
     * Agrega la columna, solo si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('movimiento_cajas', 'manual')) {

            Schema::table('movimiento_cajas', function (Blueprint $table) {
                // true = lo cargó una persona; false = lo generó el sistema; NULL = fila vieja.
                $table->boolean('manual')->nullable()->after('concepto_movimiento_caja_id');
            });
        }
    }

    /**
     * Saca la columna, solo si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('movimiento_cajas', 'manual')) {

            Schema::table('movimiento_cajas', function (Blueprint $table) {
                $table->dropColumn('manual');
            });
        }
    }
}
