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
                'image_url'     => 'https://concepto.de/wp-content/uploads/2015/03/paisaje-e1549600034372.jpg',
            ],
            [
                'text'  => 'Lista de precios Truvari Bebidas __fecha__',
                'color' => '0-0-0',
                'background'    => '255-255-255',
                'image_url'     => 'https://i.pinimg.com/736x/ac/53/c7/ac53c746c0b570df28f47c53f84657e6.jpg',                
            ],
            [
                'text'  => 'VENTA SOLO POR CAJA CERRADA (NO MIXTA) - LOS PRECIOS PUEDEN MODIFICARSE SIN PREVIO AVISO',
                'color' => '255-255-255',
                'background'    => '54-56-169',
            ],
            [
                'text'  => 'PEDIDOS AL WHATSAPP: 3513130437 ',
                'color' => '255-255-255',
                'background'    => '54-56-169',
            ],
            [
                'text'  => 'CONSULTAR STOCK - ENVIO A DOMICILIO $5000, SIN CARGO EN COMPRAS DE $60.000 o MAS',
                'color' => '227-28-28',
                'background'    => '255-255-255',
            ],
            [
                'text'  => 'La Calera, Intercountry y Autopista a Carlos Paz se cobra Peaje',
                'color' => '227-28-28',
                'background'    => '255-255-255',
            ],
        ];

        $position = 1;
        foreach ($models as $model) {
            
            $model['user_id'] = config('app.USER_ID');
            $model['position'] = $position;

            $position++;
            
            ArticlePdfObservation::create($model);
        }
    }
}
