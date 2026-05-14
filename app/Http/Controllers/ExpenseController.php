<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\PaymentMethodHelper;
use App\Http\Controllers\Helpers\caja\DeleteCajaCompensacionHelper;
use App\Http\Controllers\Helpers\expense\ExpenseCajaHelper;
use App\Models\Expense;
use Illuminate\Http\Request;

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

        $model->load('current_acount_payment_methods');
        
        foreach ($model->current_acount_payment_methods as $payment_method) {

            if (
                $payment_method->type != 'cheque'                
                && $payment_method->pivot->caja_id
            ) {
                
                $data = [
                    'amount'    => $payment_method->pivot->amount,
                    'caja_id'   => $payment_method->pivot->caja_id,
                ];  

                ExpenseCajaHelper::guardar_movimiento_caja($model, $data);
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
