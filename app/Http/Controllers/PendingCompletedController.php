<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\agenda\AgendaCompletarHelper;
use App\Http\Controllers\Helpers\agenda\AgendaGastoRequeridoException;
use App\Http\Controllers\Helpers\agenda\AgendaYaCompletadaException;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountCajaHelper;
use App\Models\Pending;
use App\Models\PendingCompleted;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ocurrencias de la Agenda marcadas como hechas (misión agenda-tareas-calendario, 14/9/2026).
 * El trabajo de marcar —candado, PendingCompleted, gasto, flag de la tarea— vive en
 * AgendaCompletarHelper; acá queda lo del HTTP: leer el body (con la forma vieja de la SPA como
 * respaldo), prevalidar cajas para que el 422 no escriba nada, y traducir las excepciones del
 * helper a 409/422.
 */
class PendingCompletedController extends Controller
{

    /**
     * `GET pending-completed/from-date/{desde}/{hasta}`: las hechas de la cuenta en el rango, por
     * fecha en que se marcaron (`fecha_realizada`, que es la que muestra Realizadas como "hecha
     * el"), de la más reciente a la más vieja. `scopeWithAll` trae `pending.expense_concept` y
     * `expense`.
     *
     * @param  string|null  $from_date
     * @param  string|null  $until_date
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($from_date = null, $until_date = null) {

        $models = PendingCompleted::where('user_id', $this->userId())
                        ->whereDate('fecha_realizada', '>=', $from_date)
                        ->whereDate('fecha_realizada', '<=', $until_date)
                        ->orderBy('fecha_realizada', 'DESC')
                        ->orderBy('id', 'DESC')
                        ->withAll()
                        ->get();

        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {

        // La SPA vieja manda `id` (el de la tarea) en vez de `pending_id`. Se acepta como sinónimo.
        $pending_id = (int) $request->pending_id > 0 ? (int) $request->pending_id : (int) $request->id;

        $pending = Pending::where('id', $pending_id)
                            ->where('user_id', $this->userId())
                            ->first();

        if (is_null($pending)) {

            return response()->json(['message' => 'La tarea no existe.'], 404);
        }

        $fecha = $this->parsear_fecha($request->fecha_realizacion);

        if (is_null($fecha)) {

            // Una puntual tiene una sola fecha posible: no hace falta que la manden.
            if (!$pending->es_recurrente && !is_null($pending->fecha_realizacion)) {

                $fecha = Carbon::parse($pending->fecha_realizacion)->startOfDay();

            } else {

                return response()->json(['message' => 'Indicá qué fecha de la tarea se hizo (AAAA-MM-DD).'], 422);
            }
        }

        $sin_gasto = $request->boolean('sin_gasto');
        $expense = is_array($request->expense) ? $request->expense : [];

        /*
         * Prevalidación de las cajas destino ANTES de llamar al helper, igual que
         * ExpenseController::store(): así el 422 no deja nada escrito, ni siquiera algo que la
         * transacción tenga que revertir. Ver el comentario largo en ese controller.
         */
        if ((int) $pending->expense_concept_id > 0 && !$sin_gasto) {

            $payment_methods = isset($expense['payment_methods']) ? $expense['payment_methods'] : null;

            $cajas_sin_apertura = CurrentAcountCajaHelper::cajas_sin_apertura_en_payload($payment_methods);

            if (count($cajas_sin_apertura)) {

                return response()->json([
                    'message' => 'Las siguientes cajas nunca se abrieron: '.implode(', ', $cajas_sin_apertura).'. Hay que abrirlas para poder registrar el gasto.',
                ], 422);
            }
        }

        try {

            $model = AgendaCompletarHelper::completar($pending, $fecha, [
                'notas'     => $request->notas,
                'sin_gasto' => $sin_gasto,
                'expense'   => $expense,
            ], $this->userId(), function () {
                // Adentro de la transacción del helper, para que el lock del correlativo se
                // sostenga hasta el commit (ver ExpenseHelper::crear()).
                return $this->num('expenses');
            });

        } catch (AgendaYaCompletadaException $e) {

            return response()->json(['message' => $e->getMessage()], 409);

        } catch (AgendaGastoRequeridoException $e) {

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->sendAddModelNotification('PendingCompleted', $model->id);

        $model = $this->fullModel('PendingCompleted', $model->id);

        return response()->json([
            'model'     => $model,
            'expense'   => $model->expense,
        ], 201);
    }

    public function show($id) {

        $model = $this->completada_de_la_cuenta($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La tarea realizada no existe.'], 404);
        }

        return response()->json(['model' => $this->fullModel('PendingCompleted', $model->id)], 200);
    }

    /**
     * No lo usa nadie (la SPA nunca edita una realizada), pero si alguien lo llama tiene que
     * respetar la cuenta. Lo único editable son las notas: el resto sale de la tarea.
     */
    public function update(Request $request, $id) {

        $model = $this->completada_de_la_cuenta($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La tarea realizada no existe.'], 404);
        }

        $model->notas = $request->notas;
        $model->save();

        $this->sendAddModelNotification('PendingCompleted', $model->id);
        return response()->json(['model' => $this->fullModel('PendingCompleted', $model->id)], 200);
    }

    /**
     * "Deshacer": borra la marca de hecha y, si la tarea es puntual, la vuelve a pendiente.
     *
     * 🔴 El gasto NO se toca. Borrarlo implicaría compensar los movimientos de caja que generó
     * (o no, según lo que el usuario elija), y ese flujo ya existe en Gastos con su modal de
     * confirmación y su DeleteCajaCompensacionHelper: duplicarlo acá sería tener dos criterios
     * para la misma decisión. Se devuelve `expense_id` para que la SPA avise que el gasto queda
     * cargado y se borra desde Gastos.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id) {

        $model = $this->completada_de_la_cuenta($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La tarea realizada no existe.'], 404);
        }

        $expense_id = is_null($model->expense_id) ? null : (int) $model->expense_id;

        DB::transaction(function () use ($model) {

            $pending = Pending::where('id', $model->pending_id)->first();

            // Hasta el 14/9/2026 deshacer borraba el PendingCompleted y dejaba la puntual en
            // `completado = 1`: desaparecía de las dos pestañas.
            if (!is_null($pending) && !$pending->es_recurrente) {

                $pending->completado = 0;
                $pending->save();
            }

            ImageController::deleteModelImages($model);
            $model->delete();
        });

        $this->sendDeleteModelNotification('PendingCompleted', $model->id);

        return response()->json(['expense_id' => $expense_id], 200);
    }

    /**
     * Busca la realizada SCOPEADA por la cuenta (null → 404). Hasta el 14/9/2026 destroy() hacía
     * `find($id)` pelado.
     *
     * @param  int  $id
     * @return \App\Models\PendingCompleted|null
     */
    protected function completada_de_la_cuenta($id) {

        return PendingCompleted::where('id', $id)
                                ->where('user_id', $this->userId())
                                ->first();
    }

    /**
     * Misma regla que PendingController::parsear_fecha(): toma el día de un `Y-m-d` (o del
     * principio de un `Y-m-d H:i:s`) sin convertir zona horaria, y rechaza fechas que no existen.
     *
     * @param  mixed  $valor
     * @return \Carbon\Carbon|null
     */
    protected function parsear_fecha($valor) {

        if (!is_string($valor) || !preg_match('/^(\d{4}-\d{2}-\d{2})/', $valor, $partes)) {

            return null;
        }

        try {

            $fecha = Carbon::createFromFormat('Y-m-d', $partes[1]);

        } catch (\Exception $e) {

            return null;
        }

        if ($fecha === false || $fecha->format('Y-m-d') !== $partes[1]) {

            return null;
        }

        return $fecha->startOfDay();
    }
}
