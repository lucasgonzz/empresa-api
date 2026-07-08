<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\SaleTax;
use Illuminate\Http\Request;

/**
 * CRUD estándar de impuestos sobre ventas (sale_taxes — Capa 2 del motor de precios).
 * Sigue el mismo patrón que CuotaController.
 */
class SaleTaxController extends Controller
{

    public function index() {
        $models = SaleTax::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = SaleTax::create([
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'apply_to_all'          => $request->apply_to_all,
            'activo'                => $request->activo,
            'user_id'               => $this->userId(),
        ]);
        // Si apply_to_all = false, sincronizo los artículos a los que aplica el impuesto
        if (!is_null($request->article_ids)) {
            $model->articles()->sync($request->article_ids);
        }
        $this->sendAddModelNotification('SaleTax', $model->id);
        return response()->json(['model' => $this->fullModel('SaleTax', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('SaleTax', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = SaleTax::find($id);
        $model->name                  = $request->name;
        $model->percentage            = $request->percentage;
        $model->apply_to_all          = $request->apply_to_all;
        $model->activo                = $request->activo;
        $model->save();

        // Si apply_to_all = false, sincronizo los artículos a los que aplica el impuesto
        if (!is_null($request->article_ids)) {
            $model->articles()->sync($request->article_ids);
        }

        $this->sendAddModelNotification('SaleTax', $model->id);
        return response()->json(['model' => $this->fullModel('SaleTax', $model->id)], 200);
    }

    public function destroy($id) {
        $model = SaleTax::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('SaleTax', $model->id);
        return response(null);
    }
}
