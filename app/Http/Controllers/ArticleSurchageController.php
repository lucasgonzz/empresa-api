<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\DescuentoRecargoExcluyenteHelper;
use App\Models\ArticleSurchage;
use Illuminate\Http\Request;

class ArticleSurchageController extends Controller
{

    public function index() {
        $models = ArticleSurchage::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        // Un recargo lleva SOLO porcentaje o SOLO monto: el monto de mas queda inerte en el
        // calculo de precios. Ver DescuentoRecargoExcluyenteHelper.
        if (DescuentoRecargoExcluyenteHelper::hay_conflicto($request)) {
            return DescuentoRecargoExcluyenteHelper::respuesta_de_conflicto();
        }
        $model = ArticleSurchage::create([
            'article_id'            => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'percentage'            => $request->percentage,
            'amount'                => $request->amount,
            'luego_del_precio_final'                => $request->luego_del_precio_final,
            // Naturaleza contable del recargo (Prompt 260). Nullable, sin UI todavía.
            'tipo'                  => $request->tipo,
        ]);
        if (!is_null($request->model_id)) {
            ArticleHelper::setFinalPrice($model->article);
            $this->sendAddModelNotification('article', $model->article_id, false);
        }
        return response()->json(['model' => $this->fullModel('ArticleSurchage', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticleSurchage', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticleSurchage::find($id);
        // A diferencia de store(), aca se rechaza solo si el request INTRODUCE el conflicto: una
        // fila vieja que ya venia con los dos cargados se puede volver a guardar sin cambiarlos.
        // El porque esta escrito en DescuentoRecargoExcluyenteHelper::introduce_conflicto().
        if (DescuentoRecargoExcluyenteHelper::introduce_conflicto($request, $model)) {
            return DescuentoRecargoExcluyenteHelper::respuesta_de_conflicto();
        }
        $model->percentage                = $request->percentage;
        $model->amount                    = $request->amount;
        $model->luego_del_precio_final    = $request->luego_del_precio_final;
        // Naturaleza contable del recargo (Prompt 260). Sin UI todavía: solo se pisa si el
        // request lo manda explícitamente, para no perder el backfill 'otro' en updates existentes.
        if (!is_null($request->tipo)) {
            $model->tipo = $request->tipo;
        }
        $model->save();
        ArticleHelper::setFinalPrice($model->article);
        $this->sendAddModelNotification('article', $model->article_id, false);
        return response()->json(['model' => $this->fullModel('ArticleSurchage', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticleSurchage::find($id);
        // El artículo se resuelve ANTES de borrar: sin la fila ya no hay forma de saber cuál recalcular.
        $article = $model->article;
        $model->delete();
        ImageController::deleteModelImages($model);
        /*
         * 🔴 NO sacar: borrar un recargo tiene que recalcular el costo real, el precio y las listas, igual que
         * store() y update(). El guardado de la ficha conserva el costo real guardado cuando nada cambió
         * (misión guardar-sin-cambios-listas-y-costo-real), así que ya no es él quien pone al día lo que
         * este endpoint dejaba atrasado. La SPA borra por DELETE api/<recurso>/{id}. Un recargo sin artículo
         * (alta con temporal_id, todavía sin guardar) no recalcula nada.
         */
        if (!is_null($article)) {
            ArticleHelper::setFinalPrice($article);
            $this->sendAddModelNotification('article', $article->id, false);
        }
        $this->sendDeleteModelNotification('ArticleSurchage', $model->id);
        return response(null);
    }
}
