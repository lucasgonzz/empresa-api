<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\PaymentPlan\PaymentPlanService;
use Illuminate\Http\Request;

class PaymentPlanController extends Controller
{

    // FROM DATES
    public function index($from_date = null, $until_date = null) {
        $models = PaymentPlan::where('user_id', $this->userId())
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

    
    public function store(Request $request)
    {
        $sale = Sale::find($request->sale_id); // ajustá

        $plan = app(PaymentPlanService::class)->crear_plan_y_cuotas(
            $request,
            $sale
        );

        return response()->json(['model'    => $plan], 201);
    }
}
