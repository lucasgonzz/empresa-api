<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\CurrentAcountPaymentMethodDiscount;
use Illuminate\Http\Request;

class CurrentAcountPaymentMethodDiscountController extends Controller
{

    public function index() {
        $models = CurrentAcountPaymentMethodDiscount::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = CurrentAcountPaymentMethodDiscount::create([
            'current_acount_payment_method_id'                  => $request->current_acount_payment_method_id,
            'discount_percentage'                  => $request->discount_percentage,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('CurrentAcountPaymentMethodDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('CurrentAcountPaymentMethodDiscount', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('CurrentAcountPaymentMethodDiscount', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = CurrentAcountPaymentMethodDiscount::find($id);
        $model->current_acount_payment_method_id                = $request->current_acount_payment_method_id;
        $model->discount_percentage                = $request->discount_percentage;
        $model->save();
        $this->sendAddModelNotification('CurrentAcountPaymentMethodDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('CurrentAcountPaymentMethodDiscount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = CurrentAcountPaymentMethodDiscount::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('CurrentAcountPaymentMethodDiscount', $model->id);
        return response(null);
    }
}
