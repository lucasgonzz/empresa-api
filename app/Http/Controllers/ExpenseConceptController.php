<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ExpenseConcept;
use Illuminate\Http\Request;

class ExpenseConceptController extends Controller
{

    public function index() {
        $models = ExpenseConcept::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ExpenseConcept::create([
            'num'                   => $this->num('expense_concepts'),
            'name'                  => $request->name,
            'expense_category_id'   => $request->expense_category_id,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('ExpenseConcept', $model->id);
        return response()->json(['model' => $this->fullModel('ExpenseConcept', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ExpenseConcept', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ExpenseConcept::find($id);
        $model->name                    = $request->name;
        $model->expense_category_id     = $request->expense_category_id;
        $model->save();
        $this->sendAddModelNotification('ExpenseConcept', $model->id);
        return response()->json(['model' => $this->fullModel('ExpenseConcept', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ExpenseConcept::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ExpenseConcept', $model->id);
        return response(null);
    }
}
