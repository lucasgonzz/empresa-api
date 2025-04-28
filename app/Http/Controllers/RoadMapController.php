<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\RoadMapHelper;
use App\Models\RoadMap;
use App\Models\Sale;
use Illuminate\Http\Request;

class RoadMapController extends Controller
{


    public function index($employee_id, $date_param, $from_date = null, $until_date = null) {
        $models = RoadMap::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();

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

        $this->sendAddModelNotification('RoadMap', $model->id);
        return response()->json(['model' => $this->fullModel('RoadMap', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('RoadMap', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = RoadMap::find($id);
        $model->employee_id          = $request->employee_id;
        $model->fecha_entrega        = $request->fecha_entrega;
        $model->notes                = $request->notes;
        $model->terminada            = $request->terminada;
        $model->save();

        GeneralHelper::attachModels($model, 'sales', $request->sales);
        
        $this->sendAddModelNotification('RoadMap', $model->id);
        return response()->json(['model' => $this->fullModel('RoadMap', $model->id)], 200);
    }

    public function destroy($id) {
        $model = RoadMap::find($id);
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
