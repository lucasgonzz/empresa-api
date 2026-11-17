<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\MedidasEnvioHelper;
use App\Http\Controllers\Helpers\PromocionVinotecaHelper;
use App\Http\Controllers\Helpers\EdicionPromocionVinotecaHelper as Edicion;
use App\Models\PromocionVinoteca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromocionVinotecaController extends Controller
{

    public function index() {
        $models = PromocionVinoteca::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $medidas = MedidasEnvioHelper::datos($request->all());
        $model = PromocionVinoteca::create(array_merge([
            'name'                  => $request->name,
            'slug'                  => ArticleHelper::slug($request->name),
            'cost'                  => $request->cost,
            'final_price'           => $request->final_price,
            'stock'                 => $request->stock,
            'online'                => $request->online,
            'address_id'            => $request->address_id,
            'description'           => $request->description,
            'user_id'               => $this->userId(),
        ], $medidas));

        PromocionVinotecaHelper::attach_articles($model, $request->articles);

        PromocionVinotecaHelper::set_cost($model, $request->articles);

        $this->updateRelationsCreated('promocion_vinoteca', $model->id, $request->childrens);

        // $this->sendAddModelNotification('PromocionVinoteca', $model->id);
        return response()->json(['model' => $this->fullModel('PromocionVinoteca', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PromocionVinoteca', $id)], 200);
    }

    public function update(Request $request, $id) {
        return DB::transaction(function () use ($request, $id) {
            $model = Edicion::promo($id, $this->userId(), true);
            $datos = Edicion::validar($model, $request->all(), $this->userId());
            if ($request->attributes->has('referencia_edicion_promo')) {
                Edicion::comprobar_referencia($model, $datos, $request->attributes->get('referencia_edicion_promo'));
            }
            // Omitido no significa vacío. El costo guardado es POR PROMO y no se vuelve a
            // dividir por el stock remanente: la cantidad del armado solo se conoce al crear.
            $model->fill($datos);
            if (array_key_exists('name', $datos)) {
                $model->slug = ArticleHelper::slug($datos['name']);
            }
            $model->save();
            return response()->json(['model' => $this->fullModel('PromocionVinoteca', $model->id)], 200);
        });
    }

    function delete_stock(Request $request, $id) {

        $model = PromocionVinoteca::find($id);
        $model->stock -= $request->stock_a_eliminar;
        $model->save();

        PromocionVinotecaHelper::regresar_stock($model, $request->articles);
        
        if ($model->stock <= 0) {
            $model->delete();
        }

    }

    public function destroy($id) {
        $model = PromocionVinoteca::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('PromocionVinoteca', $model->id);
        return response(null);
    }
}
