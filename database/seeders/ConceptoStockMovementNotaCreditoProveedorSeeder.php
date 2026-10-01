<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Agrega el concepto de movimiento de stock "Nota de credito proveedor" a bases de producción
 * existentes (misión devoluciones-compras-y-rediseno, 1/10/2026).
 *
 * Lo usa NotaCreditoProveedorHelper para el movimiento con el que una devolución de compra SACA del
 * stock lo que se le devuelve al proveedor. Además de etiquetar el movimiento, es lo que lee
 * ValidarDevolucionCompraHelper para saber cuánto de una compra ya se devolvió (el tope por libro):
 * sin el concepto, esos movimientos quedarían sin etiqueta y el tope dejaría de ver lo devuelto.
 *
 * En bases nuevas ya lo crea ConceptoStockMovementSeeder; este standalone es la mitad obligatoria
 * para producción (regla del CLAUDE.md del repo: seeder general + seeder standalone).
 *
 * Idempotente y por NOMBRE, no por id: los ids de concepto_stock_movements varían entre
 * instalaciones (cada base corrió su seeder con su autoincrement) y el lookup en runtime es por
 * nombre (SetConcepto::get_concepto con concepto_stock_movement_name).
 */
class ConceptoStockMovementNotaCreditoProveedorSeeder extends Seeder
{
    /**
     * Inserta el concepto solo si no existe todavía.
     *
     * @return void
     */
    public function run()
    {
        /** Nombre exacto que busca NotaCreditoProveedorHelper. */
        $nombre = 'Nota de credito proveedor';

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
