<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Pdf\SuperBudgetPdf;
use App\Http\Controllers\SuperBudgetHelper;
use App\Models\SuperBudget;
use Illuminate\Http\Request;

class SuperBudgetController extends Controller
{
    function index() {
        $models = SuperBudget::withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    function store(Request $request) {
        $model = SuperBudget::create([
            'client'   => $request->client,
        ]);
        SuperBudgetHelper::attachFeatures($model, $request->super_budget_features);
        return response()->json(['model' => $this->fullModel('App\SuperBudget', $model->id), 201]);
    }

    function update(Request $request, $id) {
        $model = SuperBudget::find($id);
        $model->client = $model->client;
        $model->save();
                            
        SuperBudgetHelper::attachFeatures($model, $request->super_budget_features);
        return response()->json(['model' => $this->fullModel('App\SuperBudget', $model->id), 201]);
    }

    function pdf() {
        $model = SuperBudget::orderBy('id', 'DESC')
                            ->first();
        $pdf = new SuperBudgetPdf($model);
    }
}