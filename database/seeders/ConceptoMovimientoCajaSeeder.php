<?php

namespace Database\Seeders;

use App\Models\ConceptoMovimientoCaja;
use Illuminate\Database\Seeder;

class ConceptoMovimientoCajaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'name'  => 'Venta',
            ],  
            [
                'name'  => 'Gasto',
            ],  
            [
                'name'  => 'Pago de Cliente',
            ],  
            [
                'name'  => 'Pago a Proveedor',
            ],  
            [
                'name'  => 'Movimiento entre Cajas',
            ],  
            [
                'name'  => 'Varios',
            ],  
        ];

        foreach ($models as $model) {
            
            ConceptoMovimientoCaja::create($model);
        }
    }
}
