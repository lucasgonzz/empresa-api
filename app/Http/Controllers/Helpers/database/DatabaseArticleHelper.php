<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\Article;
use App\Models\Description;
use App\Models\Image;
use App\Models\PriceChange;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Schema;

class DatabaseArticleHelper {

    static function copiar_articulos($user, $bbdd_destino, $from_id) {

        if (!is_null($user)) {

            $articles = Article::where('user_id', $user->id)
                                ->orderBy('id', 'ASC')
                                ->where('id', '>=', $from_id)
                                ->with('descriptions', 'images', 'price_changes' ,'stock_movements', 'addresses')
                                ->withTrashed()
                                ->get();

            DatabaseHelper::set_user_conecction($bbdd_destino);

            // Una vez por copia, ya parados en la base destino (ver destino_tiene_stock_por_deposito()).
            $destino_con_stock_por_deposito = Self::destino_tiene_stock_por_deposito();

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
                Self::stock_movements($created_article, $article, $destino_con_stock_por_deposito);

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

    /**
     * Si la base destino (la conexión `mysql`, que `DatabaseHelper::set_user_conecction()` ya
     * apuntó ahí) tiene las columnas `stock_anterior` y `stock_por_deposito` de
     * `stock_movements`. Una base que todavía no corrió esa migración (29/9/2026) las rechaza con
     * "Unknown column" y el INSERT cortaría la copia entera: en ese caso no se mandan.
     *
     * @return bool
     */
    static function destino_tiene_stock_por_deposito() {

        $schema = Schema::connection('mysql');

        return $schema->hasColumn('stock_movements', 'stock_anterior')
            && $schema->hasColumn('stock_movements', 'stock_por_deposito');
    }

    /**
     * @param  \App\Models\Article  $created_article
     * @param  \App\Models\Article  $article
     * @param  bool|null            $con_stock_por_deposito  si la base destino tiene las columnas
     *                                                       nuevas; null lo averigua acá
     * @return void
     */
    static function stock_movements($created_article, $article, $con_stock_por_deposito = null) {

        if (is_null($con_stock_por_deposito)) {
            $con_stock_por_deposito = Self::destino_tiene_stock_por_deposito();
        }

        foreach ($article->stock_movements as $stock_movement) {

            $datos = [
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
            ];

            // Stock por deposito del movimiento (ver SetStockPorDeposito), solo si la base destino
            // tiene las columnas. Con el cast del modelo llega como array y se vuelve a guardar
            // como JSON; una base de origen sin estas columnas las da en null.
            if ($con_stock_por_deposito) {
                $datos['stock_anterior']     = $stock_movement->stock_anterior;
                $datos['stock_por_deposito'] = $stock_movement->stock_por_deposito;
            }

            StockMovement::create($datos);
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