<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\expense\ExpenseCajaHelper;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = Expense::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Expense::create([
            'num'                                   => $this->num('expenses'),
            'expense_concept_id'                    => $request->expense_concept_id,
            'amount'                                => $request->amount,
            'current_acount_payment_method_id'      => $request->current_acount_payment_method_id,
            'importe_iva'                           => $request->importe_iva,
            'observations'                          => $request->observations,
            'caja_id'                               => $request->caja_id,
            'created_at'                            => $request->created_at,
            'user_id'                               => $this->userId(),
        ]);

        ExpenseCajaHelper::guardar_movimiento_caja($model);

        return response()->json(['model' => $this->fullModel('Expense', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Expense', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Expense::find($id);
        $model->expense_concept_id                    = $request->expense_concept_id;
        $model->amount                                = $request->amount;
        $model->current_acount_payment_method_id      = $request->current_acount_payment_method_id;
        $model->importe_iva                           = $request->importe_iva;
        $model->observations                          = $request->observations;
        $model->caja_id                               = $request->caja_id;
        $model->created_at                            = $request->created_at;
        $model->save();

        ExpenseCajaHelper::editar_movimiento_caja($model);

        return response()->json(['model' => $this->fullModel('Expense', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Expense::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Expense', $model->id);
        return response(null);
    }
}
