<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\CreditCard;
use Illuminate\Http\Request;

class CreditCardController extends Controller
{

    public function index() {
        $models = CreditCard::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = CreditCard::create([
            'num'                   => $this->num('credit_cards'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('credit_card', $model->id);
        return response()->json(['model' => $this->fullModel('CreditCard', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('CreditCard', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = CreditCard::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('credit_card', $model->id);
        return response()->json(['model' => $this->fullModel('CreditCard', $model->id)], 200);
    }

    public function destroy($id) {
        $model = CreditCard::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('CreditCard', $model->id);
        return response(null);
    }
}
