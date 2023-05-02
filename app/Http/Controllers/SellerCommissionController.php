<?php

namespace App\Http\Controllers;

use App\Models\SellerCommission;
use Illuminate\Http\Request;

class SellerCommissionController extends Controller
{
    
    function index($model_id, $from_date, $until_date = null) {
        $models = SellerCommission::whereDate('created_at', '>=', $from_date)
                            ->withAll()
                            ->orderBy('created_at', 'DESC');
        if (!is_null($until_date)) {
            $models = $models->whereDate('created_at', '<=', $until_date);
        }
        if ($model_id != 0) {
            $models = $models->where('partner_id', $model_id);
        }
        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }
}
