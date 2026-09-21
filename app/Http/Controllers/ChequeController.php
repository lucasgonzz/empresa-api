<?php

namespace App\Http\Controllers;

use App\Exports\ChequesFilteredExport;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountPagoAltaHelper;
use App\Models\Cheque;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\CreditAccount;
use App\Models\CurrentAcountPaymentMethod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

            // Si es recibido y fue endosado (a un proveedor O en un gasto: la definición es una
            // sola y vive en ChequeHelper, no acá).
            if ($cheque->tipo === 'recibido' && !ChequeHelper::en_cartera($cheque)) {
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

    /**
     * Los cheques recibidos que hoy se pueden endosar, para el select de la fila de pago a
     * proveedor y de la fila de gasto (misión cheques-endoso-y-bancos, 21/9/2026).
     *
     * @return \Illuminate\Http\JsonResponse  {models: Cheque[] withAll}
     */
    function disponibles_para_endosar() {

        return response()->json(['models' => ChequeHelper::disponibles_para_endosar($this->userId())], 200);
    }

    /**
     * El botón Endosar del módulo de cheques: registra un pago al proveedor con una fila de tipo
     * cheque que ELIGE el recibido (`cheque_id`), y el endoso en sí lo hace ChequeHelper por el
     * mismo camino que el pago de cuenta corriente y el gasto. Hasta el 21/9/2026 este método
     * marcaba el recibido a mano antes de armar el pago, y era el único de los tres caminos que lo
     * hacía así.
     *
     * Responde 422 si el cheque ya no se puede endosar, y también si el proveedor no tiene cuenta
     * corriente en la moneda del cheque: antes en ese caso no se creaba nada y se devolvía 200 con
     * el cheque sin marcar, o sea un endoso que "salió bien" sin registrar nada.
     */
    function endosar(Request $request) {

        // La misma lectura de `cheque_id` que la fila de pago: un '12abc' es "sin cheque", no el 12.
        $cheque_id = ChequeHelper::cheque_id_de(['cheque_id' => $request->cheque_id]);

        $cheque = $cheque_id > 0 ? Cheque::where('user_id', $this->userId())->find($cheque_id) : null;

        if (is_null($cheque)) {

            return response()->json(['message' => 'El cheque elegido para endosar no existe o no es de tu cuenta.'], 422);
        }

        $problemas = ChequeHelper::problemas_de_endoso_en_payload([
            [
                'cheque_id'                        => $cheque->id,
                'current_acount_payment_method_id' => $this->metodo_de_pago_cheque_id(),
                'amount'                           => $cheque->amount,
                'caja_id'                          => 0,
            ],
        ], $this->userId());

        if (count($problemas)) {

            return response()->json(['message' => implode('. ', $problemas).'.'], 422);
        }

        $provider_id = (int) $request->provider_id;

        if ($provider_id <= 0) {

            return response()->json(['message' => 'Elegí el proveedor al que le endosás el cheque.'], 422);
        }

        $credit_account = $this->get_provider_credit_account($cheque, $provider_id);

        if (is_null($credit_account)) {

            return response()->json(['message' => 'El proveedor no tiene cuenta corriente en la moneda del cheque N° '.$cheque->numero.', así que el endoso no se puede registrar.'], 422);
        }

        $this->crear_provider_current_acount($cheque, $provider_id, $credit_account);

        return response()->json(['model' => $this->fullModel('Cheque', $cheque->id)], 200);
    }

    /**
     * El pago al proveedor que deja registrado el endoso, por CurrentAcountPagoAltaHelper::registrar():
     * el MISMO alta que `POST current-acount/pago` y que el asistente. La fila de método de pago es la
     * que armaría la pantalla de pago a proveedor eligiendo "Endosar un cheque recibido": método
     * Cheque, `cheque_id` del origen y su monto. attachPaymentMethods → attach_payment_methods →
     * ChequeHelper::crear_cheque ve el `cheque_id` y endosa.
     *
     * Hasta el 21/9/2026 este método armaba el pago a mano y terminaba con
     * `$credit_account->saldo = $pago->saldo` SIN save(): el botón nunca actualizó el saldo de la
     * cuenta corriente del proveedor. Por registrar() pasa por update_credit_account_saldo() como
     * cualquier otro pago (lo fija el test de paridad 4_Endosar_desde_el_modulo_Test).
     *
     * @param  \App\Models\Cheque  $cheque
     * @param  int  $provider_id
     * @param  \App\Models\CreditAccount  $credit_account
     * @return \App\Models\CurrentAcount
     */
    function crear_provider_current_acount($cheque, $provider_id, $credit_account) {

        $payment_methods = [
            [
                'current_acount_payment_method_id' => $this->metodo_de_pago_cheque_id(),
                'amount'                           => $cheque->amount,
                'cheque_id'                        => $cheque->id,
                'cheque_banco_id'                  => $cheque->cheque_banco_id,
                'caja_id'                          => 0,

                'numero'                           => $cheque->numero,
                'banco'                            => $cheque->banco,
                'fecha_emision'                    => $cheque->fecha_emision,
                'fecha_pago'                       => $cheque->fecha_pago,
                'es_echeq'                         => $cheque->es_echeq,
            ],
        ];

        // Adentro de una transacción por lo mismo que CurrentAcountController::pago(): si el
        // cheque lo endosó otra request en el medio, el endoso corta y el pago no queda huérfano.
        return DB::transaction(function () use ($credit_account, $provider_id, $payment_methods, $cheque) {

            return CurrentAcountPagoAltaHelper::registrar([
                'credit_account_id'              => $credit_account->id,
                'model_name'                     => 'provider',
                'model_id'                       => $provider_id,
                'current_acount_payment_methods' => $payment_methods,
                'haber'                          => $cheque->amount,
                'description'                    => null,
                'numero_orden_de_compra'         => null,
                'is_provisorio'                  => 0,
                'current_date'                   => 1,
                'created_at'                     => null,
                'to_pay'                         => null,
                'payment_plan_cuota'             => null,
            ]);
        });
    }

    /**
     * El id del método de pago de tipo cheque del catálogo (el 1, "Cheque", sembrado fijo), resuelto
     * por su tipo y no escrito a mano.
     *
     * @return int
     */
    protected function metodo_de_pago_cheque_id() {

        $metodo = CurrentAcountPaymentMethod::whereHas('type', function ($q) {
            $q->where('slug', 'cheque');
        })->orderBy('id')->first();

        return !is_null($metodo) ? (int) $metodo->id : 1;
    }

    /**
     * La cuenta corriente del proveedor en la moneda del cheque. La moneda sale de la cuenta
     * corriente del cobro en el que entró el cheque; si el cheque no viene de un cobro (una venta,
     * un cheque viejo sin `current_acount`), se toma pesos.
     *
     * @param  \App\Models\Cheque  $cheque
     * @param  int  $provider_id
     * @return \App\Models\CreditAccount|null
     */
    function get_provider_credit_account($cheque, $provider_id) {

        $moneda_id = 1;

        if (!is_null($cheque->current_acount) && !is_null($cheque->current_acount->credit_account) && !is_null($cheque->current_acount->credit_account->moneda_id)) {

            $moneda_id = $cheque->current_acount->credit_account->moneda_id;
        }

        return CreditAccount::where('model_name', 'provider')
                            ->where('model_id', $provider_id)
                            ->where('moneda_id', $moneda_id)
                            ->first();
    }

    function destroy($id) {
        $model = Cheque::find($id);
        $model->delete();
        return response(null, 200);
    }
}
 