<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\User;
use App\Models\UserConfiguration;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        if (env('FOR_SERVER') == 'la_barraca') {
            $this->la_barraca();
        } else {
            $this->lucas();
        }

    }

    function lucas() {
        $commerce = User::create([
            'id'                            => 308,
            'name'                          => 'Lucas',
            'doc_number'                    => '123',
            'email'                         => 'lucasgonzalez210200@gmail.com',
            'hosting_image_url'             => 'http://miregistrodeventas.local:8001/storage/brljlnhpojrrnk0hfapz.jpeg',
            'phone'                         => '3444622139',
            'company_name'                  => 'Lucas',
            'status'                        => 'commerce',
            'plan_id'                       => 6,
            'type'                          => 'provider',
            'password'                      => bcrypt('123'),
            'percentage_card'               => 0,
            'has_delivery'                  => 1,
            'dollar'                        => 200,
            'delivery_price'                => 70,
            'online_prices'                 => 'all',
            'online'                        => 'http://kioscoverde.local:8080',
            'order_description'             => 'Observaciones',
            'show_articles_without_images'  => 1,
            'default_article_image_url'     => 'http://miregistrodeventas.local:8001/storage/ajx4wszusy7hp2vditgb.webp',
            'created_at'                    => Carbon::now()->subMonths(2),
            'payment_expired_at'            => Carbon::now()->addDays(2),
        ]);

        Address::create([
            'street'        => 'Parana con chocolate',
            'street_number' => 3322,
            'city'          => 'Gualeguay',
            'lat'           => '1',
            'lng'           => '1',
            'province'      => 'Entre Rios',
            'user_id'       => $commerce->id,
        ]);

        $commerce->extencions()->attach([1, 2, 3, 4, 7, 8, 9]);
        UserConfiguration::create([
            'current_acount_pagado_details'         => 'Recibo de pago (saldado)',
            'current_acount_pagandose_details'      => 'Recibo de pago',
            'iva_included'                          => 0,
            'limit_items_in_sale_per_page'          => null,
            'can_make_afip_tickets'                 => 1,
            'user_id'                               => $commerce->id,
        ]);

        AfipInformation::create([
            'iva_condition_id'      => 1,
            'razon_social'          => 'Colman',
            'domicilio_comercial'   => 'Pellegrini 1876',
            'cuit'                  => '20175018841',
            'punto_venta'           => 4,
            'ingresos_brutos'       => '20175018841',
            'inicio_actividades'    => Carbon::now()->subYears(5),
            'user_id'               => $commerce->id,
        ]);
    }

    function la_barraca() {
        $commerce = User::create([
            'id'                            => 309,
            'name'                          => 'Oscar',
            'email'                         => 'lucasgonzalez210200@gmail.com',
            'hosting_image_url'             => 'http://miregistrodeventas.local:8001/storage/brljlnhpojrrnk0hfapz.jpeg',
            'phone'                         => '3444622139',
            'company_name'                  => 'La barraca',
            'status'                        => 'commerce',
            'plan_id'                       => 6,
            'type'                          => 'provider',
            'password'                      => bcrypt('1234'),
            'percentage_card'               => 0,
            'has_delivery'                  => 1,
            'dollar'                        => 200,
            'delivery_price'                => 70,
            'online_prices'                 => 'all',
            'online'                        => 'http://kioscoverde.local:8080',
            'order_description'             => 'Observaciones',
            'show_articles_without_images'  => 1,
            'default_article_image_url'     => 'http://miregistrodeventas.local:8001/storage/ajx4wszusy7hp2vditgb.webp',
            'created_at'                    => Carbon::now()->subMonths(2),
        ]);

        Address::create([
            'street'        => 'Alfredo palacios 333',
            'street_number' => 3322,
            'city'          => 'Gualeguay',
            'lat'           => '1',
            'lng'           => '1',
            'province'      => 'Entre Rios',
            'user_id'       => $commerce->id,
        ]);

        $commerce->extencions()->attach([1, 2, 3, 4, 7, 8, 9]);
        UserConfiguration::create([
            'current_acount_pagado_details'         => 'Recibo de pago (saldado)',
            'current_acount_pagandose_details'      => 'Recibo de pago',
            'iva_included'                          => 0,
            'limit_items_in_sale_per_page'          => null,
            'can_make_afip_tickets'                 => 1,
            'user_id'                               => $commerce->id,
        ]);

        AfipInformation::create([
            'iva_condition_id'      => 1,
            'razon_social'          => 'La Barraca',
            'domicilio_comercial'   => 'Alfredo palacios 333',
            'cuit'                  => '20175018841',
            'punto_venta'           => 4,
            'ingresos_brutos'       => '20175018841',
            'inicio_actividades'    => Carbon::now()->subYears(5),
            'user_id'               => $commerce->id,
        ]);
    }

    function super() {
        $super = User::create([
            'id'              => 310,
            'name' => 'Lucas super',
            'status' => 'super',
            'password' => bcrypt('1234'),
        ]);
    }
}
