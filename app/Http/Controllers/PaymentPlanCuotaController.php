<?php

namespace App\Http\Controllers;

use App\Models\PaymentPlanCuota;
use Illuminate\Http\Request;

class PaymentPlanCuotaController extends Controller
{
    // FROM DATES
    public function index($estado, $from_date = null, $until_date = null) {
        $models = PaymentPlanCuota::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->where('estado', $estado)
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('fecha_vencimiento', '>=', $from_date)
                                ->whereDate('fecha_vencimiento', '<=', $until_date);
            } else {
                $models = $models->whereDate('fecha_vencimiento', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    function destroy($id) {
        $model = PaymentPlanCuota::find($id);
        $model->delete();
        return response(null, 200);
    }
}
