<?php

namespace App\Http\Controllers;

use App\Http\Controllers\AfipWsController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CajaHelper;
use App\Http\Controllers\Helpers\CurrentAcountDeleteSaleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleChartHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\SaleProviderOrderHelper;
use App\Http\Controllers\Pdf\SaleAfipTicketPdf;
use App\Http\Controllers\Pdf\SaleDeliveredArticlesPdf;
use App\Http\Controllers\Pdf\SalePdf;
use App\Http\Controllers\Pdf\SaleTicketPdf;
use App\Http\Controllers\SellerCommissionController;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SaleController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = Sale::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        } else {
            $models = $models->where('to_check', 1)
                            ->orWhere('checked', 1);
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    function show($id) {
        return response()->json(['model' => $this->fullModel('Sale', $id)], 200);
    }

    public function store(Request $request) {
        $model = Sale::create([
            'num'                               => $this->num('sales'),
            'client_id'                         => $request->client_id,
            'sale_type_id'                      => $request->sale_type_id,
            'observations'                      => $request->observations,
            'address_id'                        => $request->address_id,
            'current_acount_payment_method_id'  => SaleHelper::getCurrentAcountPaymentMethodId($request),
            'afip_information_id'               => $request->afip_information_id,
            'save_current_acount'               => $request->save_current_acount,
            'price_type_id'                     => $request->price_type_id,
            'discounts_in_services'             => $request->discounts_in_services,
            'surchages_in_services'             => $request->surchages_in_services,
            'employee_id'                       => SaleHelper::getEmployeeId($request),
            'to_check'                          => $request->to_check,
            'user_id'                           => $this->userId(),
        ]);
        SaleHelper::attachProperies($model, $request);
        SaleProviderOrderHelper::createProviderOrder($model, $this);
        $this->sendAddModelNotification('Sale', $model->id);
        SaleHelper::sendUpdateClient($this, $model);
        if (!is_null($model->client_id) && is_null($model->current_acount)) {
            Log::info('No se creo cuenta corriente para la venta id '.$model->id);
        }
        return response()->json(['model' => $this->fullModel('Sale', $model->id)], 201);
    }  

    function update(Request $request, $id) {
        $model = Sale::where('id', $id)
                        ->with('articles')
                        ->first();

        $previus_articles = $model->articles;
        Log::info('previus_articles:');
        
        foreach ($previus_articles as $article) {
            Log::info($article->name);
        }
        SaleHelper::detachItems($model);
        
        $model->discounts_in_services               = $request->discounts_in_services;
        $model->surchages_in_services               = $request->surchages_in_services;
        $model->current_acount_payment_method_id    = $request->current_acount_payment_method_id;
        $model->afip_information_id                 = $request->afip_information_id;
        $model->address_id                          = $request->address_id;
        $model->sale_type_id                        = $request->sale_type_id;
        $model->observations                        = $request->observations;
        $model->to_check                            = $request->to_check;
        $model->checked                             = $request->checked;
        $model->confirmed                           = $request->confirmed;
        $model->employee_id                         = SaleHelper::getEmployeeId($request);
        $model->updated_at                          = Carbon::now();
        $model->save();
        $previus_client_id                          = $model->client_id;

        SaleHelper::attachProperies($model, $request, false, $previus_articles);

        $model->updated_at = Carbon::now();
        $model->save();

        $model = Sale::find($model->id);
        if ($model->client_id) {
            SaleHelper::updateCurrentAcountsAndCommissions($model);
        }

        SaleHelper::updatePreivusClient($model, $previus_client_id);
        $this->sendAddModelNotification('Sale', $model->id);
        SaleHelper::sendUpdateClient($this, $model);
        return response()->json(['model' => $this->fullModel('Sale', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Sale::find($id);
        if (!is_null($model->afip_ticket)) {
            SaleHelper::createNotaCreditoFromDestroy($model);
        } else {
            if ($model->client_id) {
                SaleHelper::deleteCurrentAcountFromSale($model);
                SaleHelper::deleteSellerCommissionsFromSale($model);
                $model->client->pagos_checkeados = 0;
                $model->client->save();
                CurrentAcountHelper::checkSaldos('client', $model->client_id);
                $this->sendAddModelNotification('client', $model->client_id, false);
            }
            foreach ($model->articles as $article) {
                ArticleHelper::resetStock($article, $article->pivot->amount, $model);
                // ArticleHelper::setArticleStockFromAddresses($article);
            }
            $model->delete();
            $this->sendDeleteModelNotification('sale', $model->id);
        }
        return response(null);
    }

    function makeAfipTicket(Request $request) {
        $sale = Sale::find($request->sale_id);
        if (!is_null($sale)) {
            $sale->afip_information_id = $request->afip_information_id;
            $sale->save();
            $ct = new AfipWsController($sale);
            $afip_ticket = $ct->init();
            return response()->json(['sale' => $this->fullModel('Sale', $request->sale_id)], 201);
        }
        return response(null, 200);
    }

    function updatePrices(Request $request, $id) {
        $model = Sale::find($id);
        SaleHelper::updateItemsPrices($model, $request->items);
        if ($model->client_id) {
            SaleHelper::updateCurrentAcountsAndCommissions($model);
        }
        $this->sendAddModelNotification('Sale', $id);
        return response()->json(['model' => $this->fullModel('Sale', $id)], 200);
    }

    function pdf($id, $with_prices, $with_costs) {
        $sale = Sale::find($id);
        SaleHelper::setPrinted($sale);
        $pdf = new SalePdf($sale, (boolean)$with_prices, (boolean)$with_costs);
    }

    function afipTicketPdf($id) {
        $sale = Sale::find($id);
        $pdf = new SaleAfipTicketPdf($sale);
    }

    function deliveredArticlesPdf($id) {
        $sale = Sale::find($id);
        $pdf = new SaleDeliveredArticlesPdf($sale);
    }

    function ticketPdf($id) {
        $sale = Sale::find($id);
        $pdf = new SaleTicketPdf($sale);
    }

    function caja() {
        $caja = CajaHelper::getCaja($this);
        return response()->json(['caja' => $caja], 200);
    }

    function charts($from, $until) {
        $charts = SaleChartHelper::getCharts($this, $from, $until);
        return response()->json(['charts' => $charts], 200);
    }
}
