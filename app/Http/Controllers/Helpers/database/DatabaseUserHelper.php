<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\OnlineConfiguration;
use App\Models\User;

class DatabaseUserHelper {

    static function copiar_usuario($user, $bbdd_destino) {
        $user = User::where('id', $user->id)
                        ->with('extencions', 'online_configuration')
                        ->first();

        DatabaseHelper::set_user_conecction($bbdd_destino);

        $created_user = User::create([
            'id'                              => $user->id,
            'name'                            => $user->name,
            'doc_number'                      => $user->doc_number,
            'dollar'                          => $user->dollar,
            'company_name'                    => $user->company_name,
            'phone'                           => $user->phone,
            'email'                           => $user->email,
            'download_articles'               => $user->download_articles,
            'iva_included'                    => $user->iva_included,
            'password'                        => $user->password,
            'ask_amount_in_vender'            => $user->ask_amount_in_vender,
            'sale_ticket_width'               => $user->sale_ticket_width,
            'default_current_acount_payment_method_id'               => $user->default_current_acount_payment_method_id,
            'discount_stock_from_recipe_after_advance_to_next_status'               => $user->discount_stock_from_recipe_after_advance_to_next_status,
            'article_ticket_info_id'          => $user->article_ticket_info_id,

            'dias_alertar_empleados_ventas_no_cobradas'          => $user->dias_alertar_empleados_ventas_no_cobradas,
           
            'dias_alertar_administradores_ventas_no_cobradas'          => $user->dias_alertar_administradores_ventas_no_cobradas,
        ]);

        echo 'Se creo user id '.$user->id.' </br>';

        foreach ($user->extencions as $extencion) {
            $created_user->extencions()->attach($extencion->id);
            echo 'Se agrego extencion '.$extencion->name.' </br>';
        }

        $online_configuration = $user->online_configuration;

        OnlineConfiguration::create([
            'user_id'            => $online_configuration->user_id,    
            'pausar_tienda_online'            => $online_configuration->pausar_tienda_online,    
            'register_to_buy'                 => $online_configuration->register_to_buy,    
            'scroll_infinito_en_home'         => $online_configuration->scroll_infinito_en_home,    
            'online_price_type_id'            => $online_configuration->online_price_type_id,                     
            'online_price_surchage'           => $online_configuration->online_price_surchage,                      
            'instagram'                       => $online_configuration->instagram,                     
            'facebook'                        => $online_configuration->facebook,                     
            'quienes_somos'                   => $online_configuration->quienes_somos,                     
            'default_article_image_url'       => $online_configuration->default_article_image_url,                     
            'mensaje_contacto'                => $online_configuration->mensaje_contacto,                     
            'show_articles_without_images'    => $online_configuration->show_articles_without_images,
            'save_sale_after_finish_order'    => $online_configuration->save_sale_after_finish_order,
                                 
            'show_articles_without_stock'     => $online_configuration->show_articles_without_stock,
            'stock_null_equal_0'              => $online_configuration->stock_null_equal_0,

            'online_description'              => $online_configuration->online_description,                     
            'has_delivery'                    => $online_configuration->has_delivery,                     
            'order_description'               => $online_configuration->order_description,
        ]);
        
        echo 'Se creo online_configuration '.$online_configuration->id.' </br>';

    }
}