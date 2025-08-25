<?php

namespace App\Http\Controllers\Helpers\Seeders;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\Article;
use App\Models\Description;
use App\Models\Image;
use App\Models\PriceType;


class ArticleSeederHelper {

    public $addresses;

    function __construct() {
        $this->addresses = Address::all();
    }
	   
    function set_provider($created_article, $article) {

        if (isset($article['provider_id'])) {
            $created_article->providers()->attach($article['provider_id'], [
                                        'cost'  => $article['cost'],
                                        'amount' => $article['stock'],
                                    ]);
        }
    }

    function set_images($created_article, $article, $rubro, $formato = 'jpg') {
        $category_name = strtolower($created_article->category->name);
        Image::create([
            'imageable_type'                            => 'article',
            'imageable_id'                              => $created_article->id,
            env('IMAGE_URL_PROP_NAME', 'image_url')     => env('APP_IMAGES_URL').'/storage/'.$rubro.'/'.str_replace(' ', '_', $category_name).'.'.$formato,
        ]);
    }

    function set_stock_movement($created_article, $article) {

        $ct = new StockMovementController();
        
        $data['model_id'] = $created_article->id;
        $data['provider_id'] = $created_article->provider_id;

        if (count($this->addresses) >= 1) {

            $segundos = 0;

            foreach ($this->addresses as $address) {
            
                $data['to_address_id'] = $address->id;
                $data['amount'] = rand(10, 100);
                $data['concepto_stock_movement_name'] = 'Creacion de deposito';

                $ct->crear($data, false, null, null, $segundos);
                $segundos += 5;
            }
        } else {
            $data['amount'] = rand(10, 100);
            $ct->crear($data);
        }
    }

}