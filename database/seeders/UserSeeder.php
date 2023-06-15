<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\OnlineConfiguration;
use App\Models\User;
use App\Models\UserConfiguration;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
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
            return;
        } 
        $ct = new Controller();
        $models = [
            [
                'name'                          => 'Lucas Gonzalez',
                'company_name'                  => 'Lucas',
                'image_url'                     => env('APP_URL').'/storage/cubo.jpeg',
                'doc_number'                    => '123',
                'email'                         => 'lucasgonzalez5500@gmail.com',
                'phone'                         => '3444622139',
                'password'                      => bcrypt('123'),
                'visible_password'              => null,
                'dollar'                        => 300,
                'home_position'                 => 1,
                'download_articles'             => 1,
                'online'                        => 'http://tienda.local:8081',
                // 'payment_expired_at'         => Carbon::now()->addDay(),
                'online_configuration'          => [
                    'online_price_type_id'          => 1,
                    'default_article_image_url'     => 'http://empresa.local:8000/storage/168053912176623.webp',
                    'quienes_somos'                 => 
                    'Lorem ipsum dolor sit, amet consectetur adipisicing, elit. Quidem placeat, illo enim excepturi alias numquam, labore. Cum repellat beatae consequatur commodi adipisci, ad, magnam impedit. Aliquid eum, molestias non error!

                    Lorem ipsum dolor sit, amet consectetur adipisicing, elit. Quidem placeat, illo enim excepturi alias numquam, labore. Cum repellat beatae consequatur commodi adipisci, ad, magnam impedit. Aliquid eum, molestias non error!',
                    'mensaje_contacto'              => 'Contactar tambien por mensaje directo en Facebook o Instagram, es el medio en el que mas activos estamos!',
                    'online_price_surchage'         => 50,
                    // 'max_items_in_sale'             => 2,
                    'pausar_tienda_online'          => 0,
                    'instagram'                     => 'https://www.instagram.com/lucasgonzz/',
                    'facebook'                      => 'https://www.facebook.com',
                ],
            ],
            [
                'name'              => 'Marcos',
                'company_name'      => 'Marcos',
                'image_url'         => env('APP_URL').'/storage/zapatilla_roja.webp',
                'doc_number'        => '1234',
                'email'             => 'marcosgonzalez5500@gmail.com',
                'password'          => bcrypt('1234'),
                'visible_password'  => null,
                'home_position'                 => 3,
            ],
            [
                'name'              => 'Bartolo',
                'company_name'      => 'Marcos',
                'image_url'         => env('APP_URL').'/storage/kas.png',
                'doc_number'        => '1234',
                'email'             => 'marcosgonzalez5500@gmail.com',
                'password'          => bcrypt('1234'),
                'visible_password'  => null,
                'home_position'                 => 2,
            ],
            [
                'name'              => 'Juliana',
                'company_name'      => 'Marcos',
                'image_url'         => env('APP_URL').'/storage/pinocho.png',
                'doc_number'        => '1234',
                'email'             => 'marcosgonzalez5500@gmail.com',
                'password'          => bcrypt('1234'),
                'visible_password'  => null,
                'home_position'                 => 2,
            ],
            [
                'name'              => 'Inidca',
                'company_name'      => 'Marcos',
                'image_url'         => env('APP_URL').'/storage/indica.png',
                'doc_number'        => '1234',
                'email'             => 'marcosgonzalez5500@gmail.com',
                'password'          => bcrypt('1234'),
                'visible_password'  => null,
                'home_position'                 => 2,
            ],
            [
                'name'              => 'Calie',
                'company_name'      => 'Marcos',
                'image_url'         => env('APP_URL').'/storage/cali.png',
                'doc_number'        => '1234',
                'email'             => 'marcosgonzalez5500@gmail.com',
                'password'          => bcrypt('1234'),
                'visible_password'  => null,
                'home_position'                 => 2,
            ],
            [
                'name'              => 'Juan',
                'doc_number'        => '1',
                'email'             => 'lucasgonzalez550022@gmail.com',
                'password'          => bcrypt('1'),
                'visible_password'  => '1',
                'owner_id'          => 1,
                'image_url'         => null,
                'permissions_slug'    => [
                    'article.index',
                    'article.store',
                    'article.update',
                    'client.index',
                    'client.update',
                    'sale.store',
                ],
            ],
            [
                'name'              => 'Miguel',
                'doc_number'        => '2',
                'email'             => 'lucasgonzalez550023@gmail.com',
                'password'          => bcrypt('2'),
                'visible_password'  => '2',
                'image_url'         => null,
                'owner_id'          => 1,
                'permissions_slug'    => [
                    'article.index',
                    'article.delete',

                    'sale.index',
                    'sale.store',
                    'sale.update',
                ],
            ],
        ];
        foreach ($models as $model) {
            $user = User::create([
                'name'                          => $model['name'], 
                'company_name'                  => isset($model['company_name']) ? $model['company_name'] : null,  
                'doc_number'                    => $model['doc_number'], 
                'email'                         => $model['email'], 
                'password'                      => $model['password'],  
                'image_url'                     => $model['image_url'],  
                'visible_password'              => $model['visible_password'],  
                'owner_id'                      => isset($model['owner_id']) ? $model['owner_id'] : null,  
                'payment_expired_at'            => isset($model['payment_expired_at']) ? $model['payment_expired_at'] : null,  
                'online'                        => isset($model['online']) ? $model['online'] : null,
                'home_position'                 => isset($model['home_position']) ? $model['home_position'] : null,
            ]);
            if (is_null($user->owner_id)) {

                $user->extencions()->attach([1, 2, 5, 6, 8, 9]);
                UserConfiguration::create([
                    'current_acount_pagado_details'         => 'Saldado',
                    'current_acount_pagandose_details'      => 'Recibo de pago',
                    'iva_included'                          => 0,
                    'limit_items_in_sale_per_page'          => null,
                    'can_make_afip_tickets'                 => 1,
                    'user_id'                               => $user->id,
                ]);

                AfipInformation::create([
                    'iva_condition_id'      => 1,
                    'razon_social'          => 'Empresa de '.$user->company_name,
                    'domicilio_comercial'   => 'Pellegrini 1876',
                    'cuit'                  => '20175018841',
                    // 20175018841 papa 
                    // 20167430490 felix
                    'punto_venta'           => 4,
                    'ingresos_brutos'       => '20175018841',
                    'inicio_actividades'    => Carbon::now()->subYears(5),
                    'user_id'               => $user->id,
                ]);

                if (isset($model['online_configuration'])) {
                    $online_configuration               = $model['online_configuration'];
                    $online_configuration['user_id']    = $user->id;
                    OnlineConfiguration::create($online_configuration);
                }
            }
            if (!is_null($user->owner_id)) {
                foreach ($model['permissions_slug'] as $permission_slug) {
                    $user->permissions()->attach($ct->getModelBy('permission_empresas', 'slug', $permission_slug, false, 'id'));
                }
            }
        }
    }

    function la_barraca() {
        $commerce = User::create([
            'id'                            => 3,
            'name'                          => 'Oscar',
            'email'                         => null,
            // 'hosting_image_url'             => 'http://miregistrodeventas.local:8001/storage/brljlnhpojrrnk0hfapz.jpeg',
            // 'phone'                         => '3444622139',
            'company_name'                  => 'La barraca',
            'status'                        => 'commerce',
            'plan_id'                       => 6,
            'type'                          => 'provider',
            'password'                      => bcrypt('1234'),
            'percentage_card'               => 0,
            'has_delivery'                  => 1,
            'dollar'                        => 200,
            'doc_number'                    => '21491373',
            // 'delivery_price'                => 70,
            'online_prices'                 => 'all',
            // 'online'                        => 'http://kioscoverde.local:8080',
            'order_description'             => 'Observaciones',
            'show_articles_without_images'  => 1,
            // 'default_article_image_url'     => 'http://miregistrodeventas.local:8001/storage/ajx4wszusy7hp2vditgb.webp',
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
}
