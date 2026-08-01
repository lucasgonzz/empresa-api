<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ExpenseConcept;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        /**
         * Si al concepto le cambiaron la categoria, los gastos ya cargados con ese concepto se mueven
         * con el. La categoria es una agrupacion de reporte, no un dato historico del comprobante:
         * si el usuario reorganiza sus categorias, espera que los reportes viejos reflejen la
         * organizacion nueva.
         *
         * Se escribe con el query builder y no con Eloquent a proposito: son N gastos, un solo UPDATE,
         * y ademas asi no se les toca el updated_at a gastos que el usuario no edito.
         */
        DB::table('expenses')
            ->where('expense_concept_id', $model->id)
            ->update(['expense_category_id' => $model->expense_category_id]);

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
