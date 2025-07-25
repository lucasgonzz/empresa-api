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
use App\Http\Controllers\Pdf\AfipTicketPdf;
use App\Http\Controllers\Pdf\CurrentAcountPdf;
use App\Http\Controllers\Pdf\NotaCreditoPdf;
use App\Http\Controllers\Pdf\PagoPdf;
use App\Imports\CurrentAcountsImport;
use App\Models\Commissioner;
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
                        ->orderBy('created_at', 'ASC')
                        ->get();
        // $models = CurrentAcountHelper::format($models);
        return response()->json(['models' => $models], 200);
    }

    function check_saldos_y_pagos($model_name, $model_id) {
        CurrentAcountHelper::checkSaldos($model_name, $model_id);
        CurrentAcountHelper::checkPagos($model_name, $model_id, true);
    }

    public function pago(Request $request) {
        $pago = CurrentAcount::create([
            'haber'                             => $this->get_haber($request),
            'description'                       => $request->description,
            'numero_orden_de_compra'            => $request->numero_orden_de_compra,
            'status'                            => 'pago_from_client',
            'user_id'                           => $this->userId(),
            'num_receipt'                       => CurrentAcountHelper::getNumReceipt(),
            'to_pay_id'                         => !is_null($request->to_pay) ? $request->to_pay['id'] : null,
            'client_id'                         => $request->model_name == 'client' ? $request->model_id : null,
            'provider_id'                       => $request->model_name == 'provider' ? $request->model_id : null,
            'created_at'                        => CurrentAcountHelper::getCreatedAt($request),
            'employee_id'                       => UserHelper::userId(false),
        ]);

        CurrentAcountPagoHelper::attachPaymentMethods($pago, $request->current_acount_payment_methods, $request->model_name);

        $pago->saldo = CurrentAcountHelper::getSaldo($request->model_name, $request->model_id, $pago) - $request->haber;
        
        $pago->detalle = 'Pago N°'.$pago->num_receipt;
        $pago->save();

        $pago_helper = new CurrentAcountPagoHelper($request->model_name, $request->model_id, $pago);
        $pago_helper->init();
        
        if (!$request->current_date) {
            CurrentAcountHelper::checkSaldos($request->model_name, $request->model_id);
        } else {
            CurrentAcountHelper::checkCurrentAcountSaldo($request->model_name, $request->model_id);
            CurrentAcountHelper::updateModelSaldo($pago, $request->model_name, $request->model_id);
        }
        
        // CurrentAcountHelper::checkPagos($request->model_name, $request->model_id, true);

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
        $nota_credito = CurrentAcountHelper::notaCredito($request->form['nota_credito'], $request->form['description'], $request->model_name, $request->model_id);
        CurrentAcountHelper::checkCurrentAcountSaldo($request->model_name, $request->model_id);
        $this->sendAddModelNotification($request->model_name, $request->model_id);
        return response()->json(['current_acount' => $nota_credito], 201);
    }


    public function notaDebito(Request $request) {
        $nota_debito = CurrentAcount::create([
            'detalle'       => 'Nota de debito',
            'description'   => $request->description,
            'debe'          => $request->debe,
            'status'        => 'sin_pagar',
            'client_id'     => $request->model_name == 'client' ? $request->model_id : null,
            'provider_id'   => $request->model_name == 'provider' ? $request->model_id : null,
            'user_id'       => $this->userId(),
        ]);
        $nota_debito->saldo = CurrentAcountHelper::getSaldo($request->model_name, $request->model_id, $nota_debito) + $request->debe;
        $nota_debito->save();
        CurrentAcountHelper::checkCurrentAcountSaldo($request->model_name, $request->model_id);
        CurrentAcountHelper::updateModelSaldo($nota_debito, $request->model_name, $request->model_id);
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
            CurrentAcountDeleteNotaDebitoHelper::deleteNotaDebito($current_acount, $model_name);
        }

        $current_acount->delete();
        if ($model_name == 'client') {
            $model_id = $current_acount->client_id;
        } else {
            $model_id = $current_acount->provider_id;
        }
        $model = GeneralHelper::getModelName($model_name)::find($model_id);
        $model->pagos_checkeados = 0;
        $model->save();
        
        CurrentAcountHelper::checkSaldos($model_name, $model_id);
        
        CurrentAcountHelper::checkPagos($model_name, $model_id, true);

        $this->sendAddModelNotification($model_name, $model_id, false);
    }

    function pdfFromModel($model_name, $model_id, $months_ago) {
        $months_ago = Carbon::now()->subMonths($months_ago);
        $models = CurrentAcount::whereDate('created_at', '>=', $months_ago)
                                ->orderBy('created_at', 'ASC');
                                
        if ($model_name == 'client') {
            $models = $models->where('client_id', $model_id);
        } else if ($model_name == 'provider') {
            $models = $models->where('provider_id', $model_id);
        }
        $models = $models->get();
        new CurrentAcountPdf($models);
    }

    function pdf($ids, $model_name) {
        $ids = explode('-', $ids);
        if (count($ids) == 1) {
            $model = CurrentAcount::find($ids[0]);
            if ($model->status == 'pago_from_client') {
                if (!is_null($model->client_id)) {
                    $model_name = 'client';
                } else {
                    $model_name = 'provider';
                }
                $pdf = new PagoPdf($model, $model_name);
                $pdf->printCurrentAcounts();
            } else if ($model->status == 'nota_credito') {
                if (!is_null($model->afip_ticket)) {
                    $pdf = new AfipTicketPdf(null, $model);
                } else {
                    $pdf = new NotaCreditoPdf($model);
                    $pdf->printCurrentAcounts();
                }
            }
        } else {
            $pdf = new PdfPrintCurrentAcounts($ids, $model_name);
            $pdf->printCurrentAcounts();
        }
    }
}
