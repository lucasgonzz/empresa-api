<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Seeder;

class ClientsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $users = User::where('company_name', 'Autopartes Boxes')
                        ->get();
        $models = [
            [
                'name'              => 'Marcos Gonzalez',
                'address'           => 'San antonio 23 - Gualeguay, Entre Rios',
                'cuit'              => '20242112025',
                'razon_social'      => 'MARCOS SRL', 
                'iva_condition_id'  => 1,
            ],
            [

                'name'              => 'Lucas Gonzalez',
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'San antonio 23 - Gualeguay, Entre Rios',
                'cuit'              => '20242112025',
                'razon_social'      => 'MARCOS SRL', 
                'iva_condition_id'  => 1,
            ],
            [

                'name'              => 'Luquis Gonzalez',
                'address'           => 'San antonio 23 - Gualeguay, Entre Rios',
                'cuit'              => '20242112025',
                'razon_social'      => 'MARCOS SRL', 
                'iva_condition_id'  => 1,
            ],
        ];
        $ct = new Controller();
        foreach ($users as $user) {
            foreach ($models as $model) {
                $model['num']     = $ct->num('clients', $user->id);  
                $model['user_id'] = $user->id;
            }
            Client::create($model);
        }
    }
}
