<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\RoadMapHelper;
use App\Http\Controllers\Pdf\RoadMapPdf;
use App\Models\RoadMap;
use App\Models\RoadMapClientObservation;
use App\Models\RoadMapClientPosition;
use App\Models\Sale;
use Illuminate\Http\Request;

class RoadMapController extends Controller
{

    /**
     * ¿El usuario solo puede ver SUS hojas de ruta? Es así cuando no es dueño ni administrador, tiene
     * `road_map.terminadas.only_your` y no tiene `road_map.terminadas.all` (si tiene los dos, gana
     * "todas"). Sin ninguno de los dos permisos ve todas, como siempre: nadie pierde visibilidad
     * por un permiso que nunca tuvo tildado. Mismo criterio que `sale.index.employees.*`.
     *
     * @return bool
     */
    protected function solo_sus_hojas() {
        if ($this->is_admin()) {
            return false;
        }

        $permisos = $this->user(false)->permissions;

        return $permisos->contains('slug', 'road_map.terminadas.only_your')
            && !$permisos->contains('slug', 'road_map.terminadas.all');
    }

    /**
     * Una hoja de ruta con el mismo formato que el listado (con `clientes` agrupados y ordenados), para
     * que la SPA pueda mostrarla en Rutas sin recargar. Antes store/update/show devolvían el modelo
     * pelado y el modal de Rutas salía sin clientes hasta volver a pedir el listado.
     *
     * @param  int  $id
     * @return \App\Models\RoadMap|null
     */
    protected function hoja_completa($id) {
        $model = $this->fullModel('RoadMap', $id);

        if (is_null($model)) {
            return null;
        }

        return RoadMapHelper::agrupar_clientes(collect([$model]))->first();
    }

    public function index($employee_id, $date_param, $from_date = null, $until_date = null) {
        $models = RoadMap::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();

        if ($this->solo_sus_hojas()) {
            // Se ignora el repartidor que vino en la URL: el filtro no puede depender de la SPA.
            $employee_id = $this->user(false)->id;
        }

        if ($employee_id != 0) {
            $models = $models->where('employee_id', $employee_id);
        }

        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate($date_param, '>=', $from_date)
                                ->whereDate($date_param, '<=', $until_date);
            } else {
                $models = $models->whereDate($date_param, $from_date);
            }
        }

        $models = $models->get();

        $models = RoadMapHelper::agrupar_clientes($models);
        
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = RoadMap::create([
            'num'                   => $this->num('road_maps'),
            'employee_id'           => $request->employee_id,
            'fecha_entrega'         => $request->fecha_entrega,
            'notes'                 => $request->notes,
            'terminada'             => $request->terminada,
            'user_id'               => $this->userId(),
        ]);
        
        GeneralHelper::attachModels($model, 'sales', $request->sales);

        RoadMapHelper::attach_client_positions($model, $request->client_positions);

        $this->sendAddModelNotification('RoadMap', $model->id);
        return response()->json(['model' => $this->hoja_completa($model->id)], 201);
    }  

    public function show($id) {
        $model = $this->hoja_completa($id);

        if (is_null($model) || $model->user_id != $this->userId()
            || ($this->solo_sus_hojas() && $model->employee_id != $this->user(false)->id)) {
            abort(404);
        }

        return response()->json(['model' => $model], 200);
    }

    public function update(Request $request, $id) {
        $model = RoadMap::find($id);
        $model->employee_id          = $request->employee_id;
        $model->fecha_entrega        = $request->fecha_entrega;
        $model->notes                = $request->notes;
        $model->terminada            = $request->terminada;
        $model->save();

        GeneralHelper::attachModels($model, 'sales', $request->sales);
        
        RoadMapHelper::attach_client_positions($model, $request->client_positions);
        
        $this->sendAddModelNotification('RoadMap', $model->id);
        return response()->json(['model' => $this->hoja_completa($model->id)], 200);
    }

    public function destroy($id) {
        $model = RoadMap::where('id', $id)
                        ->where('user_id', $this->userId())
                        ->first();

        if (is_null($model)) {
            // Respuesta y no abort(): el borrado masivo (DeleteModelsHelper) llama a destroy() por cada
            // id y cuenta como "no borrada" a la que responde 4xx; una excepción cortaría todo el lote.
            return response()->json(['message' => 'La hoja de ruta no existe.'], 404);
        }

        // Los pivots y las filas hijas no tienen clave foránea: si no se borran acá quedan colgando
        // (y las ventas seguirían figurando "en una hoja" que ya no existe).
        $model->sales()->detach();
        RoadMapClientPosition::where('road_map_id', $model->id)->delete();
        RoadMapClientObservation::where('road_map_id', $model->id)->delete();

        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('RoadMap', $model->id);
        return response(null);
    }

    function search_sales($fecha_entrega) {

        $sales = Sale::where('user_id', $this->userId())
                        ->where('terminada', 0)
                        ->whereNotNull('fecha_entrega')
                        ->whereDate('fecha_entrega', $fecha_entrega)
                        ->orderBy('created_at', 'ASC')
                        ->withAll()
                        ->get();

        return response()->json(['models' => $sales], 200);
    }

    function pdf($id) {
        $models = RoadMap::where('id', $id)
                            ->get();
                            
        $model = RoadMapHelper::agrupar_clientes($models)[0];
        new RoadMapPdf($model);
    }

    // function search_sales(Request $request) {
    //     $search_query = $request->query_value;

    //     $sales = Sale::where('user_id', $this->userId())
    //                     ->where('terminada', 0)
    //                     ->whereNotNull('fecha_entrega')
    //                     ->where(function($query) use ($search_query) {
    //                         $query->where('num', $search_query)
    //                                 ->orWhereHas('client', function($q) use ($search_query) {
    //                                     $q->where('name', 'LIKE', "%$search_query%");
    //                                 });
    //                     })
    //                     ->orderBy('created_at', 'ASC')
    //                     ->paginate(100);

    //     return response()->json(['models' => $sales], 200);
    // }
}
