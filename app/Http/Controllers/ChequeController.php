<?php

namespace App\Http\Controllers;

use App\Models\CurrentAcountCurrentAcountPaymentMethod;
use Illuminate\Http\Request;

class ChequeController extends Controller
{
    function index() {
        $models = CurrentAcountCurrentAcountPaymentMethod::where('current_acount_payment_method_id', 1)
                                                            ->where('user_id', $this->userId())
                                                            ->orderBy('created_at', 'ASC')
                                                            ->with('current_acount.client')
                                                            ->get();
        $_models = [];

        foreach ($models as $model) {
            
            if (is_null($model->current_acount)) {

                $model->current_acount = [
                    'client'    => [
                        'name'  => null,
                    ],
                ];
            } else {
                $_models[] = $model;
            }
        }

        return response()->json(['models' => $_models], 200);
    }
}
