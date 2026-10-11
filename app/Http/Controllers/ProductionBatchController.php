<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ProductionBatchMovementHelper;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductionBatchController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = ProductionBatch::where('user_id', $this->userId())
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


    public function store(Request $request)
    {
        $request->validate([
            // 'article_id'                  => 'required|integer',
            'production_batch_status_id'  => 'required|integer',

            // La receta es obligatoria y tiene que ser de este comercio: de ella sale el articulo
            // del lote. Antes `Recipe::find(null)->article_id` daba un 500, y con la receta de otro
            // comercio el lote salia con el articulo ajeno.
            'recipe_id'                   => ['required', 'integer', Rule::exists('recipes', 'id')->where('user_id', $this->userId())],
            'recipe_route_id'             => 'nullable|integer',
            'planned_amount'              => 'required|numeric|min:0.0001',
            'notes'                       => 'nullable|string',
        ], [
            'recipe_id.required'    => 'Elegí la receta que se va a fabricar en el lote.',
            'recipe_id.integer'     => 'Elegí la receta que se va a fabricar en el lote.',
            'recipe_id.exists'      => 'La receta elegida no existe.',
        ]);

        $recipe = Recipe::find($request->recipe_id);

        $model = ProductionBatch::create([
            'article_id'                  => $recipe->article_id,
            'recipe_id'                   => $request->recipe_id,
            'recipe_route_id'             => $request->recipe_route_id,
            'production_batch_status_id'  => $request->production_batch_status_id,
            'planned_amount'              => $request->planned_amount,
            'notes'                       => $request->notes,
            'employee_id'                 => $this->userId(false),
            'user_id'                     => $this->userId(),
        ]);

        return response()->json(['model' => $this->fullModel('ProductionBatch', $model->id)], 201);
    }

    public function show($id)
    {
        $model = ProductionBatch::withAll()->findOrFail($id);

        return response()->json(['model' => $model], 200);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            // 'article_id'                  => 'required|integer',
            'production_batch_status_id'  => 'required|integer',
            'recipe_id'                   => 'nullable|integer',
            'recipe_route_id'             => 'nullable|integer',
            'planned_amount'              => 'required|numeric|min:0.0001',
            'notes'                       => 'nullable|string',
        ]);

        $model = ProductionBatch::findOrFail($id);

        // $model->article_id                 = $request->article_id;
        $model->production_batch_status_id = $request->production_batch_status_id;
        // $model->recipe_id                  = $request->recipe_id;
        // $model->recipe_route_id            = $request->recipe_route_id;
        // $model->planned_amount             = $request->planned_amount;
        $model->notes                      = $request->notes;
        $model->save();

        return response()->json(['model' => $this->fullModel('ProductionBatch', $id)], 200);
    }

    public function destroy($id)
    {
        // Solo lotes de este comercio: con el findOrFail pelado un id ajeno se llevaba el lote de
        // otro dueño (la base de produccion compartida tiene 51 comercios).
        $model = ProductionBatch::where('user_id', $this->userId())->findOrFail($id);

        // Revierte todos los movimientos del lote (insumos devueltos, producto sacado) y recien
        // despues lo borra, todo en una transaccion.
        ProductionBatchMovementHelper::delete_batch($model, $this);

        return response(null, 204);
    }
}