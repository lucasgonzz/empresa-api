<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\agenda\AgendaHelper;
use App\Http\Controllers\Helpers\agenda\AgendaTareaHelper;
use App\Models\Pending;
use App\Models\PendingCompleted;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PendingController extends Controller
{

    function recurrentes() {
        $models = Pending::where('user_id', $this->userId())
                            ->where('es_recurrente', 1)
                            ->orderBy('created_at', 'ASC')
                            ->withAll()
                            ->get();

        foreach ($models as $model) {
            $model->fecha_realizacion = null;
        }

        return response()->json(['models' => $models], 200);
    }

    public function index($from_date = null, $until_date = null) {
        $pendings = Pending::where('user_id', $this->userId())
                                    ->where('es_recurrente', 0)
                                    ->where('completado', 0)
                                    ->whereBetween('fecha_realizacion', [$from_date, $until_date])
                                    ->orderBy('created_at', 'DESC')
                                    ->withAll()
                                    ->get();

        $recurrentes = Pending::where('user_id', $this->userId())
                                    ->where('es_recurrente', 1)
                                    ->orderBy('created_at', 'DESC')
                                    ->withAll()
                                    ->get();

        foreach ($recurrentes as $recurrente) {

            $fecha_de_realizacion = Carbon::parse($recurrente->fecha_realizacion);

            // Log::info('fecha_de_realizacion: '.$fecha_de_realizacion);
            
            while ($fecha_de_realizacion->lt($from_date)) {
                $fecha_de_realizacion->addUnit($recurrente->unidad_frecuencia->slug, $recurrente->cantidad_frecuencia);
            }

            // $ultima_realizada = PendingCompleted::where('pending_id', $recurrente->id)
            //                                         ->orderBy('created_at', 'DESC')
            //                                         ->first();

            while ($fecha_de_realizacion->between($from_date, $until_date)) {

                // Log::info('comparando fecha_de_realizacion: '.$fecha_de_realizacion);
                $pending_completed = PendingCompleted::where('pending_id', $recurrente->id)
                                                        ->whereDate('fecha_realizacion', $fecha_de_realizacion)
                                                        ->first();

                if (is_null($pending_completed)) {

                    // Log::info('No habia tarea, agregando al array:');
                    $pendings->push([
                        'id'                    => $recurrente->id,
                        'detalle'               => $recurrente->detalle,
                        'fecha_realizacion'     => $fecha_de_realizacion->copy(),
                        'unidad_frecuencia_id'  => $recurrente->unidad_frecuencia_id,
                        'cantidad_frecuencia'   => $recurrente->cantidad_frecuencia,
                        'expense_concept_id'    => $recurrente->expense_concept_id,
                        'notas'                 => $recurrente->notas,
                        'es_recurrente'         => 1,
                        'completado'            => 0,
                    ]);

                    // Log::info($pendings);
                }

                // Log::info('Se agregaron '.$recurrente->cantidad_frecuencia.' - '.$recurrente->unidad_frecuencia->slug.':');
                $fecha_de_realizacion->addUnit($recurrente->unidad_frecuencia->slug, $recurrente->cantidad_frecuencia);
                // Log::info($fecha_de_realizacion);
            }
        }

        return response()->json(['models' => $pendings], 200);
    }

    /**
     * Máximo de días (inclusive) que acepta un rango de la agenda. La lista pide 60 y el
     * calendario un mes con sus bordes (42): 120 deja margen y corta un pedido que expandiría
     * miles de ocurrencias por error.
     */
    const MAX_DIAS_RANGO_AGENDA = 120;

    /**
     * Misión agenda-tareas-calendario (14/9/2026): `GET pending-agenda/{desde}/{hasta}`. Devuelve
     * las ocurrencias del rango (puntuales y recurrentes expandidas) más las vencidas de la
     * cuenta, que van aparte y sin rango. Ver AgendaHelper.
     *
     * `index()` y `recurrentes()` de arriba quedan como estaban: la SPA vieja los usa.
     *
     * @param  string  $desde  Y-m-d, inclusive.
     * @param  string  $hasta  Y-m-d, inclusive.
     * @return \Illuminate\Http\JsonResponse
     */
    public function agenda($desde, $hasta) {

        $desde_c = AgendaTareaHelper::parsear_fecha($desde);
        $hasta_c = AgendaTareaHelper::parsear_fecha($hasta);

        if (is_null($desde_c) || is_null($hasta_c)) {

            return response()->json(['message' => 'Las fechas tienen que venir como AAAA-MM-DD.'], 422);
        }

        if ($hasta_c->lt($desde_c)) {

            return response()->json(['message' => 'La fecha hasta no puede ser anterior a la fecha desde.'], 422);
        }

        if ($desde_c->diffInDays($hasta_c) > self::MAX_DIAS_RANGO_AGENDA) {

            return response()->json(['message' => 'El rango de la agenda no puede superar los '.self::MAX_DIAS_RANGO_AGENDA.' días.'], 422);
        }

        // Carbon::today() sale en la zona de la app (America/Argentina/Buenos_Aires), que es la
        // que define qué es "hoy" para el comercio.
        $hoy = Carbon::today();

        return response()->json([
            'hoy'           => $hoy->format('Y-m-d'),
            'vencidas'      => AgendaHelper::vencidas($this->userId(), $hoy),
            'ocurrencias'   => AgendaHelper::ocurrencias_entre($this->userId(), $desde_c, $hasta_c),
        ], 200);
    }

    public function store(Request $request) {

        /*
         * La validación vive en AgendaTareaHelper desde la misión asistente-ia-acciones (15/9/2026):
         * el asistente de IA también da de alta tareas y tiene que pasar por las mismas reglas y los
         * mismos mensajes. Se le pasa el body entero (`all()`), que es lo que leía `$request->clave`.
         */
        $datos = AgendaTareaHelper::validar($request->all(), $this->userId());

        if (is_string($datos)) {

            return response()->json(['message' => $datos], 422);
        }

        $datos['completado'] = 0;
        $datos['user_id'] = $this->userId();

        $model = Pending::create($datos);

        $this->sendAddModelNotification('Pending', $model->id);
        return response()->json(['model' => $this->fullModel('Pending', $model->id)], 201);
    }

    public function show($id) {

        $model = $this->tarea_de_la_cuenta($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La tarea no existe.'], 404);
        }

        return response()->json(['model' => $this->fullModel('Pending', $model->id)], 200);
    }

    public function update(Request $request, $id) {

        $model = $this->tarea_de_la_cuenta($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La tarea no existe.'], 404);
        }

        // Mismas reglas y mensajes que store(): viven en AgendaTareaHelper (ver el comentario de store()).
        $datos = AgendaTareaHelper::validar($request->all(), $this->userId());

        if (is_string($datos)) {

            return response()->json(['message' => $datos], 422);
        }

        /*
         * Si cambió la REGLA de una recurrente (primera fecha, unidad o cantidad) y la primera
         * fecha nueva quedó en el pasado, la base se mueve a la primera ocurrencia de la regla
         * nueva desde hoy. Sin esto, las ocurrencias ya hechas bajo la regla vieja reaparecían
         * como vencidas: una mensual del 5 con tres realizadas, editada al 10, devolvía 10/6, 10/7
         * y 10/8 en rojo, porque la expansión arranca siempre en la base con la regla actual y
         * las PendingCompleted viejas quedan colgadas de otras fechas. La edición de una regla
         * es "de acá en adelante"; lo ya hecho queda en Realizadas con su fecha. Una edición que
         * no toca la regla (detalle, notas, monto, gasto) no mueve nada. Lo encontró el chequeo
         * independiente del 14/9/2026. La cuenta vive en AgendaTareaHelper: el asistente de IA
         * edita tareas por el mismo camino.
         */
        $datos['fecha_realizacion'] = AgendaTareaHelper::reanclar_si_cambio_la_regla($model, $datos);

        /*
         * Hasta el 14/9/2026 update() no escribía `expense_amount`: el monto se perdía al editar
         * la tarea. Ahora se guarda el mismo conjunto de columnas que en store(), incluida
         * `fecha_fin_recurrencia`.
         */
        foreach ($datos as $columna => $valor) {

            $model->{$columna} = $valor;
        }

        /*
         * `completado` solo se puede BAJAR desde acá, y solo si viene explícito en false: es la
         * salida para las puntuales que la SPA vieja dejó en `completado = 1` sin PendingCompleted
         * (no tienen nada que deshacer en PendingCompletedController). Subirlo a 1 sigue siendo
         * trabajo exclusivo de marcar como hecha, que es lo que registra el gasto.
         */
        if ($request->has('completado') && !$request->boolean('completado')) {

            $model->completado = 0;
        }

        $model->save();

        $this->sendAddModelNotification('Pending', $model->id);
        return response()->json(['model' => $this->fullModel('Pending', $model->id)], 200);
    }

    public function destroy($id) {

        $model = $this->tarea_de_la_cuenta($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La tarea no existe.'], 404);
        }

        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Pending', $model->id);
        return response(null);
    }

    /**
     * Busca la tarea SCOPEADA por la cuenta. Hasta el 14/9/2026 update()/destroy() hacían
     * `find($id)` pelado y cualquier usuario autenticado podía editar o borrar pendientes de
     * otra cuenta con solo adivinar el id. Devuelve null (→ 404) si no es de la cuenta, sin
     * distinguir "no existe" de "no es tuya": para el que llama es lo mismo.
     *
     * @param  int  $id
     * @return \App\Models\Pending|null
     */
    protected function tarea_de_la_cuenta($id) {

        return Pending::where('id', $id)
                        ->where('user_id', $this->userId())
                        ->first();
    }

    /*
     * La validación del body (validar), el reancle de una regla editada (reanclar_si_cambio_la_regla)
     * y el parseo de fechas (parsear_fecha) viven en AgendaTareaHelper desde la misión
     * asistente-ia-acciones (15/9/2026): el asistente de IA da de alta y edita tareas por el mismo
     * camino que esta pantalla.
     */
}
