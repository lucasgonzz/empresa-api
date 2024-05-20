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
use App\Http\Controllers\Helpers\SaleModificationsHelper;
use App\Http\Controllers\Helpers\SaleProviderOrderHelper;
use App\Http\Controllers\Pdf\SaleAfipTicketPdf;
use App\Http\Controllers\Pdf\SaleDeliveredArticlesPdf;
use App\Http\Controllers\Pdf\SalePdf;
use App\Http\Controllers\Pdf\SaleTicketPdf;
use App\Http\Controllers\SellerCommissionController;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\SaleModification;
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
            $models = $models->where('terminada', 1);

            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }

        } else {

            $models = $models->where(function($query) {
                        $query->where('to_check', 1)
                              ->orWhere('checked', 1)
                              ->orWhere('confirmed', 1);
                    })->where('terminada', 0);
            
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
            'omitir_en_cuenta_corriente'        => $request->omitir_en_cuenta_corriente,
            'price_type_id'                     => $request->price_type_id,
            'discounts_in_services'             => $request->discounts_in_services,
            'surchages_in_services'             => $request->surchages_in_services,
            'employee_id'                       => SaleHelper::getEmployeeId($request),
            'to_check'                          => $request->to_check,
            'numero_orden_de_compra'            => $request->numero_orden_de_compra,
            'terminada'                         => SaleHelper::get_terminada(),
            'user_id'                           => $this->userId(),
        ]);

        SaleHelper::check_guardad_cuenta_corriente_despues_de_facturar($model, $this);

        SaleHelper::attachProperies($model, $request);

        SaleProviderOrderHelper::createProviderOrder($model, $this);

        $this->sendAddModelNotification('Sale', $model->id);

        SaleHelper::sendUpdateClient($this, $model);

        if (!is_null($model->client_id) && is_null($model->current_acount)) {
            Log::info('No se creo cuenta corriente para la venta id '.$model->id);
        }

        Log::info('Se creo la venta num: '.$model->num.' id: '.$model->id.'. $sale->employee_id: '.$model->employee_id.', la sesion la tiene: '.$this->userId(false));

        // SaleHelper::log_client($model);

        // SaleHelper::log_articles($model, $model->articles);     

        return response()->json(['model' => $this->fullModel('Sale', $model->id)], 201);
    }  

    function update(Request $request, $id) {
        $model = Sale::where('id', $id)
                        ->with('articles')
                        ->first();

        $previus_articles = $model->articles;

        $request->items = array_reverse($request->items);

        $sale_modification = SaleModificationsHelper::create($model, $this);

        // SaleHelper::log_client($model);

        // SaleHelper::log_articles($model, $previus_articles);

        // SaleHelper::detachItems($model);
        SaleHelper::detachItems($model, $sale_modification);
        
        $previus_client_id                          = $model->client_id;
        
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
        $model->client_id                           = $request->client_id;
        $model->omitir_en_cuenta_corriente          = $request->omitir_en_cuenta_corriente;
        $model->numero_orden_de_compra              = $request->numero_orden_de_compra;
        $model->employee_id                         = SaleHelper::getEmployeeId($request);
        $model->updated_at                          = Carbon::now();
        $model->save();

        SaleHelper::attachProperies($model, $request, false, $previus_articles, $sale_modification);

        $model->updated_at = Carbon::now();
        $model->save();

        $model = Sale::find($model->id);
        if ($model->client_id && !$model->to_check && !$model->checked) {
            SaleHelper::updateCurrentAcountsAndCommissions($model);
        }

        
        $this->sendAddModelNotification('Sale', $model->id);
        SaleHelper::sendUpdateClient($this, $model);


        $sale_modification->estado_despues_de_actualizar = SaleModificationsHelper::get_estado($model);
        $sale_modification->save();

        return response()->json(['model' => $this->fullModel('Sale', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Sale::find($id);
        Log::info('Se quiere eliminar sale N° '.$model->num.'. id: '.$model->id.'. Por el empleado: '.Auth()->user()->name.', doc: '.Auth()->user()->doc_number);
        if (!is_null($model->client)) {
            Log::info('Y pertenece al cliente '.$model->client->name);
        }
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
                if (!is_null($article->stock)) {
                    ArticleHelper::resetStock($article, $article->pivot->amount, $model);
                }
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
            $result = $ct->init();
            return response()->json(['sale' => $this->fullModel('Sale', $request->sale_id), 'result' => $result], 201);
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

    function pdf($id, $with_prices, $with_costs, $precios_netos, $confirmed = 0) {
        $sale = Sale::find($id);
        Log::info('El usuario '.Auth()->user()->name.' id '.Auth()->user()->id.' va a imprimir la venta N° '.$sale->num.' id: '.$sale->id);
        SaleHelper::setPrinted($this, $sale, $confirmed);
        $pdf = new SalePdf($sale, (boolean)$with_prices, (boolean)$with_costs, (boolean)$precios_netos);
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

    function ventas_sin_cobrar() {

        $owner = $this->user();

        $user = $this->user(false);

        $dias = $owner->dias_alertar_empleados_ventas_no_cobradas;

        if ($this->is_owner()) {
            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;
        } else if ($this->is_admin() && is_null($user->dias_alertar_empleados_ventas_no_cobradas)) {
            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;
        } else if (!is_null($user->dias_alertar_empleados_ventas_no_cobradas)) {
            $dias = $user->dias_alertar_empleados_ventas_no_cobradas;
        }

        $ver_solo_las_ventas_suyas = true;

        if ($this->is_owner()) {
            $ver_solo_las_ventas_suyas = false;
        } else if ($user->ver_alertas_de_todos_los_empleados) {
            $ver_solo_las_ventas_suyas = false;
        }

        $sales = Sale::where('user_id', $this->userId())
                        ->whereHas('current_acount', function($q) {
                            return $q->where('status', 'sin_pagar')
                                        ->orWhere('status', 'pagandose');
                        })
                        ->whereHas('client', function ($query) {
                            $query->where('saldo', '>', 300);
                        })
                        ->whereDate('created_at', '<=', Carbon::today()->subDays($dias));

        if ($ver_solo_las_ventas_suyas) {
            $sales = $sales->where('employee_id', $user->id);
        }

        Log::info('ventas_sin_cobrar de hace '.$dias.' dias');
        Log::info('ver_solo_las_ventas_suyas: '.$ver_solo_las_ventas_suyas);

        $sales = $sales->with('client', 'employee', 'current_acount')
                        ->orderBy('created_at', 'DESC')
                        ->get();

        return response()->json(['models' => $sales], 200);
    }

    function set_terminada($sale_id) {
        $sale = Sale::find($sale_id);
        if (!is_null($sale)) {
            $sale->terminada = 1;
            $sale->save();
        }
        return response()->json(['sale' => $this->fullModel('Sale', $sale_id)], 201);
    }

}
