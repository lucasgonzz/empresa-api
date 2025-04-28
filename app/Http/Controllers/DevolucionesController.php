<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Devoluciones\RegresarStockHelper;
use App\Http\Controllers\Helpers\Devoluciones\UpdateSaleHelper;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Illuminate\Http\Request;

class DevolucionesController extends Controller
{
    function search_sale($num) {
        $sale = Sale::where('user_id', $this->userId())
                    ->where('num', $num)
                    ->withAll()
                    ->first();

        return response()->json(['sale' => $sale], 200);
    }

    function store(Request $request) {

        $model_id = null;
        if (
            $request->generar_current_acount
            && !is_null($request->client_id)
        ) {
            $model_id = $request->client_id;
        }

        $nota_credito = CurrentAcountHelper::notaCredito(
            $request->total_devolucion, 
            $request->observaciones, 
            'client', 
            $model_id, 
            $request->sale_id, 
            $request->items
        );

        GeneralHelper::attachModels($nota_credito, 'discounts', $request->discounts, ['percentage']);
        GeneralHelper::attachModels($nota_credito, 'surchages', $request->surchages, ['percentage']);

        if ($request->sale_id) {
            UpdateSaleHelper::update_sale_returned_items($request);
        }

        if ($request->regresar_stock) {
            RegresarStockHelper::regresar_stock($request);
        }

        if ($request->facturar_nota_credito) {
            
            $sale = Sale::find($request->sale_id);
            $afip_helper = new AfipNotaCreditoHelper($sale, $nota_credito);
            $afip_helper->init();
        }

        return response(null, 201);

    }
}
