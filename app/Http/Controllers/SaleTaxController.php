<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\SaleTax;
use Illuminate\Http\Request;

/**
 * CRUD estándar de impuestos sobre ventas (sale_taxes — Capa 2 del motor de precios).
 * Sigue el mismo patrón que CuotaController.
 *
 * Prompt 261: crear/editar/eliminar un sale_tax dispara el mismo recálculo masivo de precios
 * que ya usa PriceType (ProcessSetFinalPrices), ya que sale_taxes forma parte del cálculo de
 * Capa 2 (precio de lista) y afecta a todos los artículos alcanzados por el impuesto.
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

        // Recalculo masivo de precios en background: el nuevo impuesto puede afectar a todos
        // los articulos del usuario (apply_to_all) o a los vinculados por pivot.
        ProcessSetFinalPrices::dispatch($this->userId());

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

        // Recalculo masivo de precios en background: cambios en el impuesto (porcentaje,
        // activo, apply_to_all, articulos vinculados) impactan en el precio final calculado.
        ProcessSetFinalPrices::dispatch($this->userId());

        $this->sendAddModelNotification('SaleTax', $model->id);
        return response()->json(['model' => $this->fullModel('SaleTax', $model->id)], 200);
    }

    public function destroy($id) {
        $model = SaleTax::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();

        // Recalculo masivo de precios en background: al eliminar el impuesto, los articulos
        // alcanzados dejan de tenerlo aplicado.
        ProcessSetFinalPrices::dispatch($this->userId());

        $this->sendDeleteModelNotification('SaleTax', $model->id);
        return response(null);
    }
}
