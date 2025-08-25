<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Stock\SetArticleStock\CheckCartAmount;
use App\Http\Controllers\Stock\SetArticleStock\CheckFromAddress;
use App\Http\Controllers\Stock\SetArticleStock\CheckGlobalStock;
use App\Http\Controllers\Stock\SetArticleStock\CheckToAddress;
use App\Http\Controllers\Stock\SetArticleStock\CheckVariants;
use App\Models\ConceptoStockMovement;

class SetArticleStock  {
    
    static function set_article_stock($stock_movement, $article, $set_updated_at, $user_id = null) {

        if (!is_null($article)) {

            CheckFromAddress::check_from_address($stock_movement, $article);

            CheckToAddress::check_to_address($stock_movement, $article);

            CheckVariants::check_article_variant($stock_movement, $article);

            CheckGlobalStock::check_global_stock($stock_movement, $article, $set_updated_at);
    
            ArticleHelper::setArticleStockFromAddresses($article, false, $user_id);

            ArticleHelper::checkAdvises($article);
            
            CheckCartAmount::check_cart_amount($stock_movement, $article);

        } 
    }



 }