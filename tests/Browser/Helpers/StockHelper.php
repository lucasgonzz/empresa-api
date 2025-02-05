<?php

namespace Tests\Browser\Helpers;

use App\Models\Address;
use App\Models\Article;


class StockHelper
{
    
    static function check_global_stock($article_name, $stock)
    {

        $article = Article::where('name', $article_name)->first();

        $instance->assertDatabaseHas('articles', [
            'id'                => $article->id,
            'stock'             => $stock,
        ]);
        
    }
    
    static function get_address_stock_data($article_name, $address_street, $stock)
    {

        $article = Article::where('name', $article_name)->first();
        $address = Address::where('street', $address_street)->first();

        return [
            'address_id' => $address->id,
            'article_id' => $article->id,
            'amount'     => $stock,
        ];
        
    }
}
