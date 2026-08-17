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
            // Los tres num de este bloque estaban repetidos en 1 (bug viejo). Se corrigen de
            // paso a 1/2/3 porque los 12 perfiles nuevos siguen la numeracion correlativa desde
            // aca (4 en adelante) y dejarlos en 1 hubiera repetido num tres veces mas.
            'num'                       => 1,
            'name'                      => 'Lucas gonzalez',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'lucasgonzalez5500@gmail.com',
            'password'                  => bcrypt('1234'),
            'comercio_city_client_id'   => 1,
            'user_id'                   => config('app.USER_ID'),
        ]);
        $marcos = Buyer::create([
            'num'                       => 2,
            'name'                      => 'Marcos gonzalez',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'lucasgonzalez210200@gmail.com',
            'password'                  => bcrypt('1234'),
            'comercio_city_client_id'   => 2,
            'user_id'                   => config('app.USER_ID'),
        ]);

        $marcos = Buyer::create([
            'num'                       => 3,
            'name'                      => 'Vendedor',
            'surname'                   => 'Gonzalez',
            'city'                      => 'Gualeguay',
            'phone'                     => '+5493444622139',
            'email'                     => 'vendedor@gmail.com',
            'password'                  => bcrypt('1234'),
            'seller_id'                 => 1,
            'user_id'                   => config('app.USER_ID'),
        ]);

        // Perfiles de tienda 4 a 15, agregados para la semilla de datos de prueba completa
        // (unidad U2). comercio_city_client_id apunta a los clientes 3 a 14 (ClientSeeder):
        // sin ese vinculo un Buyer no genera ninguna sugerencia del motor de ofertas, porque
        // los criterios carrito_abandonado e interes_ecommerce (CriteriosDeOfertaService)
        // joinean clients con buyers justamente por esa columna.
        $perfiles_tienda = [
            ['num' => 4,  'name' => 'Sofia',     'surname' => 'Martinez',  'email' => 'sofia.martinez@gmail.com',   'client_id' => 3],
            ['num' => 5,  'name' => 'Nicolas',    'surname' => 'Fernandez', 'email' => 'nicolas.fernandez@gmail.com', 'client_id' => 4],
            ['num' => 6,  'name' => 'Valentina',  'surname' => 'Rodriguez', 'email' => 'valentina.rodriguez@gmail.com', 'client_id' => 5],
            ['num' => 7,  'name' => 'Tomas',      'surname' => 'Diaz',      'email' => 'tomas.diaz@gmail.com',        'client_id' => 6],
            ['num' => 8,  'name' => 'Camila',     'surname' => 'Gomez',     'email' => 'camila.gomez@gmail.com',      'client_id' => 7],
            ['num' => 9,  'name' => 'Mateo',      'surname' => 'Alvarez',   'email' => 'mateo.alvarez@gmail.com',     'client_id' => 8],
            ['num' => 10, 'name' => 'Julieta',    'surname' => 'Romero',    'email' => 'julieta.romero@gmail.com',    'client_id' => 9],
            ['num' => 11, 'name' => 'Franco',     'surname' => 'Sosa',      'email' => 'franco.sosa@gmail.com',       'client_id' => 10],
            ['num' => 12, 'name' => 'Agustina',   'surname' => 'Torres',    'email' => 'agustina.torres@gmail.com',   'client_id' => 11],
            ['num' => 13, 'name' => 'Bruno',      'surname' => 'Ibanez',    'email' => 'bruno.ibanez@gmail.com',      'client_id' => 12],
            ['num' => 14, 'name' => 'Martina',    'surname' => 'Acosta',    'email' => 'martina.acosta@gmail.com',    'client_id' => 13],
            ['num' => 15, 'name' => 'Lautaro',    'surname' => 'Molina',    'email' => 'lautaro.molina@gmail.com',    'client_id' => 14],
        ];

        foreach ($perfiles_tienda as $perfil) {
            Buyer::create([
                'num'                       => $perfil['num'],
                'name'                      => $perfil['name'],
                'surname'                   => $perfil['surname'],
                'city'                      => 'Gualeguay',
                'phone'                     => '+5493444622139',
                'email'                     => $perfil['email'],
                'password'                  => bcrypt('1234'),
                'comercio_city_client_id'   => $perfil['client_id'],
                'user_id'                   => config('app.USER_ID'),
            ]);
        }
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
            'user_id'                   => config('app.USER_ID'),
        ]);
    }
}
