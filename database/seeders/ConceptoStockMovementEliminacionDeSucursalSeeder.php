<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Agrega el concepto de movimiento de stock "Eliminacion de sucursal" a bases de
 * producción existentes (tanda correctivos 2408, ítem 7).
 *
 * Lo usa AddressController::destroy() para dejar rastro del stock que tenía una sucursal
 * al eliminarla. En bases nuevas ya lo crea ConceptoStockMovementSeeder; este standalone
 * es la mitad obligatoria para producción (regla del CLAUDE.md del repo: seeder general +
 * seeder standalone).
 *
 * Idempotente y por NOMBRE, no por id: los ids de concepto_stock_movements varían entre
 * instalaciones (cada base corrió su seeder con su autoincrement) y el lookup en runtime
 * es por nombre (SetConcepto::get_concepto con concepto_stock_movement_name).
 */
class ConceptoStockMovementEliminacionDeSucursalSeeder extends Seeder
{
    /**
     * Inserta el concepto solo si no existe todavía.
     *
     * @return void
     */
    public function run()
    {
        /** Nombre exacto que busca AddressController::destroy(). */
        $nombre = 'Eliminacion de sucursal';

        /** Ya existe: no se duplica ni se toca (los conceptos son de solo lectura). */
        $ya_existe = DB::table('concepto_stock_movements')
                        ->where('name', $nombre)
                        ->exists();

        if ($ya_existe) {
            return;
        }

        /** Momento único para los timestamps del insert. */
        $now = Carbon::now();

        DB::table('concepto_stock_movements')->insert([
            'name'       => $nombre,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
