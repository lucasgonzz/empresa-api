<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\User;
use App\Models\UserConfiguration;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $ct = new Controller();
        $models = [
            [
                'name'              => 'Lucas Gonzalez',
                'company_name'      => 'Lucas',
                'image_url'         => env('APP_URL').'/storage/cubo.jpeg',
                'doc_number'        => '123',
                'email'             => 'lucasgonzalez5500@gmail.com',
                'phone'             => '3444622139',
                'instagram'         => 'https://www.instagram.com/lucasgonzz/',
                'facebook'          => 'https://www.facebook.com',
                'password'          => bcrypt('123'),
                'online_prices'     => 'only_buyers_with_comerciocity_client',
                'visible_password'  => null,
                'default_article_image_url' => 'http://empresa.local:8000/storage/168053912176623.webp',
                'payment_expired_at'=> Carbon::now()->addDay(),
                'quienes_somos'     => 
                'Lorem ipsum dolor sit, amet consectetur adipisicing, elit. Quidem placeat, illo enim excepturi alias numquam, labore. Cum repellat beatae consequatur commodi adipisci, ad, magnam impedit. Aliquid eum, molestias non error!

                Lorem ipsum dolor sit, amet consectetur adipisicing, elit. Quidem placeat, illo enim excepturi alias numquam, labore. Cum repellat beatae consequatur commodi adipisci, ad, magnam impedit. Aliquid eum, molestias non error!',
                'mensaje_contacto'  => 'Contactar tambien por mensaje directo en Facebook o Instagram, es el medio en el que mas activos estamos!',
            ],
            [
                'name'              => 'Marcos',
                'company_name'      => 'Marcos',
                'image_url'         => env('APP_URL').'/storage/cubo.jpeg',
                'doc_number'        => '1234',
                'email'             => 'marcosgonzalez5500@gmail.com',
                'password'          => bcrypt('1234'),
                'visible_password'  => null,
            ],
            [
                'name'              => 'Empleado 1',
                'doc_number'        => '1',
                'email'             => 'lucasgonzalez550022@gmail.com',
                'password'          => bcrypt('1'),
                'visible_password'  => '1',
                'owner_id'          => 1,
                'image_url'         => null,
                'permissions_slug'    => [
                    'product.index',
                    'product.store',
                    'product.update',
                ],
            ],
            [
                'name'              => 'Empleado 2',
                'doc_number'        => '2',
                'email'             => 'lucasgonzalez550023@gmail.com',
                'password'          => bcrypt('2'),
                'visible_password'  => '2',
                'image_url'         => null,
                'owner_id'          => 1,
                'permissions_slug'    => [
                    'product.index',
                    'product.delete',

                    'sale.index',
                    'sale.store',
                    'sale.update',
                ],
            ],
        ];
        foreach ($models as $model) {
            $user = User::create([
                'name'                  => $model['name'], 
                'company_name'          => isset($model['company_name']) ? $model['company_name'] : null,  
                'doc_number'            => $model['doc_number'], 
                'email'                 => $model['email'], 
                'password'              => $model['password'],  
                'image_url'             => $model['image_url'],  
                'visible_password'      => $model['visible_password'],  
                'owner_id'              => isset($model['owner_id']) ? $model['owner_id'] : null,  
                'online_prices'         => isset($model['online_prices']) ? $model['online_prices'] : null,  
                'payment_expired_at'    => isset($model['payment_expired_at']) ? $model['payment_expired_at'] : null,  
                'instagram'             => isset($model['instagram']) ? $model['instagram'] : null,  
                'facebook'              => isset($model['facebook']) ? $model['facebook'] : null,  
                'phone'                 => isset($model['phone']) ? $model['phone'] : null,  
                'quienes_somos'         => isset($model['quienes_somos']) ? $model['quienes_somos'] : null,  
                'mensaje_contacto'      => isset($model['mensaje_contacto']) ? $model['mensaje_contacto'] : null,  
                'default_article_image_url'      => isset($model['default_article_image_url']) ? $model['default_article_image_url'] : null,  
            ]);
            if (is_null($user->owner_id)) {

                $user->extencions()->attach([1, 2, 5, 6]);
                UserConfiguration::create([
                    'current_acount_pagado_details'         => 'Recibo de pago (saldado)',
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
                    'punto_venta'           => 4,
                    'ingresos_brutos'       => '20175018841',
                    'inicio_actividades'    => Carbon::now()->subYears(5),
                    'user_id'               => $user->id,
                ]);
            }
            if (!is_null($user->owner_id)) {
                foreach ($model['permissions_slug'] as $permission_slug) {
                    $user->permissions()->attach($ct->getModelBy('permissions', 'slug', $permission_slug, false, 'id'));
                }
            }
        }
    }
}
