<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Models\Cheque;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountCurrentAcountPaymentMethod;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ChequeController extends Controller
{

    function index() {
        $hoy = Carbon::today();

        $diasProntoAVencer = 3; // por defecto, 3 días
        // $diasProntoAVencer = $request->input('dias_pronto_a_vencer', 3); // por defecto, 3 días

        $cheques = Cheque::where('user_id', $this->userId())
                        ->withAll()
                        ->orderBy('id', 'DESC')
                        ->get();

        $agrupados = [
            'recibido' => [
                'pendientes' => [],
                'disponibles_para_cobrar' => [],
                'pronto_a_vencerse' => [],
                'vencidos' => [],
                'cobrados' => [],
                'rechazados' => [],
                'endosados' => [],
            ],
            'emitido' => [
                'pendientes' => [],
                'disponibles_para_cobrar' => [],
                'pronto_a_vencerse' => [],
                'vencidos' => [],
                'cobrados' => [],
                'rechazados' => [],
            ]
        ];

        foreach ($cheques as $cheque) {
            // Si está marcado manualmente, va a estado final
            if ($cheque->estado_manual === 'cobrado') {
                $agrupados[$cheque->tipo]['cobrados'][] = $cheque;
                continue;
            }

            if ($cheque->estado_manual === 'rechazado') {
                $agrupados[$cheque->tipo]['rechazados'][] = $cheque;
                continue;
            }

            // Si es recibido y fue endosado
            if ($cheque->tipo === 'recibido' && $cheque->endosado_a_provider_id) {
                $agrupados['recibido']['endosados'][] = $cheque;
                continue;
            }

            // Cálculo de estado dinámico
            $fechaPago = Carbon::parse($cheque->fecha_pago);
            $fechaVencimiento = $fechaPago->copy()->addDays(30);
            $diasHastaVencimiento = $hoy->diffInDays($fechaVencimiento, false);

            if ($hoy->lt($fechaPago)) {
                $agrupados[$cheque->tipo]['pendientes'][] = $cheque;
            } elseif ($hoy->gte($fechaPago) && $hoy->lt($fechaVencimiento)) {
                if ($diasHastaVencimiento <= $diasProntoAVencer) {
                    $agrupados[$cheque->tipo]['pronto_a_vencerse'][] = $cheque;
                } else {
                    $agrupados[$cheque->tipo]['disponibles_para_cobrar'][] = $cheque;
                }
            } elseif ($hoy->gt($fechaVencimiento)) {
                $agrupados[$cheque->tipo]['vencidos'][] = $cheque;
            }
        }
                            
        return response()->json(['models' => $agrupados], 200);
    }

    function cobrar(Request $request) {
        $cheque = Cheque::find($request->cheque_id);
        $cheque->estado_manual = 'cobrado';
        $cheque->cobrado_en = Carbon::now();
        $cheque->cobrado_por_id = $this->userId(false);
        $cheque->save();

        if ($request->caja_id != 0) {
            CurrentAcountCajaHelper::guardar_pago($cheque->amount, $request->caja_id, 'client', $cheque->current_acount, 'Cobro cheque N° '.$cheque->numero);
        }

        return response()->json(['model' => $cheque], 200);
    }

    function rechazar(Request $request) {
        $cheque = Cheque::find($request->cheque_id);
        $cheque->estado_manual = 'rechazado';
        $cheque->rechazado_en = Carbon::now();
        $cheque->rechazado_por_id = $this->userId(false);
        $cheque->rechazado_observaciones = $request->rechazado_observaciones;

        $cheque->save();
        
        return response()->json(['model' => $cheque], 200);
    }

    function endosar(Request $request) {
        $cheque = Cheque::find($request->cheque_id);
        $cheque->endosado_a_provider_id = $request->provider_id;
        $cheque->fecha_endoso = Carbon::now();
        $cheque->save();

        $this->crear_provider_current_acount($cheque);
        
        return response()->json(['model' => $cheque], 200);
    }

    function crear_provider_current_acount($cheque) {

        $payment_methods = [
            [
                'current_acount_payment_method_id' => 1,
                'amount' => $cheque->amount,

                'numero'                    => $cheque->numero,
                'banco'                     => $cheque->banco,
                'amount'                    => $cheque->amount,
                'fecha_emision'             => $cheque->fecha_emision,
                'fecha_pago'                => $cheque->fecha_pago,
                'es_echeq'                  => $cheque->es_echeq,
            ],
        ];

        $num_receipt = CurrentAcountHelper::getNumReceipt();

        $pago = CurrentAcount::create([
            'haber'                             => $cheque->amount,
            'description'                       => null,
            'status'                            => 'pago_from_client',
            'user_id'                           => $this->userId(),
            'num_receipt'                       => $num_receipt,
            'detalle'                           => 'Pago N°'.$num_receipt,
            'provider_id'                       => $cheque->endosado_a_provider_id,
            'created_at'                        => Carbon::now(),
        ]);

        CurrentAcountPagoHelper::attachPaymentMethods($pago, $payment_methods);
        $pago->saldo = CurrentAcountHelper::getSaldo('provider', $pago->provider_id, $pago) - $pago->haber;
        $pago->save();
        $pago_helper = new CurrentAcountPagoHelper('provider', $pago->provider_id, $pago);
        $pago_helper->init();
        CurrentAcountHelper::updateModelSaldo($pago, 'provider', $pago->provider_id);
    }
}
 