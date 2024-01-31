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
        $this->lucas();
        $this->marcos();
    }

    function lucas() {
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
                'num'               => 4,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
                'saldo'             => 1500,
            ],
            [
                'num'               => 4,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 4,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 4,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 4,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 4,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 4,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 4,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 4,
                'name'              => 'Mc Electronica',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 4,
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
        foreach ($models as $model) {
            Provider::create($model);
        }
    }

    function marcos() {
        $user = User::where('company_name', 'marcos')->first();
        $lucas_user = User::where('company_name', 'Autopartes Boxes')->first();
        $lucas_provider = Provider::create([
            'num'                   => 1,
            'name'                  => 'Lucas',
            'comercio_city_user_id' => $lucas_user->id,
            'user_id'               => $user->id,
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
