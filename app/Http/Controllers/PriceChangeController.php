<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PriceChangeController extends Controller
{

    function index($article_id) {
        $models = PriceChange::where('article_id', $article_id)
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    static function store($article, $auth_user_id = null) {
        
        if (is_null($auth_user_id)) {
            $auth_user_id = UserHelper::userId(false);
        }

        $price_change = PriceChange::create([
            'article_id'    => $article->id,
            'cost'          => $article->cost,
            'price'         => $article->price,
            'final_price'   => $article->final_price,
            'employee_id'   => $auth_user_id,
        ]);

        if (count($article->price_types) >= 1) {

            foreach ($article->price_types as $article_price_type) {
                
                $price_change->price_types()->attach($article_price_type->id, [
                    'final_price'   => $article_price_type->pivot->final_price,
                ]);
            }
        }

    }
}
