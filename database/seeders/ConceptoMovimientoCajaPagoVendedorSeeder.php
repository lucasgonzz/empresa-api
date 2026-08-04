<?php

namespace Database\Seeders;

use App\Models\ConceptoMovimientoCaja;
use Illuminate\Database\Seeder;

/**
 * Grupo 268 · Prompt 01 — garantiza que exista el concepto de movimiento de caja "Pago a
 * Vendedor", usado por el egreso real de caja que genera el pago a un vendedor (Prompt 03).
 *
 * Obligatoriamente idempotente (firstOrCreate, nunca create() a secas): este seeder corre en
 * las 40+ bases de cliente y algunas pueden ya tener el concepto cargado.
 *
 * No hardcodea ningun id: el Prompt 03 resuelve el id por nombre en tiempo de ejecucion. Este
 * seeder solo garantiza que la fila exista.
 */
class ConceptoMovimientoCajaPagoVendedorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ConceptoMovimientoCaja::firstOrCreate([
            'name' => 'Pago a Vendedor',
        ]);
    }
}
