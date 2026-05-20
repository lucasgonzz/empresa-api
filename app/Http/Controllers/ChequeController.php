<?php

namespace App\Http\Controllers;

use App\Exports\ChequesFilteredExport;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Models\Cheque;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountCurrentAcountPaymentMethod;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ChequeController extends Controller
{
    /**
     * Descarga un Excel con los cheques indicados por ID (los mismos que muestra el front al filtrar).
     *
     * @param \Illuminate\Http\Request $request Query `cheque_ids` (ids separados por guión, ej. 12-45-88).
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function excel_export(Request $request)
    {
        $cheques = $this->get_cheques_for_excel_export($request);

        return Excel::download(
            new ChequesFilteredExport($cheques),
            'cheques_'.date_format(Carbon::now(), 'd-m-y').'.xlsx'
        );
    }

    /**
     * Carga cheques por IDs enviados desde la SPA, respetando owner y orden del listado.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Support\Collection
     */
    protected function get_cheques_for_excel_export(Request $request)
    {
        if (!$request->has('cheque_ids')) {
            return collect();
        }

        $raw_ids = explode('-', (string) $request->query('cheque_ids'));
        $ids = [];

        foreach ($raw_ids as $raw_id) {
            $id = (int) trim($raw_id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if (!count($ids)) {
            return collect();
        }

        $cheques_by_id = Cheque::where('user_id', $this->userId())
            ->whereIn('id', $ids)
            ->withAll()
            ->get()
            ->keyBy('id');

        $ordered = collect();

        foreach ($ids as $id) {
            if (isset($cheques_by_id[$id])) {
                $ordered->push($cheques_by_id[$id]);
            }
        }

        return $ordered;
    }

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

            // Cálculo de estado dinámico (fecha de cobro = fecha_pago + 30 días).
            // Importante: el último día hábil del plazo debe incluirse en la ventana de cobro;
            // si se usa solo lt(fechaVencimiento), el día exacto de vencimiento no entra en ninguna
            // rama y el cheque desaparece del listado agrupado (off-by-one).
            $fechaPago = Carbon::parse($cheque->fecha_pago);
            $fechaVencimiento = $fechaPago->copy()->addDays(30);
            $diasHastaVencimiento = $hoy->diffInDays($fechaVencimiento, false);

            if ($hoy->lt($fechaPago)) {
                $agrupados[$cheque->tipo]['pendientes'][] = $cheque;
            } elseif ($hoy->gte($fechaPago) && $hoy->lte($fechaVencimiento)) {
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

    function pagar(Request $request) {
        $cheque = Cheque::find($request->cheque_id);
        $cheque->estado_manual = 'cobrado';
        $cheque->cobrado_en = Carbon::now();
        $cheque->cobrado_por_id = $this->userId(false);
        $cheque->save();

        if ($request->caja_id != 0) {
            CurrentAcountCajaHelper::guardar_pago($cheque->amount, $request->caja_id, 'provider', $cheque->current_acount, 'Pago cheque N° '.$cheque->numero);
        }

        return response()->json(['model' => $cheque], 200);
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
                'endosado_desde_client_id'  => $cheque->client_id,
            ],
        ];

        $num_receipt = CurrentAcountHelper::getNumReceipt();

        $credit_account = $this->get_provider_credit_account($cheque);

        if ($credit_account) {
            
            $pago = CurrentAcount::create([
                'haber'                             => $cheque->amount,
                'description'                       => null,
                'status'                            => 'pago_from_client',
                'user_id'                           => $this->userId(),
                'num_receipt'                       => $num_receipt,
                'detalle'                           => 'Pago N°'.$num_receipt,
                'provider_id'                       => $cheque->endosado_a_provider_id,
                'created_at'                        => Carbon::now(),
                'credit_account_id'                    => $credit_account->id,
            ]);

            CurrentAcountPagoHelper::attachPaymentMethods($pago, $payment_methods);
            $pago->saldo = CurrentAcountHelper::getSaldo($credit_account->id, $pago) - $pago->haber;
            $pago->save();

            $pago_helper = new CurrentAcountPagoHelper($credit_account->id, 'provider', $pago->provider_id, $pago);
            $pago_helper->init();

            $credit_account->saldo = $pago->saldo;
        }

        // CurrentAcountHelper::updateModelSaldo($pago, 'provider', $pago->provider_id);
    }

    function get_provider_credit_account($cheque) {
        $credit_account = CreditAccount::where('model_name', 'provider')
                                        ->where('model_id', $cheque->endosado_a_provider_id)
                                        ->where('moneda_id', $cheque->current_acount->credit_account->moneda_id)
                                        ->first();
        return $credit_account;
    }

    function destroy($id) {
        $model = Cheque::find($id);
        $model->delete();
        return response(null, 200);
    }
}
 