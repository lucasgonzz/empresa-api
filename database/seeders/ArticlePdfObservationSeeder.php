<?php

namespace Database\Seeders;

use App\Models\ArticlePdfObservation;
use Illuminate\Database\Seeder;

class ArticlePdfObservationSeeder extends Seeder
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
                'text'  => 'VENTA SOLO POR CAJA CERRADA (NO MIXTA) - LOS PRECIOS PUEDEN MODIFICARSE SIN PREVIO AVISO',
                'color' => '255-255-255',
                'background'    => '54-56-169',
                'position'      => 1,
            ],
            [
                'text'  => 'PEDIDOS AL WHATSAPP: 3513130437 ',
                'color' => '255-255-255',
                'background'    => '54-56-169',
                'position'      => 2,
            ],
            [
                'text'  => 'CONSULTAR STOCK - ENVIO A DOMICILIO $5000, SIN CARGO EN COMPRAS DE $60.000 o MAS',
                'color' => '227-28-28',
                'background'    => '255-255-255',
                'position'      => 3,
            ],
            [
                'text'  => 'La Calera, Intercountry y Autopista a Carlos Paz se cobra Peaje',
                'color' => '227-28-28',
                'background'    => '255-255-255',
                'position'      => 4,
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = env('USER_ID');
            ArticlePdfObservation::create($model);
        }
    }
}
