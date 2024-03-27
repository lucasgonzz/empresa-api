<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;


class ProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->oscar();
        $this->matias();
    }

    function oscar() {
        $ct = new Controller();
        $user = User::where('company_name', 'Autopartes Boxes')->first();
        $models = [
            [
                'num'               => 1,
                'name'              => 'Buenos Aires',
                'percentage_gain'   => 100,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
                'saldo'             => 100,
            ],
            [
                'num'               => 2,
                'name'              => 'Rosario',
                'percentage_gain'   => null,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
                'saldo'             => 1200,
            ],
            [
                'num'               => 3,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 1,
                'name'              => 'Autopartes Boxes',
                'percentage_gain'   => 50,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => 2,
                'comercio_city_user_id' => 1,
            ],
        ];
        // $models = [
        //     [
        //         'num'               => 1,
        //         'name'              => 'Bermon',
        //         'percentage_gain'   => 30,
        //         'email'             => null,
        //         'address'           => '',
        //         'user_id'           => 500,
        //         'comercio_city_user_id' => null,
        //     ],
        //     [
        //         'num'               => 2,
        //         'name'              => 'CORTIFER',
        //         'percentage_gain'   => 30,
        //         'email'             => null,
        //         'address'           => '',
        //         'user_id'           => 500,
        //         'comercio_city_user_id' => null,
        //     ],
        //     [
        //         'num'               => 3,
        //         'name'              => 'ELECTROMARTINS',
        //         'percentage_gain'   => 30,
        //         'email'             => null,
        //         'address'           => '',
        //         'user_id'           => 500,
        //         'comercio_city_user_id' => null,
        //     ],
        //     [
        //         'num'               => 2,
        //         'name'              => 'EZETA',
        //         'percentage_gain'   => 30,
        //         'email'             => null,
        //         'address'           => '',
        //         'user_id'           => 500,
        //         'comercio_city_user_id' => null,
        //     ],
        //     [
        //         'num'               => 2,
        //         'name'              => 'LESSA',
        //         'percentage_gain'   => 30,
        //         'email'             => null,
        //         'address'           => '',
        //         'user_id'           => 500,
        //         'comercio_city_user_id' => null,
        //     ],

        //     [
        //         'num'               => 2,
        //         'name'              => 'PEREZ',
        //         'percentage_gain'   => 30,
        //         'email'             => null,
        //         'address'           => '',
        //         'user_id'           => 500,
        //         'comercio_city_user_id' => null,
        //     ],

        //     [
        //         'num'               => 2,
        //         'name'              => 'RERAR',
        //         'percentage_gain'   => 30,
        //         'email'             => null,
        //         'address'           => '',
        //         'user_id'           => 500,
        //         'comercio_city_user_id' => null,
        //     ],

        //     [
        //         'num'               => 2,
        //         'name'              => 'SABATINO',
        //         'percentage_gain'   => 30,
        //         'email'             => null,
        //         'address'           => '',
        //         'user_id'           => 500,
        //         'comercio_city_user_id' => null,
        //     ],

        // ];
        foreach ($models as $model) {
            Provider::create($model);
        }
    }

    function matias() {
        $user = User::where('company_name', 'Ferretodo')->first();
        $lucas_user = User::where('company_name', 'Autopartes Boxes')->first();
        $lucas_provider = Provider::create([
            'num'                   => 1,
            'name'                  => 'Oscar Peroni',
            'comercio_city_user_id' => $lucas_user->id,
            'user_id'               => $user->id,
            'percentage_gain'       => 10,
        ]);
    }

    function la_barraca() {
        $ct = new Controller();
        $user = User::where('company_name', 'la barraca')->first();
        $models = [
            [
                'name'              => 'Buenos Aires',
                'percentage_gain'   => 50,
            ],
            [
                'name'              => 'Rosario',
                'percentage_gain'   => 100,
            ],
        ];
        foreach ($models as $model) {
            Provider::create([
                'num'               => $ct->num('providers', $user->id), 
                'name'              => $model['name'],
                'percentage_gain'   => $model['percentage_gain'],
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ]);
        }
    }
}
