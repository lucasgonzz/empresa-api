<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\caja\DeleteCajaCompensacionHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Http\Controllers\Helpers\expense\ExpenseCajaHelper;
use App\Http\Controllers\Helpers\expense\ExpenseHelper;
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

        /*
         * El alta en sí (transacción, desglose de métodos de pago, movimientos de caja) vive en
         * ExpenseHelper::crear() desde la misión agenda-tareas-calendario (14/9/2026), porque la
         * Agenda también da de alta gastos y el helper viejo lo hacía armando un Request falso
         * contra este método. Acá queda lo que es del HTTP: normalizar la moneda, prevalidar las
         * cajas para responder 422 sin escribir nada, y armar la respuesta. El payload y las
         * respuestas de `POST api/expense` no cambiaron.
         */
        $moneda_id = ExpenseHelper::normalizar_moneda_id($request->moneda_id);

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

        $data = $request->only([
            'expense_concept_id',
            'amount',
            'importe_iva',
            'observations',
            'created_at',
            'payment_methods',
        ]);

        $data['moneda_id'] = $moneda_id;

        // El correlativo va como closure para que num() corra ADENTRO de la transacción del helper
        // y su lockForUpdate se sostenga hasta el commit (ver el docblock de ExpenseHelper::crear()).
        $model = ExpenseHelper::crear($data, $this->userId(), function () {
            return $this->num('expenses');
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
