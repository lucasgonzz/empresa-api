<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CreditAccountHelper;
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
        Log::info('ClientSeeder');
        $link_google_maps = 'https://www.google.com/maps/place/%C3%81ngel+Justiniano+Carranza+2038,+C1414+Cdad.+Aut%C3%B3noma+de+Buenos+Aires/@-34.5795941,-58.4380356,17z/data=!3m1!4b1!4m6!3m5!1s0x95bcb593c3e82309:0x9a790614083c577a!8m2!3d-34.5795985!4d-58.4354607!16s%2Fg%2F11c1796wqb?entry=ttu&g_ep=EgoyMDI1MDQwOC4wIKXMDSoASAFQAw%3D%3D';

        $models = [
            [

                'num'                   => 1,
                'name'                  => 'Lucas Gonzalez',
                'email'                 => 'lucasgonzalez5500@gmail.com',
                'pais_exportacion_id'   => 3,
                'address'               => 'San antonio 23 - Gualeguay, Entre Rios',
                'phone'                 => '3444622139',
                'cuit'                  => '20242112025',
                'dni'                   => 'd42354898d',
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => config('app.FOR_USER') == 'colman' ? 2 : null,
                'user_id'               => config('app.USER_ID'),
                'price_type_id'         => 2,
                'saldo'                 => null,
                'address_id'            => 1,
                'pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar'         => 0,
            ],
            [

                'num'                   => 2,
                'name'                  => 'Marcos Perez',
                'address'               => 'Martin Fierro 23 - Gualeguay, Entre Rios',
                'email'                 => 'lucasgonzalez210200@gmail.com',
                'cuit'                  => '20242112025',
                'phone'                 => '3444622139',
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => config('app.FOR_USER') == 'colman' ? 3 : null,
                'price_type_id'         => 3,
                'user_id'               => config('app.USER_ID'),
                'comercio_city_user_id' => null,
            ],
            [

                'num'                   => 3,
                'name'                  => 'Sabrina Herrero',
                'address'               => 'Juan Bosco 009 - Victoria, Entre Rios',
                'phone'                 => '3444622139',
                'cuit'                  => '20242112025',
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => config('app.FOR_USER') == 'colman' ? 3 : null,
                'price_type_id'         => 3,
                'user_id'               => config('app.USER_ID'),
                'comercio_city_user_id' => null,
            ],
            [

                'num'                   => 4,
                'name'                  => 'Brisa Fiorotto',
                'address'               => 'Juan Bosco 009 - Victoria, Entre Rios',
                'phone'                 => '3444622139',
                'cuit'                  => '20242112025',
                'razon_social'          => 'MARCOS SRL',
                'iva_condition_id'      => 1,
                'seller_id'             => config('app.FOR_USER') == 'colman' ? 3 : null,
                'price_type_id'         => 3,
                'user_id'               => config('app.USER_ID'),
                'comercio_city_user_id' => null,
            ],
            // Clientes 5 a 10 agregados para la semilla de datos de prueba de reportes (grupo
            // 321): 4 sucursales, mezcla de listas de precio (incluido null) y de condición de
            // IVA, para que los filtros del Estado de Resultados y de la Posición Fiscal tengan
            // con qué. `saldo` en null: lo produce el sembrador con movimientos reales.
            [

                'num'                   => 5,
                'name'                  => 'Ferreteria El Tornillo',
                'address'               => 'Av. San Martin 450 - Tucuman',
                'phone'                 => '3814500001',
                'cuit'                  => '30712345671',
                'razon_social'          => 'FERRETERIA EL TORNILLO SRL',
                'iva_condition_id'      => 1,
                'price_type_id'         => 2,
                'user_id'               => config('app.USER_ID'),
                'saldo'                 => null,
                'address_id'            => 1,
            ],
            [

                'num'                   => 6,
                'name'                  => 'Corralon San Martin',
                'address'               => 'Bv. Pellegrini 1200 - Santa Fe',
                'phone'                 => '3425500002',
                'cuit'                  => '30712345672',
                'razon_social'          => 'CORRALON SAN MARTIN SA',
                'iva_condition_id'      => 2,
                'price_type_id'         => 3,
                'user_id'               => config('app.USER_ID'),
                'saldo'                 => null,
                'address_id'            => 2,
            ],
            [

                'num'                   => 7,
                'name'                  => 'Pintureria Colorin',
                'address'               => 'Av. Corrientes 3200 - Buenos Aires',
                'phone'                 => '1145500003',
                'cuit'                  => '30712345673',
                'razon_social'          => 'PINTURERIA COLORIN SRL',
                'iva_condition_id'      => 1,
                'price_type_id'         => null,
                'user_id'               => config('app.USER_ID'),
                'saldo'                 => null,
                'address_id'            => 3,
            ],
            [

                'num'                   => 8,
                'name'                  => 'Sanitarios del Litoral',
                'address'               => 'Ruta 11 Km 5 - Mar del Plata',
                'phone'                 => '2235500004',
                'cuit'                  => '30712345674',
                'razon_social'          => 'SANITARIOS DEL LITORAL SA',
                'iva_condition_id'      => 2,
                'price_type_id'         => 2,
                'user_id'               => config('app.USER_ID'),
                'saldo'                 => null,
                'address_id'            => 4,
            ],
            [

                'num'                   => 9,
                'name'                  => 'Bulonera Central',
                'address'               => 'Av. Mitre 780 - Tucuman',
                'phone'                 => '3814500005',
                'cuit'                  => '30712345675',
                'razon_social'          => 'BULONERA CENTRAL SRL',
                'iva_condition_id'      => 1,
                'price_type_id'         => 3,
                'user_id'               => config('app.USER_ID'),
                'saldo'                 => null,
                'address_id'            => 1,
            ],
            [

                'num'                   => 10,
                'name'                  => 'Herrajes Parana',
                'address'               => 'Bv. Zapiola 640 - Santa Fe',
                'phone'                 => '3425500006',
                'cuit'                  => '30712345676',
                'razon_social'          => 'HERRAJES PARANA SA',
                'iva_condition_id'      => 2,
                'price_type_id'         => null,
                'user_id'               => config('app.USER_ID'),
                'saldo'                 => null,
                'address_id'            => 2,
            ],
        ];

        foreach ($models as $model) {

            $model['link_google_maps'] = $link_google_maps;
            
            $client = Client::create($model);

            if (isset($model['id'])) {

                $client->id = $model['id'];
                $client->save();
            }
            
            Log::info('Se va a mandar crear_credit_accounts para client id '.$client->id);
            CreditAccountHelper::crear_credit_accounts('client', $client->id);
        }

    }
}
