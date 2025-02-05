<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

use App\Http\Controllers\Helpers\CartArticleAmountInsificienteHelper;

class CheckCartAmount {
    
    static function check_cart_amount($stock_movement, $article) {

        $concepto = $stock_movement->concepto->name;
        
        if (
            $concepto != 'Mov entre depositos'
            && $concepto != 'Mov manual entre depositos' 
            && $concepto != 'Importacion de excel' 
        ) {
            CartArticleAmountInsificienteHelper::checkCartsAmounts($article);
        }
    }


}