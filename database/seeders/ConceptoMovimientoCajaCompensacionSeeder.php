<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Agrega conceptos de movimiento de caja usados al compensar caja al eliminar ventas, gastos o pagos en cuenta corriente.
 *
 * Los IDs 7–10 se reservan para no colisionar con los conceptos base (1–6) del ConceptoMovimientoCajaSeeder.
 */
class ConceptoMovimientoCajaCompensacionSeeder extends Seeder
{
    /**
     * Inserta o actualiza filas en `concepto_movimiento_cajas` de forma idempotente.
     *
     * @return void
     */
    public function run()
    {
        /** Definición de conceptos: id fijo => nombre en español para UI y reportes. */
        $rows = [
            7  => 'Eliminación de Venta',
            8  => 'Eliminación de Gasto',
            9  => 'Eliminación de Pago de Cliente',
            10 => 'Eliminación de Pago a Proveedor',
        ];

        /** Momento único para timestamps en este batch. */
        $now = Carbon::now();

        foreach ($rows as $id => $name) {
            DB::table('concepto_movimiento_cajas')->updateOrInsert(
                ['id' => $id],
                [
                    'name'       => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
