<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\User;
use Illuminate\Database\Seeder;

class BuyerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $lucas = Buyer::create([
            'num'                       => 1,
            'name'                      => 'Lucas gonzalez',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'lucasgonzalez5500@gmail.com',
            'password'                  => bcrypt('1234'),
            'comercio_city_client_id'   => 1,
            'user_id'                   => env('USER_ID'),
        ]);
        $marcos = Buyer::create([
            'num'                       => 1,
            'name'                      => 'Marcos gonzalez',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'lucasgonzalez210200@gmail.com',
            'password'                  => bcrypt('1234'),
            'comercio_city_client_id'   => 3,
            'user_id'                   => env('USER_ID'),
        ]);

        $marcos = Buyer::create([
            'num'                       => 1,
            'name'                      => 'Vendedor',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'vendedor@gmail.com',
            'password'                  => bcrypt('1234'),
            'seller_id'                 => 1,
            'user_id'                   => env('USER_ID'),
        ]);

    }

    function matias() {
        $user = User::where('company_name', 'Ferretodo')
                        ->first();
        $marcos = Buyer::create([
            'num'                       => 1,
            'name'                      => 'Lucas',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'lucasgonzalez5500@gmail.com',
            'password'                  => bcrypt('1234'),
            'comercio_city_client_id'   => 5,
            'user_id'                   => env('USER_ID'),
        ]);
    }
}
