<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use Illuminate\Http\Request;

class CreditAccountController extends Controller
{

    function index($credit_account_id, $cantidad_movimientos) {
        $models = CurrentAcount::where('credit_account_id', $credit_account_id)
                            // ->where('model_name', $model_name)
                            // ->where('model_id', $model_id)
                            ->orderBy('created_at', 'DESC')
                            ->take($cantidad_movimientos)
                            ->with('current_acount_payment_methods')
                            ->with('pagado_por')
                            ->with('cheques')
                            ->with('sale.afip_ticket')
                            // ->get();
                            ->get();

        if (!UserHelper::user()->cc_ultimas_arriba) {
            $models = $models->reverse()->values();
        }
                            
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = CreditAccount::create([
            'num'                   => $this->num(''),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('CreditAccount', $model->id);
        return response()->json(['model' => $this->fullModel('CreditAccount', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('CreditAccount', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = CreditAccount::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('CreditAccount', $model->id);
        return response()->json(['model' => $this->fullModel('CreditAccount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = CreditAccount::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('CreditAccount', $model->id);
        return response(null);
    }
}
