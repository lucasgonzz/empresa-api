<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\CurrentAcountPaymentMethod;
use Illuminate\Http\Request;

class CurrentAcountPaymentMethodController extends Controller
{

    public function index() {
        $models = CurrentAcountPaymentMethod::orderBy('created_at', 'DESC')
                                        ->withAll()
                                        ->get();
        return response()->json(['models' => $models], 200);
    }
}
