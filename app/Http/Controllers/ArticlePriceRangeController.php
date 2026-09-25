<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\CriterioDeOfertaPorCantidadHelper;
use App\Models\ArticlePriceRange;
use Illuminate\Http\Request;

class ArticlePriceRangeController extends Controller
{

    public function store(Request $request) {
        $valores = CriterioDeOfertaPorCantidadHelper::normalizar_par($request->price, $request->porcentaje);
        $model = ArticlePriceRange::create([
            'article_id'                  => $request->model_id,
            'modo'                  => $request->modo,
            'amount'                  => $request->amount,
            'price'                  => $valores['price'],
            'porcentaje'                  => $valores['porcentaje'],
            'temporal_id'           => $this->getTemporalId($request),
        ]);
        return response()->json(['model' => $this->fullModel('ArticlePriceRange', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticlePriceRange', $id)], 200);
    }

    public function update(Request $request, $id) {
        $valores = CriterioDeOfertaPorCantidadHelper::normalizar_par($request->price, $request->porcentaje);
        $model = ArticlePriceRange::find($id);
        $model->modo                = $request->modo;
        $model->amount                = $request->amount;
        $model->price                = $valores['price'];
        $model->porcentaje                = $valores['porcentaje'];
        $model->save();
        return response()->json(['model' => $this->fullModel('ArticlePriceRange', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticlePriceRange::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ArticlePriceRange', $model->id);
        return response(null);
    }
}
