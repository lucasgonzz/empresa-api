<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\OrderProductionStatus;
use Illuminate\Http\Request;

class OrderProductionStatusController extends Controller
{

    public function index() {
        $models = OrderProductionStatus::where('user_id', $this->userId())
                            ->orderBy('position', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    /**
     * Normaliza a null el 0 que manda el select de la SPA cuando el usuario deja "Seleccione...".
     *
     * El guard es obligatorio y no cosmetico: un 0 guardado en
     * order_production_status_group_id haria que la cascada del estado final busque el grupo con
     * id 0 —que no existe— en vez de caer al comportamiento global, y el lote dejaria de dar de
     * alta el producto sin que nada avise.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private function nullIfZero($value)
    {
        if (is_null($value) || $value == 0) {
            return null;
        }

        return $value;
    }

    public function store(Request $request) {
        $model = OrderProductionStatus::create([
            // 'num'                   => $this->num('order_production_statuses'),
            'name'                  => $request->name,
            'position'              => $request->position,
            'order_production_status_group_id' => $this->nullIfZero($request->order_production_status_group_id),
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('order_production_status', $model->id);
        return response()->json(['model' => $this->fullModel('OrderProductionStatus', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('OrderProductionStatus', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = OrderProductionStatus::find($id);
        $model->name                = $request->name;
        $model->position                = $request->position;
        $model->order_production_status_group_id = $this->nullIfZero($request->order_production_status_group_id);
        $model->save();
        $this->sendAddModelNotification('order_production_status', $model->id);
        return response()->json(['model' => $this->fullModel('OrderProductionStatus', $model->id)], 200);
    }

    public function destroy($id) {
        $model = OrderProductionStatus::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('order_production_status', $model->id);
        return response(null);
    }
}
