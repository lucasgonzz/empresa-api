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
                                                            ->get();
        return response()->json(['models' => $models], 200);
    }
}
