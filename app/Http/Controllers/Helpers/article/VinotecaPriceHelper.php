<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class VinotecaPriceHelper {

    static function calcular_presentacion($article, $final_price) {
        
        if ($article->presentacion) {
            $final_price = $final_price * $article->presentacion;
        }
        return $final_price;
    }
}