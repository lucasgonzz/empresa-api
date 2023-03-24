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
    }

    function lucas() {
        $ct = new Controller();
        $user = User::where('company_name', 'lucas')->first();
        $models = [
            [
                'num'               => 1,
                'name'              => 'Buenos Aires',
                'percentage_gain'   => 50,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
            ],
            [
                'num'               => 2,
                'name'              => 'Rosario',
                'percentage_gain'   => null,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => $user->id,
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
                'num'                   => 1,
                'name'                  => 'lucas',
                'percentage_gain'       => 0,
                'email'                 => 'lucasgonzalez5500@gmail.com',
                'address'               => 'Calle 123',
                'comercio_city_user_id' => 1,
                'user_id'               => 2,
            ],
        ];
        foreach ($models as $model) {
            Provider::create($model);
        }
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
