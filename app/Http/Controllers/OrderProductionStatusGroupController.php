<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\OrderProductionStatusGroup;
use Illuminate\Http\Request;

class OrderProductionStatusGroupController extends Controller
{

    public function index() {
        $models = OrderProductionStatusGroup::where('user_id', $this->userId())
                            ->orderBy('position', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = OrderProductionStatusGroup::create([
            'name'                  => $request->name,
            'position'              => $request->position,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('order_production_status_group', $model->id);
        return response()->json(['model' => $this->fullModel('OrderProductionStatusGroup', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('OrderProductionStatusGroup', $id)], 200);
    }

    /**
     * El grupo del comercio de la sesion, o 404.
     *
     * Buscar por id a secas dejaba que cualquier comercio renombrara o borrara el grupo de otro:
     * `order_production_status_groups` es una tabla de todos, y el id llega crudo por la URL.
     * Borrar un grupo ajeno le cambia el estado final a las rutas que lo usan, y el lote deja de
     * dar de alta el producto sin que nada avise.
     *
     * @param  int  $id
     * @return \App\Models\OrderProductionStatusGroup
     */
    private function delComercio($id) {
        return OrderProductionStatusGroup::where('user_id', $this->userId())->findOrFail($id);
    }

    public function update(Request $request, $id) {
        $model = $this->delComercio($id);
        $model->name                = $request->name;
        $model->position            = $request->position;
        $model->save();
        $this->sendAddModelNotification('order_production_status_group', $model->id);
        return response()->json(['model' => $this->fullModel('OrderProductionStatusGroup', $model->id)], 200);
    }

    public function destroy($id) {
        $model = $this->delComercio($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('order_production_status_group', $model->id);
        return response(null);
    }
}
