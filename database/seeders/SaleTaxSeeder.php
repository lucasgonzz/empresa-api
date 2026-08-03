<?php

namespace Database\Seeders;

use App\Models\SaleTax;
use Illuminate\Database\Seeder;

/**
 * Impuesto sobre ventas de la semilla de datos de prueba (grupo 321).
 *
 * Sin ningún `sale_tax` activo, ContabilidadRepository::iibb_determinado() devuelve 0.0 de
 * entrada, así que la línea de IIBB del Estado de Resultados y de la Posición Fiscal quedaba
 * siempre en cero. 3% es la alícuota típica de IIBB comercio minorista y deja el cálculo
 * mental redondo: el impuesto queda en exactamente el 3% de las ventas.
 */
class SaleTaxSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        SaleTax::create([
            'name'          => 'Ingresos Brutos',
            'percentage'    => 3,
            'apply_to_all'  => true,
            'activo'        => 1,
            'user_id'       => config('app.USER_ID'),
        ]);
    }
}
