<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (config('app.FOR_USER') == 'truvari') {
            $this->truvari();
        } else {
            $this->empleados();
        }
    }

    function truvari() {
        $models = [
            [
                'name'              => 'Vendedor',
                'doc_number'        => '1',
                'email'             => 'lucasgonzalez550022@gmail.com',
                'password'          => bcrypt('1'),
                'visible_password'  => '1',
                'owner_id'          => 500,
                'address_id'        => 1,
                'admin_access'      => 0,
                'image_url'         => null,
                'permissions_slug'    => [
                    'sale.store',
                ],
            ],
            [
                'name'              => 'Repartidor',
                'doc_number'        => '2',
                'email'             => 'lucasgonzalez550022@gmail.com',
                'password'          => bcrypt('2'),
                'visible_password'  => '1',
                'owner_id'          => 500,
                'address_id'        => 1,
                'admin_access'      => 0,
                'image_url'         => null,
                'permissions_slug'    => [
                    'sale.por_entregar.index',
                    'road_map.index',
                    'road_map.terminadas.index',
                    'road_map.terminadas.only_your',
                ],
            ],
            [
                'name'              => 'Vendedor y Repartidor',
                'doc_number'        => '3',
                'email'             => 'lucasgonzalez550022@gmail.com',
                'password'          => bcrypt('3'),
                'visible_password'  => '1',
                'owner_id'          => 500,
                'address_id'        => 1,
                'admin_access'      => 0,
                'image_url'         => null,
                'permissions_slug'    => [
                    'sale.store',
                    'sale.por_entregar.index',
                    'road_map.index',
                    'road_map.terminadas.index',
                    'road_map.terminadas.all',
                ],
            ],
            [
                'name'              => 'Lucas',
                'doc_number'        => '4',
                'email'             => 'lucasgonzalez550022@gmail.com',
                'password'          => bcrypt('4'),
                'visible_password'  => '1',
                'owner_id'          => 500,
                'address_id'        => 1,
                'admin_access'      => 0,
                'image_url'         => null,
                'permissions_slug'    => [
                    'sale.store',
                    'sale.por_entregar.index',
                    'road_map.index',
                    'road_map.terminadas.index',
                    'road_map.terminadas.all',
                ],
            ],
        ];
        $this->crear_empleados($models);
    }

    function empleados() {
        $models = [
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

        $this->crear_empleados($models);
    }

    function crear_empleados($models) {
        foreach ($models as $model) {
            $user = User::create([
                'id'                            => isset($model['id']) ? $model['id'] : null,  
                'name'                          => $model['name'], 
                'use_archivos_de_intercambio'                  => isset($model['use_archivos_de_intercambio']) ? $model['use_archivos_de_intercambio'] : null,  
                'company_name'                  => isset($model['company_name']) ? $model['company_name'] : null,  
                'iva_included'                  => isset($model['iva_included']) ? $model['iva_included'] : 1, 
                'comision_funcion'                  => isset($model['comision_funcion']) ? $model['comision_funcion'] : null, 
                'doc_number'                    => $model['doc_number'], 
                'tamano_letra'                  => 2.5,
                'email'                         => $model['email'], 
                'password'                      => $model['password'],  
                'image_url'                     => $model['image_url'],  
                'visible_password'              => $model['visible_password'],  
                'download_articles'             => isset($model['download_articles']) ? $model['download_articles'] : null,  
                'address_id'                    => isset($model['address_id']) ? $model['address_id'] : null,  
                'owner_id'                      => isset($model['owner_id']) ? $model['owner_id'] : null,  
                'admin_access'                  => isset($model['admin_access']) ? $model['admin_access'] : null, 
                'redondear_centenas_en_vender'  => isset($model['redondear_centenas_en_vender']) ? $model['redondear_centenas_en_vender'] : 0, 
                'ask_amount_in_vender'  => isset($model['ask_amount_in_vender']) ? $model['ask_amount_in_vender'] : 1, 
                'payment_expired_at'            => isset($model['payment_expired_at']) ? $model['payment_expired_at'] : null,  
                'online'                        => isset($model['online']) ? $model['online'] : null,
                'home_position'                 => isset($model['home_position']) ? $model['home_position'] : null,
                'plan_id'                       => isset($model['plan_id']) ? $model['plan_id'] : null,
                'plan_discount'                 => isset($model['plan_discount']) ? $model['plan_discount'] : null,
                'article_ticket_info_id'                 => isset($model['article_ticket_info_id']) ? $model['article_ticket_info_id'] : null,
                'siempre_omitir_en_cuenta_corriente'                 => isset($model['siempre_omitir_en_cuenta_corriente']) ? $model['siempre_omitir_en_cuenta_corriente'] : null,
                'article_ticket_print_function'                 => isset($model['article_ticket_print_function']) ? $model['article_ticket_print_function'] : null,
                'total_a_pagar'                 => isset($model['total_a_pagar']) ? $model['total_a_pagar'] : null,
                'app_url'                       => isset($model['app_url']) ? $model['app_url'] : null,
                'base_de_datos'                 => isset($model['base_de_datos']) ? $model['base_de_datos'] : null,
                'google_custom_search_api_key'                 => isset($model['google_custom_search_api_key']) ? $model['google_custom_search_api_key'] : null,
                'dias_alertar_empleados_ventas_no_cobradas'        => 1,
                'dias_alertar_administradores_ventas_no_cobradas'  => 2,
                'default_version'               => env('APP_ENV') == 'local' ? 'http://empresa.local:8080' : null,
                'estable_version'               => env('APP_ENV') == 'local' ? 'http://empresa.local:8081' : null,
            ]); 

            if (!is_null($user->owner_id)) {
                    
                $ct = new Controller();

                foreach ($model['permissions_slug'] as $permission_slug) {
                    $user->permissions()->attach($ct->getModelBy('permission_empresas', 'slug', $permission_slug, false, 'id'));
                }
            }
        }

    }

}
