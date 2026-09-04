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
         * moneda_id=0 llega cuando el formulario no envía moneda (sin extensión ventas_en_dolares).
         * Se normaliza a pesos (1) para reportes y listados.
         */
        $moneda_id = $request->moneda_id;
        if (is_null($moneda_id) || (int) $moneda_id === 0) {
            $moneda_id = 1;
        }

        /*
         * Prevalidación de las cajas destino, ANTES de escribir nada. Es la misma que hace
         * CurrentAcountController::pago() desde el 21/8/2026, y la razón es idéntica: el alta de un
         * gasto crea el gasto, adjunta el desglose y recién después genera un movimiento de caja por
         * cada método de pago. Si la segunda caja nunca tuvo apertura, el flujo se cortaba ahí con el
         * gasto ya creado, el desglose ya adjunto y el egreso de la primera caja ya impactado: el
         * usuario veía un 500, reintentaba, y terminaba con el gasto duplicado.
         *
         * Reportado el 29/8/2026 sobre un gasto en pesos con una fila de efectivo en pesos y otra de
         * efectivo en dólares. El hotfix de agosto tapó este mismo agujero solo en cuenta corriente;
         * acá se cierra el camino del gasto, que había quedado como estaba.
         *
         * 🔴 Lo que se valida es que la caja tenga ALGUNA apertura, no que esté abierta ahora. Una
         * caja cerrada con aperturas previas cuelga el movimiento de la última y así funciona hoy en
         * todos los flujos: rechazarla le rompería la carga de gastos a cualquier comercio que
         * cierra la caja a la noche. El criterio completo está en
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
         * Todo lo que escribe va adentro de una transacción. La prevalidación de arriba cubre el
         * caso conocido, pero si algo revienta igual —por ejemplo una caja que se cierra entre la
         * validación y el guardado— no puede quedar un gasto a medias con solo una parte de los
         * movimientos de caja hechos.
         */
        $model = DB::transaction(function () use ($request, $moneda_id) {

            $model = Expense::create([
                'num'                                   => $this->num('expenses'),
                'expense_concept_id'                    => $request->expense_concept_id,
                'amount'                                => $request->amount,
                'moneda_id'                             => $moneda_id,
                'importe_iva'                           => $request->importe_iva,
                'observations'                          => $request->observations,
                'created_at'                            => $request->created_at,
                'user_id'                               => $this->userId(),
                'caja_id'                               => 0,
            ]);

            PaymentMethodHelper::attach_payment_methods($model, $request->payment_methods);

            // `.type` va explícito para no lazy-loadear la relación una vez por método de pago
            // adentro de la transacción: deberia_haber_impactado_caja() la lee en cada vuelta para
            // dejar los cheques afuera del warning.
            $model->load('current_acount_payment_methods.type');

            foreach ($model->current_acount_payment_methods as $payment_method) {

                /*
                 * Sin guard de cheque, a propósito. Hasta el 29/8/2026 acá decía
                 * `$payment_method->type != 'cheque'`: `type` es una relación belongsTo a
                 * CAPaymentMethodType, no una columna, así que eso comparaba un modelo de Eloquent
                 * contra un string y daba SIEMPRE true. Nunca excluyó un cheque, o sea que el
                 * comportamiento real y vigente en producción es el de esta condición pelada. Se
                 * deja igual, pero escrito sin la comparación rota para que el código diga lo que
                 * de verdad hace.
                 *
                 * 🔴 Que el cheque mueva o no la caja al cargar el gasto es una decisión aparte, y
                 * NO entra acá (Lucas, 29/8/2026): en el pago de cuenta corriente el cheque con caja
                 * también genera movimiento (CurrentAcountPagoHelper), así que cambiarlo de un solo
                 * lado dejaría dos criterios distintos para la misma decisión.
                 */
                if ($payment_method->pivot->caja_id) {

                    $data = [
                        'amount'    => $payment_method->pivot->amount,
                        'caja_id'   => $payment_method->pivot->caja_id,
                    ];

                    ExpenseCajaHelper::guardar_movimiento_caja($model, $data);

                } else if (CurrentAcountPagoHelper::deberia_haber_impactado_caja($payment_method)) {

                    /*
                     * Un método de pago con monto pero SIN caja destino no impacta en ninguna caja,
                     * y hasta el 29/8/2026 eso pasaba sin dejar rastro: la respuesta era 201, el
                     * gasto aparecía cargado y la plata no estaba en ninguna caja. Es el mismo
                     * agujero que se tapó en el pago de cuenta corriente el 21/8/2026.
                     *
                     * NO se lanza excepción: el método igual queda guardado y el gasto es válido; lo
                     * único que falta es el movimiento, y eso se arregla desde Tesorería.
                     */
                    Log::warning('ExpenseController@store: el metodo de pago '.$payment_method->id.' del gasto '.$model->id.' tiene monto '.$payment_method->pivot->amount.' pero no tiene caja destino, asi que no impacta en ninguna caja.');
                }
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

        /*
         * Tanda correctivos 2408, ítem 5: hasta hoy la edición de un gasto solo tocaba la
         * fila de expenses. El desglose por método de pago (pivot) y el movimiento de caja
         * quedaban con el monto viejo: el estado de resultados mostraba el monto nuevo y el
         * flujo/saldo de caja, el viejo.
         *
         * La SPA edita el gasto sin re-mandar el desglose (la tabla de métodos de pago del
         * form es de solo lectura), así que: (1) se reparte el monto nuevo entre los métodos
         * ya adjuntos en proporción a lo que cada uno pagaba, y (2) se sincronizan los
         * movimientos de caja del gasto con ese desglose y se recalculan los saldos. El
         * detalle de las dos operaciones vive en ExpenseCajaHelper.
         */
        ExpenseCajaHelper::escalar_desglose_por_nuevo_monto($model);

        ExpenseCajaHelper::editar_movimiento_caja($model);

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
