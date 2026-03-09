<?php

namespace App\Http\Controllers;

use App\Models\ProductionBatch;
use App\Models\Recipe;
use Illuminate\Http\Request;

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
            'recipe_id'                   => 'nullable|integer',
            'recipe_route_id'             => 'nullable|integer',
            'planned_amount'              => 'required|numeric|min:0.0001',
            'notes'                       => 'nullable|string',
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
        $model = ProductionBatch::findOrFail($id);
        $model->delete();

        return response(null, 204);
    }
}