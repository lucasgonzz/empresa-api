<?php

namespace App\Http\Controllers;

use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\CurrentAcountDeleteNotaDebitoHelper;
use App\Http\Controllers\Helpers\CurrentAcountDeletePagoHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\NotaCreditoHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfPrintCurrentAcounts;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCuotaHelper;
use App\Http\Controllers\Pdf\AfipTicketPdf;
use App\Http\Controllers\Pdf\CurrentAcountPdf;
use App\Http\Controllers\Pdf\CurrentAcount\NewPagoPdf;
use App\Http\Controllers\Pdf\NotaCreditoPdf;
use App\Http\Controllers\Pdf\PagoPdf;
use App\Imports\CurrentAcountsImport;
use App\Models\Commissioner;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class CurrentAcountController extends Controller
{

    function index($model_name, $model_id, $months_ago) {
        $months_ago = Carbon::now()->subMonths($months_ago);
        $models = CurrentAcount::whereDate('created_at', '>=', $months_ago);
        if ($model_name == 'client') {
            $models = $models->where('client_id', $model_id);
        } else {
            $models = $models->where('provider_id', $model_id);
        }
        $models = $models->with('current_acount_payment_methods')
                        ->with('pagado_por')
                        ->with('cheques')
                        ->with('sale.afip_ticket')
                        ->orderBy('created_at', 'DESC')
                        // ->get();
                        ->get()
                        ->reverse() // <- Este es el truco
                        ->values(); // <- Esto resetea los índices del array
        // $models = CurrentAcountHelper::format($models);
        return response()->json(['models' => $models], 200);
    }

    function check_saldos_y_pagos($credit_account_id) {
        CurrentAcountHelper::check_saldos_y_pagos($credit_account_id);
    }

    public function pago(Request $request) {

        // CurrentAcountHelper::eliminar_pagos_provisorios($request->credit_account_id, $request->is_provisorio);

        $pago = CurrentAcount::create([
            'haber'                             => $this->get_haber($request),
            'description'                       => $request->description,
            'numero_orden_de_compra'            => $request->numero_orden_de_compra,
            'credit_account_id'                 => $request->credit_account_id,
            'is_provisorio'                     => $request->is_provisorio,
            'status'                            => 'pago_from_client',
            'user_id'                           => $this->userId(),
            'num_receipt'                       => CurrentAcountHelper::getNumReceipt(),
            'to_pay_id'                         => !is_null($request->to_pay) ? $request->to_pay['id'] : null,
            'client_id'                         => $request->model_name == 'client' ? $request->model_id : null,
            'provider_id'                       => $request->model_name == 'provider' ? $request->model_id : null,
            'created_at'                        => CurrentAcountHelper::getCreatedAt($request),
            'employee_id'                       => UserHelper::userId(false),
        ]);

        $pago->detalle = 'Pago N°'.$pago->num_receipt;
        $pago->save();

        CurrentAcountPagoHelper::attachPaymentMethods($pago, $request->current_acount_payment_methods, $request->model_name);

        if (!$pago->is_provisorio) {

            $pago->saldo = CurrentAcountHelper::getSaldo($request->credit_account_id, $pago) - (float)$request->haber;

            $pago_helper = new CurrentAcountPagoHelper($request->credit_account_id, $request->model_name, $request->model_id, $pago);
            $pago_helper->init();
            
            CurrentAcountHelper::check_saldos_y_pagos($request->credit_account_id);

            CurrentAcountCuotaHelper::pagar_cuota($pago, $request);
        }

        $this->sendAddModelNotification($request->model_name, $request->model_id);
        Log::info('Terminando de guardar pago');
        return response()->json(['current_acount' => $pago], 201);
    }

    function get_haber($request) {
        $total = 0;
        foreach ($request->current_acount_payment_methods as $payment_method) {
            
            $total += (float)$payment_method['amount'];
        }
        return $total;
    }

    public function notaCredito(Request $request) {
        $nota_credito = CurrentAcountHelper::notaCredito($request->credit_account_id, $request->form['nota_credito'], $request->form['description'], $request->model_name, $request->model_id);
        CurrentAcountHelper::checkCurrentAcountSaldo($request->credit_account_id);
        $this->sendAddModelNotification($request->model_name, $request->model_id);
        return response()->json(['current_acount' => $nota_credito], 201);
    }


    public function notaDebito(Request $request) {
        $nota_debito = CurrentAcount::create([
            'detalle'           => 'Nota de debito',
            'description'       => $request->description,
            'debe'              => $request->debe,
            'status'            => 'sin_pagar',
            'client_id'         => $request->model_name == 'client' ? $request->model_id : null,
            'provider_id'       => $request->model_name == 'provider' ? $request->model_id : null,
            'user_id'           => $this->userId(),
            'credit_account_id' => $request->credit_account_id,
        ]);
        $nota_debito->saldo = CurrentAcountHelper::getSaldo($request->credit_account_id, $nota_debito) + $request->debe;
        $nota_debito->save();

        CurrentAcountHelper::checkCurrentAcountSaldo($request->credit_account_id);
        CurrentAcountHelper::update_credit_account_saldo($request->credit_account_id);

        $this->sendAddModelNotification($request->model_name, $request->model_id);
        return response()->json(['current_acount' => $nota_debito], 201);
    }

    function updateDebe(Request $request) {
        $current_acount = CurrentAcount::find($request->id);
        $current_acount->debe = $request->debe;
        $current_acount->save();
        return response(null, 200);
        // $client_controller = new ClientController();
        // $client_controller->checkSaldoss($current_acount->client_id);
    }

    function saldoInicial(Request $request) {
        $current_acount = CurrentAcount::create([
            'detalle'       => 'Saldo inicial',
            'status'        => $request->is_for_debe ? 'sin_pagar' : 'pago_from_client',
            'client_id'     => $request->model_name == 'client' ? $request->model_id : null,
            'provider_id'   => $request->model_name == 'provider' ? $request->model_id : null,
            'debe'          => $request->is_for_debe ? $request->saldo_inicial : null,
            'haber'         => !$request->is_for_debe ? $request->saldo_inicial : null,
            'saldo'         => $request->is_for_debe ? $request->saldo_inicial : -$request->saldo_inicial,
        ]);
        CurrentAcountHelper::updateModelSaldo($current_acount, $request->model_name, $request->model_id);
        return response()->json(['current_acount' => $current_acount], 201);
    }

    function updateSaldo($client_id, $current_acounts) {
        foreach ($current_acounts as $current_acount) {
            if ($this->esUnPago($current_acount)) {
                $current_acount->saldo = CurrentAcountHelper::getSaldo($client_id, $current_acount) - $current_acount->haber;
            } else {
                $current_acount->saldo = CurrentAcountHelper::getSaldo($client_id, $current_acount) + $current_acount->debe;
            }
            $current_acount->save();
        }
    }

    function esUnPago($current_acount) {
        return $current_acount->status == 'pago_from_client' || $current_acount->status == 'nota_credito';
    }

    function import(Request $request, $client_id) {
        Excel::import(new CurrentAcountsImport($client_id), $request->file('current_acounts'));
        return response(null, 200);
    }

    function delete($model_name, $id) {
        $current_acount = CurrentAcount::find($id);

        if ($current_acount->status == 'pago_from_client' || $current_acount->status == 'nota_credito') {

            // $ct = new CurrentAcountDeletePagoHelper($model_name, $current_acount);
            // $ct->deletePago();
            if ($current_acount->status == 'nota_credito') {
                NotaCreditoHelper::resetUnidadesDevueltas($current_acount);
            }
            // $current_acount->pagando_a()->detach();
            CurrentAcountHelper::updateSellerCommissionsStatus($current_acount);

        } else {
            // CurrentAcountDeleteNotaDebitoHelper::deleteNotaDebito($current_acount, $model_name);
        }

        $credit_account_id = $current_acount->credit_account_id;

        $current_acount->delete();
        
        CurrentAcountHelper::checkSaldos($credit_account_id);
        
        CurrentAcountHelper::checkPagos($credit_account_id, true);

        // $this->sendAddModelNotification($model_name, $model_id, false);
    }

    function pdfFromModel($current_acount_id, $cantidad_movimientos = 0, $type = 'simple') {
        
        // Si es > 0 son todos los movimientos de una credit_accounts
        if ($cantidad_movimientos > 0) {
            $models = CurrentAcount::where('credit_account_id', $current_acount_id)
                ->orderBy('created_at', 'ASC')
                ->take($cantidad_movimientos)
            ;
        } else {
            $models = CurrentAcount::where('id', $current_acount_id)
                ->orderBy('created_at', 'ASC')
            ;                        
        }
        if ($type == 'details') {
            $models = $models->with('articles', 'sale.articles');
        }
        $models = $models->get();
        /*
        foreach ($models as $model) {
            $articles = $model->articles;
            if ($model->sale && $model->sale->articles->count() > 0) {
                $articles = $model->sale->articles;
            }

            foreach ($articles as $article) {
                dump($article);
            }
        }
        die();
        */
        $credit_account = CreditAccount::find($models[0]->credit_account_id);
                                
        new CurrentAcountPdf($credit_account, $models, $type);
    }

    // function pdfFromModel($credit_account_id, $cantidad_movimientos) {
    //     $credit_account = CreditAccount::find($credit_account_id);
    //     $models = CurrentAcount::where('credit_account_id', $credit_account_id)
    //                             ->orderBy('created_at', 'ASC')
    //                             ->take($cantidad_movimientos)
    //                             ->get();
                                
    //     new CurrentAcountPdf($credit_account, $models);
    // }


    // Se usa para un pago o nota de credito
    function pdf($id) {

        $model = CurrentAcount::find($id);
    
        if ($model->status == 'pago_from_client') {
            if (!is_null($model->client_id)) {
                $model_name = 'client';
            } else {
                $model_name = 'provider';
            }
            $pdf = new NewPagoPdf($model, $model_name);

        } else if ($model->status == 'nota_credito') {
           
            if (!is_null($model->afip_ticket)) {
                $pdf = new AfipTicketPdf(null, $model);
            } else {
                $pdf = new NotaCreditoPdf($model);
                $pdf->printCurrentAcounts();
            }
        }
    }
}
