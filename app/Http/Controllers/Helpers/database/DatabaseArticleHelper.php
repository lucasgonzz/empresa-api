<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\Article;
use App\Models\Description;
use App\Models\Image;
use App\Models\PriceChange;
use App\Models\StockMovement;

class DatabaseArticleHelper {

    static function copiar_articulos($user, $bbdd_destino, $from_id) {

        if (!is_null($user)) {

            $articles = Article::where('user_id', $user->id)
                                ->orderBy('id', 'ASC')
                                ->where('id', '>=', $from_id)
                                ->with('descriptions', 'images', 'price_changes' ,'stock_movements', 'addresses')
                                ->get();

            DatabaseHelper::set_user_conecction($bbdd_destino);

            foreach ($articles as $article) {
                $finded_article = Article::find($article->id);
                
                if (!is_null($finded_article)) {
                    $finded_article->delete();
                    $finded_article->forceDelete();
                }
            }
            
            foreach ($articles as $article) {
                $new_article = [
                    'id'                => $article->id,                     
                    'num'               => $article->num,                     
                    'bar_code'          => $article->bar_code,            
                    'provider_code'     => $article->provider_code,   
                    'provider_id'       => $article->provider_id,     
                    'category_id'       => $article->category_id,     
                    'sub_category_id'   => $article->sub_category_id,
                    'brand_id'          => $article->brand_id,            
                    'name'              => $article->name,                    
                    'slug'              => $article->slug,                    
                    'cost'              => $article->cost,                    
                    'cost_in_dollars'       => $article->cost_in_dollars,
                    'costo_mano_de_obra'        => $article->costo_mano_de_obra,
                    'provider_cost_in_dollars'      => $article->provider_cost_in_dollars,     
                    'apply_provider_percentage_gain'        => $article->apply_provider_percentage_gain,
                    'price'     => $article->price,                   
                    'percentage_gain'       => $article->percentage_gain,
                    'provider_price_list_id'        => $article->provider_price_list_id,     
                    'iva_id'        => $article->iva_id,              
                    'stock'     => $article->stock,                   
                    'stock_min'     => $article->stock_min,           
                    'online'        => $article->online,              
                    'in_offer'      => $article->in_offer,            
                    'default_in_vender'     => $article->default_in_vender,
                    'status'        => $article->status,             
                    'user_id'       => $article->user_id, 
                    'final_price'   => $article->final_price, 
                ];

                $created_article = Article::create($new_article);

                // Crear descripciones
                Self::description($created_article, $article);

                // Crear imagenes
                Self::images($created_article, $article);
                
                // Crear price_changes
                Self::price_changes($created_article, $article);

                // Crear stock_movements
                Self::stock_movements($created_article, $article);

                // Crear addresses
                Self::addresses($created_article, $article);
                
                echo 'Se creo article id: '.$created_article->id.' </br>';
            }
        }
    }

    static function description($created_article, $article) {
        foreach ($article->descriptions as $description) {
            Description::create($description->toArray());
        }
    }

    static function images($created_article, $article) {
        foreach ($article->images as $image) {
            Image::create([
                'hosting_url' => $image->hosting_url,
                'imageable_id' => $article->id,
                'imageable_type' => $image->imageable_type,
                'color_id' => $image->color_id,
                'temporal_id' => $image->temporal_id,
            ]);
        }
    }

    static function price_changes($created_article, $article) {
        foreach ($article->price_changes as $price_change) {

            PriceChange::create([
                'article_id'            => $price_change->article_id,
                'cost'                  => $price_change->cost,
                'price'                 => $price_change->price,
                'final_price'           => $price_change->final_price,
                'employee_id'           => $price_change->employee_id,
            ]);
        }
    }

    static function stock_movements($created_article, $article) {
        foreach ($article->stock_movements as $stock_movement) {
            StockMovement::create([
                'temporal_id'               =>  $stock_movement->temporal_id,
                'article_id'                =>  $stock_movement->article_id,
                'from_address_id'           =>  $stock_movement->from_address_id,
                'to_address_id'             =>  $stock_movement->to_address_id,
                'provider_id'               =>  $stock_movement->provider_id,
                'sale_id'                   =>  $stock_movement->sale_id,
                'nota_credito_id'           =>  $stock_movement->nota_credito_id,
                'concepto'                  =>  $stock_movement->concepto,
                'observations'              =>  $stock_movement->observations,
                'amount'                    =>  $stock_movement->amount,
                'stock_resultante'          =>  $stock_movement->stock_resultante,
                'employee_id'               =>  $stock_movement->employee_id,
                'user_id'                   =>  $stock_movement->user_id,
            ]);
        }
    }

    static function addresses($created_article, $article) {
        foreach ($article->addresses as $address) {
            $created_article->addresses()->attach($address->id, [
                'amount'    => $address->pivot->amount,
            ]);
        }
    }

}