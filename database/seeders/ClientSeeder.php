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
        $user = User::where('company_name', 'Autopartes Boxes')
                        ->first();
        $models = [
            [

                'num'                   => 1,
                'name'                  => 'Lucas Gonzalez',
                'email'                 => 'lucasgonzalez5500@gmail.com',
                'address'               => 'San antonio 23 - Gualeguay, Entre Rios',
                // 'cuit'                  => '20242112025',
                'dni'                   => '42354898',
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => 2,
                'user_id'               => $user->id,
            ],
            [
                'num'                   => 2,
                'name'                  => 'Matias Galvan',
                'address'               => 'San antonio 23 - Gualeguay, Entre Rios',
                'cuit'                  => '30671859339',
                'price_type_id'         => 1,
                // Cuit Banco la Rioja: 30671859339
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => 1,
                'user_id'               => $user->id,
                'comercio_city_user_id' => User::where('company_name', 'Ferretodo')->first()->id,
            ],
            [

                'num'                   => 3,
                'name'                  => 'Luquis Gonzalez',
                'address'               => 'San antonio 23 - Gualeguay, Entre Rios',
                'cuit'                  => '20242112025',
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => 3,
                // 'price_type_id'         => 1,
                'user_id'               => $user->id,
                'comercio_city_user_id' => null,
            ],
            [
                'id'                    => 784,
                'name'                  => 'Gregorio',
                'seller_id'             => 3,
                // 'price_type_id'         => 1,
                'user_id'               => $user->id,
            ]
        ];
        foreach ($models as $model) {
            $client = Client::create($model);
            if (isset($model['id'])) {
                $client->id = $model['id'];
                $client->save();
            }
        }

        $this->matias();
    }

    function matias() {

        $user = User::where('company_name', 'Ferretodo')
                        ->first();
        $models = [
            [

                'num'                   => 1,
                'name'                  => 'Lucas Gonzalez',
                'email'                 => 'lucasgonzalez5500@gmail.com',
                'address'               => 'San antonio 23 - Gualeguay, Entre Rios',
                'cuit'                  => '20242112025',
                'razon_social'          => 'MARCOS SRL', 
                'iva_condition_id'      => 1,
                'seller_id'             => 2,
                'user_id'               => $user->id,
            ],
        ];
        foreach ($models as $model) {
            $client = Client::create($model);
        }
    }
}
