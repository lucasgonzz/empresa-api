<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Devoluciones\RegresarStockHelper;
use App\Http\Controllers\Helpers\Devoluciones\UpdateSaleHelper;
use App\Models\AfipTicket;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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

        DB::beginTransaction();

        try {

            $model_id = null;
            $credit_account_id = null;

            if (
                $request->generar_current_acount
                && !is_null($request->client_id)
            ) {
                $model_id = $request->client_id;
                $credit_account_id = CreditAccount::where('model_name', 'client')
                                                    ->where('model_id', $model_id)
                                                    ->where('moneda_id', 1)
                                                    ->first()
                                                    ->id;
            }


            $nota_credito = CurrentAcountHelper::notaCredito(
                $credit_account_id,
                $request->total_devolucion, 
                $request->observaciones, 
                'client', 
                $model_id, 
                $request->sale_id, 
                $request->items,
                $request->descriptions,
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
                
                $afip_ticket = AfipTicket::find($request->facturar_nota_credito);
                $afip_helper = new AfipNotaCreditoHelper($afip_ticket, $nota_credito);
                $afip_helper->init();
            }
            
            DB::commit();

            return response(null, 201);

        } catch(\Throwable $e) {

            DB::rollBack();

            Log::info('Error enn nota de credito');
            Log::info($e);

            return response(null, 500);
        }


    }
}
