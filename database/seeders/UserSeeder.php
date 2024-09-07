<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\ExtencionEmpresa;
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

        $this->for_user = env('FOR_USER');


        $ct = new Controller();
        $models = [
            [
                'id'                            => 500,
                'name'                          => 'Lucas',
                'use_archivos_de_intercambio'   => 1,
                'company_name'                  => 'Autopartes Boxes',
                // 'image_url'                     => null,
                'image_url'                     => env('APP_URL').'/storage/icon.png',
                'doc_number'                    => '1234',
                'email'                         => 'lucasgonzalez5500@gmail.com',
                'phone'                         => '3444622139',
                'sale_ticket_description'       => 'Hasta 15 dias de cambio trayendo este ticket',
                'password'                      => bcrypt('123'),
                'visible_password'              => null,
                'dollar'                        => 300,
                'home_position'                 => 1,
                'download_articles'             => 1,
                'online'                        => 'http://tienda.local:8081',
                'payment_expired_at'            => Carbon::now()->addDays(12),
                'last_user_activity'            => Carbon::now(),
                'total_a_pagar'                 => 15000,
                'plan_id'                       => 3,
                'plan_discount'                 => 27,
                'article_ticket_info_id'        => 1,
                'siempre_omitir_en_cuenta_corriente'    => 0,
                'online_configuration'          => [
                    'online_price_type_id'          => 3,
                    'register_to_buy'               => 1,
                    'scroll_infinito_en_home'       => 1,
                    'default_article_image_url'     => 'http://empresa.local:8000/storage/169705209718205.jpg',
                    'pausar_tienda_online'          => 0,
                ],
                'base_de_datos'                     => 'empresa_prueba_1',
                'google_custom_search_api_key'      => 'AIzaSyB8e-DlJMtkGxCK29tAo17lxBKStXtzeD4',
            ],
            [
                'id'                            => 501,
                'name'                          => 'Nico',
                'company_name'                  => 'Ferretodo',
                'iva_included'                  => 0,
                // 'image_url'                     => null,
                'image_url'                     => env('APP_URL').'/storage/icon.png',
                'doc_number'                    => '123',
                'email'                         => 'lucasgonzalez210200@gmail.com',
                'phone'                         => '3444622139',
                'password'                      => bcrypt('123'),
                'visible_password'              => null,
                'dollar'                        => 300,
                'home_position'                 => 1,
                'download_articles'             => 0,
                'online'                        => 'http://tienda.local:8081',
                'payment_expired_at'            => Carbon::now()->addDays(12),
                'last_user_activity'            => Carbon::now(),
                'total_a_pagar'                 => 15000,
                'plan_id'                       => 3,
                'plan_discount'                 => 27,
                'article_ticket_info_id'        => 1,
                // 'app_url'                       => 'https://comerciocity.com',
                'online_configuration'          => [
                    'online_price_type_id'          => 1,
                    'register_to_buy'               => 1,
                    'scroll_infinito_en_home'       => 1,
                    'default_article_image_url'     => 'http://empresa.local:8000/storage/168053912176623.webp',
                    'quienes_somos'                 => 
                    'Lorem ipsum dolor sit, amet consectetur adipisicing, elit. Quidem placeat, illo enim excepturi alias numquam, labore. Cum repellat beatae consequatur commodi adipisci, ad, magnam impedit. Aliquid eum, molestias non error!

                    Lorem ipsum dolor sit, amet consectetur adipisicing, elit. Quidem placeat, illo enim excepturi alias numquam, labore. Cum repellat beatae consequatur commodi adipisci, ad, magnam impedit. Aliquid eum, molestias non error!',
                    'mensaje_contacto'              => 'Contactar tambien por mensaje directo en Facebook o Instagram, es el medio en el que mas activos estamos!',
                    // 'online_price_surchage'         => 50,
                    // 'max_items_in_sale'             => 2,
                    'pausar_tienda_online'          => 0,
                    'instagram'                     => 'https://www.instagram.com/lucasgonzz/',
                    'facebook'                      => 'https://www.facebook.com',
                ],
                'base_de_datos'                     => 'empresa_prueba_2',
            ],
            [
                'name'              => 'Marcos',
                'company_name'      => 'Marcos',
                'image_url'         => env('APP_URL').'/storage/kas.png',
                'doc_number'        => '12345',
                'email'             => 'marcosgonzalez5500@gmail.com',
                'password'          => bcrypt('1234'),
                'visible_password'  => null,
                'home_position'                 => 2,
            ],
            [
                'name'              => 'Patricio',
                'doc_number'        => '1',
                'email'             => 'lucasgonzalez550022@gmail.com',
                'password'          => bcrypt('1'),
                'visible_password'  => '1',
                'owner_id'          => 500,
                'address_id'        => 1,
                'admin_access'      => 1,
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
                'address_id'        => 2,
                'doc_number'        => '2',
                'email'             => 'lucasgonzalez550023@gmail.com',
                'password'          => bcrypt('2'),
                'visible_password'  => '2',
                'image_url'         => null,
                'owner_id'          => 500,
                'permissions_slug'    => [
                    'article.index',
                    'article.delete',

                    'sale.index',
                    'sale.store',
                    'sale.update',
                ],
            ],
            [
                'name'              => 'Franco',
                'address_id'        => 3,
                'doc_number'        => '3',
                'email'             => 'lucasgonzalez550023@gmail.com',
                'password'          => bcrypt('3'),
                'visible_password'  => '3',
                'image_url'         => null,
                'owner_id'          => 500,
                'permissions_slug'    => [
                    'article.index',
                    'article.delete',

                    'sale.index',
                    'sale.store',
                    'sale.update',
                ],
            ],
            [
                'name'              => 'Santiago',
                'address_id'        => 4,
                'doc_number'        => '4',
                'email'             => 'lucasgonzalez550023@gmail.com',
                'password'          => bcrypt('4'),
                'visible_password'  => '4',
                'image_url'         => null,
                'owner_id'          => 500,
                'permissions_slug'    => [
                    'article.index',
                    'article.delete',

                    'sale.index',
                    'sale.store',
                    'sale.update',
                ],
            ],
        ];


        if ($this->for_user == 'colman') {

            $models[0]['name'] = 'Colman';

            $models[0]['extencions'] = [

                'budgets',
                'production',
                'acopios',
                'online',
                'article.costo_real',
                'bar_code_scanner',
                'comerciocity_interno',
                'article_num_in_online',
                'check_sales',
                'sale.observations',
                'production.production_movement',
                'check_article_stock_en_vender',
            ];

        } else if ($this->for_user == 'feito') {

            $models[0]['name'] = 'Feito';

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 1;
            $models[0]['redondear_centenas_en_vender'] = 1;

            $models[0]['extencions'] = [

                'bar_code_scanner',
                'comerciocity_interno',
                'ask_save_current_acount',
            ];

        } else if ($this->for_user == 'hipermax') {

            $models[0]['name'] = 'Hipermax';

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 1;

            $models[0]['extencions'] = [

                'bar_code_scanner',
                'comerciocity_interno',
                'ask_save_current_acount',
                'articles_default_in_vender',
            ];

        } else if ($this->for_user == 'fenix') {

            $models[0]['name'] = 'Fenix';

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'budgets',
                'online',
                'bar_code_scanner',
                'comerciocity_interno',
                'article_num_in_online',
            ];

        } else if ($this->for_user == 'pack_descartables') {

            $models[0]['name'] = 'Pack Descartables';

            $models[0]['iva_included'] = 0;

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'budgets',
                'bar_code_scanner',
                'comerciocity_interno',
                'articulo_margen_de_ganancia_segun_lista_de_precios',
                'cambiar_price_type_en_vender',
            ];

        }


        foreach ($models as $model) {
            $user = User::create([
                'id'                            => isset($model['id']) ? $model['id'] : null,  
                'name'                          => $model['name'], 
                'use_archivos_de_intercambio'                  => isset($model['use_archivos_de_intercambio']) ? $model['use_archivos_de_intercambio'] : null,  
                'company_name'                  => isset($model['company_name']) ? $model['company_name'] : null,  
                'iva_included'                  => isset($model['iva_included']) ? $model['iva_included'] : 1,  
                'doc_number'                    => $model['doc_number'], 
                'email'                         => $model['email'], 
                'password'                      => $model['password'],  
                'image_url'                     => $model['image_url'],  
                'visible_password'              => $model['visible_password'],  
                'address_id'                    => isset($model['address_id']) ? $model['address_id'] : null,  
                'owner_id'                      => isset($model['owner_id']) ? $model['owner_id'] : null,  
                'admin_access'                  => isset($model['admin_access']) ? $model['admin_access'] : null, 
                'redondear_centenas_en_vender'  => isset($model['redondear_centenas_en_vender']) ? $model['redondear_centenas_en_vender'] : null, 
                'payment_expired_at'            => isset($model['payment_expired_at']) ? $model['payment_expired_at'] : null,  
                'online'                        => isset($model['online']) ? $model['online'] : null,
                'home_position'                 => isset($model['home_position']) ? $model['home_position'] : null,
                'plan_id'                       => isset($model['plan_id']) ? $model['plan_id'] : null,
                'plan_discount'                 => isset($model['plan_discount']) ? $model['plan_discount'] : null,
                'article_ticket_info_id'                 => isset($model['article_ticket_info_id']) ? $model['article_ticket_info_id'] : null,
                'siempre_omitir_en_cuenta_corriente'                 => isset($model['siempre_omitir_en_cuenta_corriente']) ? $model['siempre_omitir_en_cuenta_corriente'] : null,
                'total_a_pagar'                 => isset($model['total_a_pagar']) ? $model['total_a_pagar'] : null,
                'app_url'                       => isset($model['app_url']) ? $model['app_url'] : null,
                'base_de_datos'                 => isset($model['base_de_datos']) ? $model['base_de_datos'] : null,
                'google_custom_search_api_key'                 => isset($model['google_custom_search_api_key']) ? $model['google_custom_search_api_key'] : null,
                'dias_alertar_empleados_ventas_no_cobradas'        => 1,
                'dias_alertar_administradores_ventas_no_cobradas'  => 2,
            ]);

            
            if (is_null($user->owner_id)) {

                if (isset($model['extencions'])) {
                    foreach ($model['extencions'] as $extencion_slug) {
                        $extencion = ExtencionEmpresa::where('slug', $extencion_slug)
                                                    ->first();
                        $user->extencions()->attach($extencion->id);
                    }
                }
                
                UserConfiguration::create([
                    'current_acount_pagado_details'         => 'Saldado',
                    'current_acount_pagandose_details'      => 'Recibo de pago',
                    'iva_included'                          => 1,
                    'limit_items_in_sale_per_page'          => null,
                    'can_make_afip_tickets'                 => 1,
                    'user_id'                               => $user->id,
                ]);

                AfipInformation::create([
                    'iva_condition_id'      => 1,
                    'razon_social'          => 'Empresa de '.$user->company_name,
                    'domicilio_comercial'   => 'Pellegrini 1876',
                    'cuit'                  => '20381712010',
                    // 20175018841 papa 
                    // 20167430490 felix
                    // 20381712010 Nico Ferretodo
                    'punto_venta'           => 4,
                    'ingresos_brutos'       => '20381712010',
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
            // 'has_delivery'                  => 1,
            'dollar'                        => 200,
            'doc_number'                    => '21491373',
            // 'delivery_price'                => 70,
            // 'online_prices'                 => 'all',
            // 'online'                        => 'http://kioscoverde.local:8080',
            // 'order_description'             => 'Observaciones',
            // 'show_articles_without_images'  => 1,
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

        $commerce->extencions()->attach([1, 2, 4, 5, 7, 8, 9]);
        UserConfiguration::create([
            'current_acount_pagado_details'         => 'Recibo de pago (saldado)',
            'current_acount_pagandose_details'      => 'Recibo de pago',
            'iva_included'                          => 1,
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
