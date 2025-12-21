<?php

namespace App\Http\Controllers;

use App\Exports\SalesFullExport;
use App\Http\Controllers\AfipWsController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CajaHelper;
use App\Http\Controllers\Helpers\CurrentAcountDeleteSaleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleChartHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\SaleModificationsHelper;
use App\Http\Controllers\Helpers\SaleProviderOrderHelper;
use App\Http\Controllers\Helpers\comisiones\ventasTerminadas\VentaTerminadaComisionesHelper;
use App\Http\Controllers\Helpers\sale\AcopioHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Http\Controllers\Helpers\sale\DeleteSaleHelper;
use App\Http\Controllers\Helpers\sale\SaleNotaCreditoAfipHelper;
use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Http\Controllers\Pdf\EtiquetaEnvioPdf;
use App\Http\Controllers\Pdf\SaleAfipTicketPdf;
use App\Http\Controllers\Pdf\SaleDeliveredArticlesPdf;
use App\Http\Controllers\Pdf\SalePdf;
use App\Http\Controllers\Pdf\SaleTicketPdf;
use App\Http\Controllers\Pdf\SaleTicketRaw;
use App\Http\Controllers\SellerCommissionController;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\SaleModification;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class SaleController extends Controller
{


    public function index($from_depositos, $from_date = null, $until_date = null) {
        $models = Sale::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();

        if ($from_depositos) {

            $models = $models->where(function($query) {
                                    $query->where('to_check', 1)
                                          ->orWhere('checked', 1)
                                          ->orWhere('confirmed', 1);
                                })->where('terminada', 0);

            Log::info('NO tiene que estar terminada');
        } else {
            
            $models = $models->where('terminada', 1);
            Log::info('tiene que estar terminada');
        }

        if (!is_null($from_date)) {

            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }

        } 
        // else {

        //     // Si entra aca es porque se esta llamando desde DEPOSITO
        //     // Porque solo de esa seccion se puede llamar sin que sea from_date

        //     $models = $models->where(function($query) {
        //                             $query->where('to_check', 1)
        //                                   ->orWhere('checked', 1)
        //                                   ->orWhere('confirmed', 1);
        //                         })->where('terminada', 0);
            
        // }
        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    public function por_entregar($from_depositos, $from_date = null) {
        $models = Sale::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll()
                        ->where('terminada', 0)
                        ->whereBetween('fecha_entrega', [$from_depositos, $from_date])
                        ->get();

        return response()->json(['models' => $models], 200);
    }

    function show($id) {
        return response()->json(['model' => $this->fullModel('Sale', $id)], 200);
    }

    function venta_ya_cread($request) {
        $sale_ya_creada = Sale::where('user_id', $this->userId())
                                ->where('client_id', $request->client_id)
                                ->where('employee_id', SaleHelper::getEmployeeId($request))
                                ->where('total', $request->total)
                                ->where('created_at', '>=', Carbon::now()->subSeconds(5))
                                ->orderBy('created_at', 'DESC')
                                ->first();
        if (!is_null($sale_ya_creada)) {
            Log::info('Casi se vuelve a crear venta N° '.$sale_ya_creada->num.'. Total: '.$sale_ya_creada->total.'. Hora: '.$sale_ya_creada->created_at->format('H:i:s'));
            return true;
        }
        return false;
    }

    public function store(Request $request) {

        DB::beginTransaction();

        Log::info($this->user(false)->name.' va a crear venta');

        if ($this->venta_ya_cread($request)) {
            Log::info('No se volvio a crear la venta');
            return;
        }

        try {

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
                'confirmed'                         => SaleHelper::get_confirmed($request->to_check),
                'numero_orden_de_compra'            => $request->numero_orden_de_compra,
                'sub_total'                         => $request->sub_total,
                'total'                             => $request->total,
                'terminada'                         => SaleHelper::get_terminada($request->to_check, $request->fecha_entrega),
                'terminada_at'                      => SaleHelper::get_terminada_at($request->to_check, $request->fecha_entrega),
                'seller_id'                         => SaleHelper::get_seller_id($request),
                'cantidad_cuotas'                   => $request->cantidad_cuotas,
                'cuota_descuento'                   => $request->cuota_descuento,
                'cuota_recargo'                     => $request->cuota_recargo,
                'caja_id'                           => $request->caja_id,
                'afip_tipo_comprobante_id'          => $request->afip_tipo_comprobante_id,
                'fecha_entrega'                     => $request->fecha_entrega,
                'moneda_id'                         => !is_null($request->moneda_id) ? $request->moneda_id : 1,
                'valor_dolar'                       => $request->valor_dolar,
                'incoterms'                         => $request->incoterms,
                'descuento'                         => round($request->descuento, 2, PHP_ROUND_HALF_UP),
                'user_id'                           => $this->userId(),
            ]);

            if (is_null($model->price_type_id)) {
                if (!is_null($model->client) && !is_null($model->client->price_type_id)) {
                    $model->price_type_id = $model->client->price_type_id;
                    $model->save();
                }
            }

            SaleHelper::check_guardad_cuenta_corriente_despues_de_facturar($model, $this);

            SaleHelper::attachProperies($model, $request);

            SaleHelper::set_total_a_facturar($model, $request);

            SaleProviderOrderHelper::createProviderOrder($model, $this);

            // Por el error de Pack
            // SaleHelper::check_que_esten_todos_los_articulos($model);

            $this->sendAddModelNotification('Sale', $model->id);

            SaleHelper::sendUpdateClient($this, $model);

            Log::info('Se creo sale n°: '.$model->num.'. Total: '.$model->total);



            $total_helper = (int)SaleHelper::getTotalSale($model, true, true, false, true);
            $total_sale = (int)$model->total;

            // Calcula la diferencia absoluta
            $diferencia = abs($total_helper - $total_sale);

            if ($diferencia > 3) {
                Log::info('Total mal para la venta '.$model->id);
                Log::info('total_sale '.$total_sale);
                Log::info('total_helper '.$total_helper);

                $message = 'El total de la venta no corresponde con los productos ingresados';
                
                throw new Exception($message);
            }

            DB::commit();

            return response()->json(['model' => $this->fullModel('Sale', $model->id)], 201);

        } catch(\Throwable $e) {

            DB::rollBack();

            Log::info($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }


    }  

    function update(Request $request, $id) {

        DB::beginTransaction();
        
        try {

            $model = Sale::where('id', $id)
                            ->with('articles')
                            ->first();

            $previus_articles = $model->articles;
            $previus_combos = $model->combos;
            $previus_promos = $model->promocion_vinotecas;

            $request->items = array_reverse($request->items);

            $sale_modification = SaleModificationsHelper::create($model, $this);

            $se_esta_confirmando = SaleHelper::get_se_esta_confirmando($request, $model);

            SaleHelper::detachItems($model, $sale_modification);
            
            $previus_client_id                          = $model->client_id;
            
            
            $model->actualizandose_por_id               = null;
           
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
            
            $model->seller_id                           = $request->seller_id;

            $model->sub_total                           = $request->sub_total;
            
            $model->total                               = $request->total;

            $model->fecha_entrega                       = $request->fecha_entrega;

            // $model->valor_dolar                         = $request->valor_dolar;
            
            $model->employee_id                         = SaleHelper::getEmployeeId($request);
            
            $model->updated_at                          = Carbon::now();
            
            $model->save();

            SaleHelper::attachProperies($model, $request, false, $previus_articles, $previus_combos, $previus_promos, $sale_modification, $se_esta_confirmando);

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

            DB::commit();
            return response()->json(['model' => $this->fullModel('Sale', $model->id)], 200);
        
        } catch(\Throwable $e) {
            DB::rollBack();
            Log::info($e);
            return response()->json(['error' => true], 500);
        } 
    }

    public function destroy($id) {
        $model = Sale::find($id);
        Log::info('Se quiere eliminar sale N° '.$model->num.'. id: '.$model->id.'. Por el empleado: '.Auth()->user()->name.', doc: '.Auth()->user()->doc_number);
        if (!is_null($model->client)) {
            Log::info('Y pertenece al cliente '.$model->client->name);
        }

        ArticlePurchaseHelper::borrar_article_purchase_actuales($model);
        
    
        if ($model->client_id) {

            /* 
                Si no es NULL, es porque se genero nota de credito de afip.
                En ese caso, no se elimina la cuenta corriente de la venta
                Porque ya tiene la nota de credito en la C/C
            */ 
            if (count($model->nota_credito_afip_tickets) == 0) {

                SaleHelper::deleteCurrentAcountFromSale($model);
            }

            SaleHelper::deleteSellerCommissionsFromSale($model);

            if (is_null($model->client->deleted_at)) {

                $credit_account = CreditAccount::where('model_name', 'client')
                                                    ->where('model_id', $model->client_id)
                                                    ->where('moneda_id', $model->moneda_id)
                                                    ->first();
                
                // $model->client->pagos_checkeados = 0;
                // $model->client->save();
                CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);
                $this->sendAddModelNotification('client', $model->client_id, false);
            }
        }
        $model->delete();

        $this->sendDeleteModelNotification('sale', $model->id);

        DeleteSaleHelper::regresar_stock($model);

        return response(null);
    }

    function makeAfipTicket(Request $request) {

        $sale = Sale::find($request->sale_id);

        if (!is_null($sale)) {

            $afip = new MakeAfipTicket();

            $afip->make_afip_ticket([
                'sale_id'                   => $request->sale_id,
                'afip_information_id'       => $request->ventas_afip_information_id,
                'afip_tipo_comprobante_id'  => $request->afip_tipo_comprobante_id,
                'afip_fecha_emision'        => $request->afip_fecha_emision,
                'facturar_importe_personalizado'        => $request->monto_a_facturar,
                'incoterms'                 => $request->incoterms,
            ]);


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
        // $this->sendAddModelNotification('Sale', $id);
        return response()->json(['model' => $this->fullModel('Sale', $id)], 200);
    }

    function pdf($id, $with_prices, $with_costs, $precios_netos, $confirmed = 0) {
        $sale = Sale::find($id);

        
        $user = User::where('id', $sale->user_id)
                            ->with('extencions')
                            ->first();

        SaleHelper::setPrinted($this, $sale, $confirmed, $user);
        $pdf = new SalePdf($sale, $user, (boolean)$with_prices, (boolean)$with_costs, (boolean)$precios_netos);
    }

    function afipTicketPdf($id) {
        $sale = Sale::where('id', $id)->withTrashed()->first();
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

    function ticketRaw($id) {
        $sale = Sale::find($id);
        new SaleTicketRaw($sale);
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
                            return $q->where('debe', '>', 0)
                                        ->where('status', 'sin_pagar')
                                        ->orWhere('status', 'pagandose')
                                        ->where(function ($query) {
                                            $query->whereNull('pagandose')
                                            ->orWhereRaw('debe - pagandose > 300');
                                        });
                        })
                        ->whereHas('client', function ($query) {
                            $query->where('saldo', '>', 300);
                        })
                        ->whereDate('created_at', '<=', Carbon::today()->subDays($dias));

        if ($ver_solo_las_ventas_suyas) {
            $sales = $sales->where('employee_id', $user->id);
        }

        // Log::info('ventas_sin_cobrar de hace '.$dias.' dias');
        // Log::info('ver_solo_las_ventas_suyas: '.$ver_solo_las_ventas_suyas);

        $sales = $sales->with('client', 'employee', 'current_acount')
                        ->orderBy('created_at', 'DESC')
                        ->get();

        $clients = VentasSinCobrarHelper::ordenar_por_clientes($sales);

        return response()->json(['models' => $clients], 200);
    }

    function set_terminada($sale_id) {
        $sale = Sale::find($sale_id);
        if (!is_null($sale)) {
            $sale->terminada = 1;
            $sale->terminada_at = Carbon::now();
            $sale->save();

            new VentaTerminadaComisionesHelper($sale, $this->userId(false));
        }
        return response()->json(['sale' => $this->fullModel('Sale', $sale_id)], 201);
    }

    function nota_credito_afip($sale_id) {
        $sale = Sale::find($sale_id);

        SaleNotaCreditoAfipHelper::crear_nota_de_credito_afip($sale);
        return response(null, 201);
    }

    function clear_actualizandose_por($sale_id) {
        $sale = Sale::find($sale_id);
        $sale->actualizandose_por_id = null;
        $sale->timestamps = false;
        $sale->save();
        return response(null, 200);

    }

    function excel_export($from_date, $until_date = null) {

        $models = Sale::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC');

        if (!is_null($from_date)) {

            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }

        } 
        $models = $models->get();

        return Excel::download(new SalesFullExport($models), 'ventas_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');

    }

    function etiqueta_envio($sale_id) {
        $sale = Sale::find($sale_id);
        new EtiquetaEnvioPdf($sale);
    }

    function unidades_entregadas(Request $request, $sale_id) {
        $sale = Sale::find($sale_id);

        AcopioHelper::set_delivered_amount($sale, $request->articles);

        return response()->json(['model' => $this->fullModel('Sale', $sale_id)], 200);
    }
}
