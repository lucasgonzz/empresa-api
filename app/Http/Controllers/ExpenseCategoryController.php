<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{

    public function index() {
        $models = ExpenseCategory::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ExpenseCategory::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('ExpenseCategory', $model->id);
        return response()->json(['model' => $this->fullModel('ExpenseCategory', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ExpenseCategory', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ExpenseCategory::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('ExpenseCategory', $model->id);
        return response()->json(['model' => $this->fullModel('ExpenseCategory', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ExpenseCategory::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ExpenseCategory', $model->id);
        return response(null);
    }
}
