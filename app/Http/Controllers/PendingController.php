<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Pending;
use App\Models\PendingCompleted;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PendingController extends Controller
{

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

                // if ($fecha_de_realizacion)

                // $this->chequear_si_fue_completada();

                // Probar el ejemplo del pizaroron, hacer seeder para eso

                // Log::info('aumentando fecha_de_realizacion: '.$fecha_de_realizacion);
            }

            $ultima_realizada = PendingCompleted::where('pending_id', $recurrente->id)
                                                    ->orderBy('created_at', 'DESC')
                                                    ->first();

            if (is_null($ultima_realizada)) {

            } else {
                
            }

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

    public function store(Request $request) {

        $model = Pending::create([
            'detalle'                => $request->detalle,
            'fecha_realizacion'      => $request->fecha_realizacion,
            'es_recurrente'          => $request->es_recurrente,
            'unidad_frecuencia_id'   => $request->unidad_frecuencia_id,
            'cantidad_frecuencia'    => $request->cantidad_frecuencia,
            'expense_concept_id'     => $request->expense_concept_id,
            'notas'                  => $request->notas,
            'user_id'                => $this->userId(),
        ]);
        $this->sendAddModelNotification('Pending', $model->id);
        return response()->json(['model' => $this->fullModel('Pending', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Pending', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Pending::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('Pending', $model->id);
        return response()->json(['model' => $this->fullModel('Pending', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Pending::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Pending', $model->id);
        return response(null);
    }
}
