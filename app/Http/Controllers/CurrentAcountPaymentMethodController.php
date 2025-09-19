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

    function store(Request $request) {
        $model = CurrentAcountPaymentMethod::create([
            'name'  => $request->name,
        ]);
        return response()->json(['model' => $model], 201);
    }

    public function update(Request $request, $id) {
        $model = CurrentAcountPaymentMethod::find($id);
        $model->name                = $request->name;
        $model->save();
        return response()->json(['model' => $model], 200);
    }
}
