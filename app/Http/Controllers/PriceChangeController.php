<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Models\PriceChange;
use Illuminate\Http\Request;

class PriceChangeController extends Controller
{

    function index($article_id) {
        $models = PriceChange::where('article_id', $article_id)
                            ->orderBy('created_at', 'DESC')
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    static function store($article, $auth_user_id = null) {
        if (is_null($auth_user_id)) {
            $auth_user_id = UserHelper::userId(false);
        }
        PriceChange::create([
            'article_id'    => $article->id,
            'cost'          => $article->cost,
            'price'         => $article->price,
            'final_price'   => $article->final_price,
            'employee_id'   => $auth_user_id,
        ]);
    }
}
