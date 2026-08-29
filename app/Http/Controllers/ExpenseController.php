<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\PaymentMethodHelper;
use App\Http\Controllers\Helpers\caja\DeleteCajaCompensacionHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Http\Controllers\Helpers\expense\ExpenseCajaHelper;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpenseController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = Expense::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {

        /*
         * Prevalidacion de las cajas destino, ANTES de escribir nada. Es la misma que hace
         * CurrentAcountController::pago() desde el 21/8/2026, y la razon es identica: el alta de un
         * gasto crea el gasto, adjunta el desglose y recien despues genera un movimiento de caja por
         * cada metodo de pago. Si la segunda caja nunca tuvo apertura, el flujo se cortaba ahi con el
         * gasto ya creado, el desglose ya adjunto y el egreso de la primera caja ya impactado: el
         * usuario veia un 500, reintentaba, y terminaba con el gasto duplicado. Reportado el
         * 29/8/2026 sobre un gasto en pesos con una fila en pesos y otra en dolares.
         *
         * 🔴 Lo que se valida es que la caja tenga ALGUNA apertura, no que este abierta ahora. Una
         * caja cerrada con aperturas previas cuelga el movimiento de la ultima y asi funciona hoy en
         * todos los flujos: rechazarla le romperia la carga de gastos a cualquier comercio que
         * cierra la caja a la noche. El criterio completo esta en
         * CurrentAcountCajaHelper::cajas_sin_apertura_en_payload(), que recibe el array crudo del
         * request y no sabe nada de cuenta corriente.
         */
        $cajas_sin_apertura = CurrentAcountCajaHelper::cajas_sin_apertura_en_payload($request->payment_methods);

        if (count($cajas_sin_apertura)) {

            return response()->json([
                'message' => 'Las siguientes cajas nunca se abrieron: '.implode(', ', $cajas_sin_apertura).'. Hay que abrirlas para poder registrar el gasto.',
            ], 422);
        }

        /*
         * Todo lo que escribe va adentro de una transaccion. La prevalidacion de arriba cubre el
         * caso conocido, pero si algo revienta igual -por ejemplo una caja que se cierra entre la
         * validacion y el guardado- no puede quedar un gasto a medias con solo una parte de los
         * movimientos de caja hechos.
         */
        $model = DB::transaction(function () use ($request) {

            $model = Expense::create([
                'num'                                   => $this->num('expenses'),
                'expense_concept_id'                    => $request->expense_concept_id,
                'amount'                                => $request->amount,
                'moneda_id'                             => $request->moneda_id,
                'importe_iva'                           => $request->importe_iva,
                'observations'                          => $request->observations,
                'created_at'                            => $request->created_at,
                'user_id'                               => $this->userId(),
                'caja_id'                               => 0,
            ]);

            PaymentMethodHelper::attach_payment_methods($model, $request->payment_methods);

            // `.type` va explicito para no lazy-loadear la relacion una vez por metodo de pago
            // adentro de la transaccion: deberia_haber_impactado_caja() la lee en cada vuelta para
            // dejar los cheques afuera del warning.
            $model->load('current_acount_payment_methods.type');

            foreach ($model->current_acount_payment_methods as $payment_method) {

                /*
                 * Sin guard de cheque, a proposito. Hasta el 29/8/2026 aca decia
                 * `$payment_method->type != 'cheque'`: `type` es una relacion belongsTo a
                 * CAPaymentMethodType, no una columna, asi que eso comparaba un modelo de Eloquent
                 * contra un string y daba SIEMPRE true. Nunca excluyo un cheque, o sea que el
                 * comportamiento real y vigente en produccion es el de esta condicion pelada. Se
                 * deja igual, pero escrito sin la comparacion rota para que el codigo diga lo que
                 * de verdad hace.
                 *
                 * 🔴 Que el cheque mueva o no la caja al cargar el gasto es una decision aparte, y
                 * NO entra en este hotfix (Lucas, 29/8/2026): en el pago de cuenta corriente el
                 * cheque con caja tambien genera movimiento (CurrentAcountPagoHelper), asi que
                 * cambiarlo de un solo lado dejaria dos criterios distintos para la misma decision.
                 * Se decide con las dos puntas a la vez.
                 */
                if ($payment_method->pivot->caja_id) {

                    $data = [
                        'amount'    => $payment_method->pivot->amount,
                        'caja_id'   => $payment_method->pivot->caja_id,
                    ];

                    ExpenseCajaHelper::guardar_movimiento_caja($model, $data);

                } else if (CurrentAcountPagoHelper::deberia_haber_impactado_caja($payment_method)) {

                    /*
                     * Un metodo de pago con monto pero SIN caja destino no impacta en ninguna caja,
                     * y hasta el 29/8/2026 eso pasaba sin dejar rastro: la respuesta era 201, el
                     * gasto aparecia cargado y la plata no estaba en ninguna caja. Es el mismo
                     * agujero que se tapo en el pago de cuenta corriente el 21/8/2026.
                     *
                     * NO se lanza excepcion: el metodo igual queda guardado y el gasto es valido; lo
                     * unico que falta es el movimiento, y eso se arregla desde Tesoreria.
                     */
                    Log::warning('ExpenseController@store: el metodo de pago '.$payment_method->id.' del gasto '.$model->id.' tiene monto '.$payment_method->pivot->amount.' pero no tiene caja destino, asi que no impacta en ninguna caja.');
                }

                // if (!is_null($payment_method['amount'])) {

                //     $amount = $payment_method['amount'];
                //     $caja_id = null;
                //     if (isset($payment_method['caja_id']) && $payment_method['caja_id'] != 0) {
                //         $caja_id = $payment_method['caja_id'];
                //     }
                
                //     $model->current_acount_payment_methods()->attach($payment_method['current_acount_payment_method_id'],[
                //         'amount'    => $amount,
                //         'caja_id'   => $caja_id,
                //     ]);

                //     $data = [
                //         'amount'    => $amount,
                //         'caja_id'    => $caja_id,
                //     ];  
        
                //     ExpenseCajaHelper::guardar_movimiento_caja($model, $data);
                // }
            }

            $model->save();

            return $model;
        });

        return response()->json(['model' => $this->fullModel('Expense', $model->id)], 201);
    }  

    public function show($id) 
    {
        $model = Expense::where('id', $id)->with('payment_methods')->first();
        return response()->json(['model' => $model], 200);
    }

    public function update(Request $request, $id) {
        $model = Expense::find($id);
        $model->expense_concept_id                    = $request->expense_concept_id;
        $model->amount                                = $request->amount;
        $model->importe_iva                           = $request->importe_iva;
        $model->observations                          = $request->observations;
        $model->caja_id                               = 0;
        $model->created_at                            = $request->created_at;
        $model->save();
        
        // $model->payment_methods()->detach();
        
        // foreach ($request->payment_methods as $payment_method) {
        //     if (!is_null($payment_method['amount'])) {
        //         $amount = $payment_method['amount'];
        //         $caja_id = null;
        //         if (isset($payment_method['caja_id']) && $payment_method['caja_id'] != 0) {
        //             $caja_id = $payment_method['caja_id'];
        //         }
                
        //         $model->payment_methods()->attach($payment_method['id'],[
        //             'amount'    => $amount,
        //             'caja_id'   => $caja_id,
        //         ]);
        //     }
        // }

        // ExpenseCajaHelper::editar_movimiento_caja($model);

        return response()->json(['model' => $this->fullModel('Expense', $model->id)], 200);
    }

    public function destroy(Request $request, $id) {
        $model = Expense::find($id);

        /** Flag enviado desde el modal de confirmación en SPA: compensar movimientos de caja al eliminar. */
        $compensar_caja = $request->boolean('compensar_caja');
        /** Helper compartido con ventas y cuenta corriente para validar cajas y generar movimientos inversos. */
        $helper_caja_compensacion = new DeleteCajaCompensacionHelper();
        if ($compensar_caja) {
            $model->loadMissing('current_acount_payment_methods', 'expense_concept');
            $cajas_cerradas = $helper_caja_compensacion->verificar_cajas_abiertas($model->current_acount_payment_methods);
            if (count($cajas_cerradas)) {
                return response()->json([
                    'message' => 'Las siguientes cajas están cerradas: '.implode(', ', $cajas_cerradas).'. Debe abrirlas para poder eliminar el gasto y compensar caja.',
                ], 422);
            }
        }

        ImageController::deleteModelImages($model);

        if ($compensar_caja) {
            $notas_eliminacion = 'Eliminación de gasto';
            if (! is_null($model->expense_concept)) {
                $notas_eliminacion .= ' — '.$model->expense_concept->name;
            }
            $helper_caja_compensacion->crear_movimientos_compensacion(
                $model->current_acount_payment_methods,
                DeleteCajaCompensacionHelper::MODEL_TYPE_EXPENSE,
                null,
                $notas_eliminacion
            );
        }

        $model->delete();
        $this->sendDeleteModelNotification('Expense', $model->id);
        return response(null);
    }
}
