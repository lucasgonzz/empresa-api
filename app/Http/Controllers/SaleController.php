<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CajaHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleChartHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\SaleProviderOrderHelper;
use App\Http\Controllers\Helpers\CurrentAcountDeleteSaleHelper;
use App\Http\Controllers\Pdf\SaleAfipTicketPdf;
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

    public function index($from_date, $until_date = null) {
        $models = Sale::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($until_date)) {
            $models = $models->whereDate('created_at', '>=', $from_date)
                            ->whereDate('created_at', '<=', $until_date);
        } else {
            $models = $models->whereDate('created_at', $from_date);
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
            'address_id'                        => $request->address_id,
            'current_acount_payment_method_id'  => SaleHelper::getCurrentAcountPaymentMethodId($request),
            'afip_information_id'               => $request->afip_information_id,
            'save_current_acount'               => $request->save_current_acount,
            'price_type_id'                     => $request->price_type_id,
            'discounts_in_services'             => $request->discounts_in_services,
            'surchages_in_services'             => $request->surchages_in_services,
            'employee_id'                       => SaleHelper::getEmployeeId($request),
            'user_id'                           => $this->userId(),
        ]);
        SaleHelper::attachProperies($model, $request);
        SaleProviderOrderHelper::createProviderOrder($model, $this);
        $this->sendAddModelNotification('Sale', $model->id);
        SaleHelper::sendUpdateClient($this, $model);
        return response()->json(['model' => $this->fullModel('Sale', $model->id)], 201);
    }  

    function update(Request $request, $id) {
        $model = Sale::where('id', $id)
                        ->with('articles')
                        ->first();
        $model->discounts_in_services = $request->discounts_in_services;
        $model->surchages_in_services  = $request->surchages_in_services;
        $model->current_acount_payment_method_id  = $request->current_acount_payment_method_id;
        $model->afip_information_id  = $request->afip_information_id;
        $model->address_id  = $request->address_id;

        SaleHelper::detachItems($model);
        SaleHelper::attachProperies($model, $request, false);

        if (!is_null($request->client_id) && $request->client_id != $model->client_id) {
            $current_acounts = CurrentAcount::where('sale_id', $model->id)->get();
            if (count($current_acounts) >= 1) {
                foreach ($current_acounts as $current_acount) {
                    $current_acount->delete();
                }
                CurrentAcountHelper::checkSaldos('client', $model->client_id);
            }
            $model->client_id = $request->client_id;
        }
        $model->updated_at = Carbon::now();
        $model->save();
        $model = Sale::where('id', $model->id)
                        ->withAll()
                        ->first();
        if ($model->client_id) {
            SaleHelper::updateCurrentAcountsAndCommissions($model);
        }
        $this->sendAddModelNotification('Sale', $model->id);
        SaleHelper::sendUpdateClient($this, $model);
        return response()->json(['model' => $model], 200);
    }

    public function destroy($id) {
        $model = Sale::find($id);
        if ($model->client_id) {
            // $current_acount = new CurrentAcountController();
            // $current_acount->deleteFromSale($model);

            CurrentAcountDeleteSaleHelper::deleteSale($model);
            $commission = new SellerCommissionController();
            $commission->deleteFromSale($model);
            CurrentAcountHelper::checkSaldos('client', $model->client_id);
            $this->sendAddModelNotification('client', $model->client_id, false);
        }
        foreach ($model->articles as $article) {
            Log::info('reseteando stock de '.$article->name);
            ArticleHelper::resetStock($article, $article->pivot->amount);
        }
        $model->delete();
        Log::info('se elimino venta');
        return response(null);
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

    function pdf($id, $with_prices, $with_costs, $with_commissions) {
        $sale = Sale::find($id);
        $pdf = new SalePdf($sale, (boolean)$with_prices, (boolean)$with_costs, (boolean)$with_commissions);
    }

    function afipTicketPdf($id) {
        $sale = Sale::find($id);
        $pdf = new SaleAfipTicketPdf($sale);
    }

    function deliveredArticlesPdf($id) {
        $sale = Sale::find($id);
        $pdf = new SaleDeliveredArticlesPdf($sale);
    }

    function ticketPdf($id, $address_id = null) {
        $sale = Sale::find($id);
        if (!is_null($address_id)) {
            $address = Address::find($address_id);
        } else {
            $address = null;
        }
        $pdf = new SaleTicketPdf($sale, $address);
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
