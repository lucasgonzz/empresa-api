<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\RecipeRoute;
use Illuminate\Http\Request;

class RecipeRouteController extends Controller
{

    public function index() {
        $models = RecipeRoute::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    /**
     * Normaliza a null el 0 que manda el select de la SPA cuando el usuario deja "Seleccione...".
     *
     * Sin este guard, un 0 en order_production_status_group_id haria que la cascada del estado
     * final busque el grupo con id 0 en vez de caer al comportamiento global, y un 0 en
     * end_order_production_status_id la haria comparar contra un estado inexistente: en los dos
     * casos el lote deja de dar de alta el producto y nada avisa.
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
        $model = RecipeRoute::create([
            'recipe_id'                 => $request->recipe_id,
            'recipe_route_type_id'      => $request->recipe_route_type_id,
            'from_address_id'           => $request->from_address_id,
            'to_address_id'             => $request->to_address_id,
            'order_production_status_group_id' => $this->nullIfZero($request->order_production_status_group_id),
            'end_order_production_status_id'   => $this->nullIfZero($request->end_order_production_status_id),
            'temporal_id'               => $this->getTemporalId($request),
            'recipe_id'                 => $request->model_id,
        ]);

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount', 'notes', 'order_production_status_id', 'address_id']);

        return response()->json(['model' => $this->fullModel('RecipeRoute', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('RecipeRoute', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = RecipeRoute::find($id);
        $model->recipe_route_type_id      = $request->recipe_route_type_id;
        $model->from_address_id           = $request->from_address_id;
        $model->to_address_id             = $request->to_address_id;
        $model->order_production_status_group_id = $this->nullIfZero($request->order_production_status_group_id);
        $model->end_order_production_status_id   = $this->nullIfZero($request->end_order_production_status_id);
        $model->save();

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount', 'notes', 'order_production_status_id', 'address_id']);
        
        return response()->json(['model' => $this->fullModel('RecipeRoute', $model->id)], 200);
    }

    public function destroy($id) {
        $model = RecipeRoute::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('RecipeRoute', $model->id);
        return response(null);
    }
}
