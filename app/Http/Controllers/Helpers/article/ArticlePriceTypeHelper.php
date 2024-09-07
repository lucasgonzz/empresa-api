<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePriceTypeHelper {

    static function attach_price_types($article, $price_types) {
        
        Log::info('attach_price_types para '.$article->name);

        foreach ($price_types as $price_type) {

            $relation = $article->price_types()->find($price_type['id']);

            $article->price_types()->syncWithoutDetaching($price_type['id']);

            Log::info('price_type: ');
            Log::info($price_type);

            $percentage = $price_type['pivot']['percentage'];

            Log::info('percentage: ');
            Log::info($percentage);

            $article->price_types()->updateExistingPivot($price_type['id'], [
                'percentage'    => $percentage,
            ]);
        }
    }
}