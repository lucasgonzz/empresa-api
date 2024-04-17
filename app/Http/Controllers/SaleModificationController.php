<?php

namespace App\Http\Controllers;

use App\Models\SaleModification;
use Illuminate\Http\Request;

class SaleModificationController extends Controller
{
    function index($sale_id) {
        $models = SaleModification::where('sale_id', $sale_id)
                                    ->withAll()
                                    ->orderBy('created_at', 'DESC')
                                    ->get();
        return response()->json(['models' => $models], 200);
    }
}
