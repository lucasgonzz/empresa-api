<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class ClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $link_google_maps = 'https://www.google.com/maps/place/%C3%81ngel+Justiniano+Carranza+2038,+C1414+Cdad.+Aut%C3%B3noma+de+Buenos+Aires/@-34.5795941,-58.4380356,17z/data=!3m1!4b1!4m6!3m5!1s0x95bcb593c3e82309:0x9a790614083c577a!8m2!3d-34.5795985!4d-58.4354607!16s%2Fg%2F11c1796wqb?entry=ttu&g_ep=EgoyMDI1MDQwOC4wIKXMDSoASAFQAw%3D%3D';

        $models = [
            [

                'num'                   => 1,
                'name'                  => 'Lucas Gonzalez',
                'email'                 => 'lucasgonzalez5500@gmail.com',
                'address'               => 'San antonio 23 - Gualeguay, Entre Rios',
                'phone'                 => '3444622139',
                'cuit'                  => '20242112025',
                'dni'                   => 'd42354898d',
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => env('FOR_USER') == 'colman' ? 2 : null,
                'user_id'               => env('USER_ID'),
                'price_type_id'         => 2,
                'saldo'                 => null,
                'address_id'            => 1,
                'pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar'         => 0,
            ],
            [

                'num'                   => 2,
                'name'                  => 'Marcos Perez',
                'address'               => 'Martin Fierro 23 - Gualeguay, Entre Rios',
                'cuit'                  => '20242112025',
                'phone'                 => '3444622139',
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => env('FOR_USER') == 'colman' ? 3 : null,
                'price_type_id'         => 3,
                'user_id'               => env('USER_ID'),
                'comercio_city_user_id' => null,
            ],
            [

                'num'                   => 3,
                'name'                  => 'Sabrina Herrero',
                'address'               => 'Martin Fierro 23 - Gualeguay, Entre Rios',
                'phone'                 => '3444622139',
                'cuit'                  => '20242112025',
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => env('FOR_USER') == 'colman' ? 3 : null,
                'price_type_id'         => 3,
                'user_id'               => env('USER_ID'),
                'comercio_city_user_id' => null,
            ],
        ];
        foreach ($models as $model) {

            $model['link_google_maps'] = $link_google_maps;
            
            $client = Client::create($model);
            if (isset($model['id'])) {
                $client->id = $model['id'];
                $client->save();
            }
        }

    }
}
