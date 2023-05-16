<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\SellerCommissionHelper;
use App\Models\SellerCommission;
use Illuminate\Http\Request;

class SellerCommissionController extends Controller
{
    
    function index($model_id, $from_date, $until_date = null) {
        $models = SellerCommission::whereDate('created_at', '>=', $from_date)
                            ->withAll()
                            ->where('status', 'active')
                            ->orderBy('created_at', 'ASC');
        if (!is_null($until_date)) {
            $models = $models->whereDate('created_at', '<=', $until_date);
        }
        if ($model_id != 0) {
            $models = $models->where('seller_id', $model_id);
        }
        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    function saldoInicial(Request $request) {
        $seller_commission = SellerCommission::create([
            'num'           => $this->num('seller_commissions'),
            'seller_id'     => $request->seller_id,
            'description'   => SellerCommissionHelper::getDescription(),
            'debe'          => !is_null($request->debe) ? $request->debe : null,
            'haber'         => !is_null($request->haber) ? $request->haber : null,
            'saldo'         => !is_null($request->debe) ? $request->debe : -$request->haber,
            'user_id'       => $this->userId(),
        ]);
        return response()->json(['model' => $this->fullModel('Seller', $request->seller_id)], 201);
    }

    function pago(Request $request) {
        $seller_commission = SellerCommission::create([
            'num'           => $this->num('seller_commissions'),
            'seller_id'     => $request->seller_id,
            'description'   => SellerCommissionHelper::getDescription(),
            'haber'         => $request->pago,
            'status'        => 'active',
            'user_id'       => $this->userId(),
        ]);
        $seller_commission->saldo = SellerCommissionHelper::getSaldo($seller_commission) - $seller_commission->haber;
        $seller_commission->save();
        return response()->json(['model' => $seller_commission], 201);
    }

    function destroy($id) {
        $model = SellerCommission::find($id);
        $model->delete();
        SellerCommissionHelper::checkSaldos($model);
        return response(null, 200);
    }
}
