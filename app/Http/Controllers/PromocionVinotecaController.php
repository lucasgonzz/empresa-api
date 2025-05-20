<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PromocionVinotecaHelper;
use App\Models\PromocionVinoteca;
use Illuminate\Http\Request;

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
        $model = PromocionVinoteca::create([
            'name'                  => $request->name,
            'slug'                  => ArticleHelper::slug($request->name),
            'cost'                  => $request->cost,
            'final_price'           => $request->final_price,
            'stock'                 => $request->stock,
            'address_id'            => $request->address_id,
            'description'           => $request->description,
            'user_id'               => $this->userId(),
        ]);

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
        $model = PromocionVinoteca::find($id);
        $model->name                = $request->name;
        $model->cost                = $request->cost;
        $model->slug                = ArticleHelper::slug($request->name);
        $model->final_price         = $request->final_price;
        $model->stock               = $request->stock;
        $model->address_id          = $request->address_id;
        $model->description         = $request->description;
        $model->save();

        PromocionVinotecaHelper::set_cost($model, $request->articles);
        
        // $this->sendAddModelNotification('PromocionVinoteca', $model->id);

        return response()->json(['model' => $this->fullModel('PromocionVinoteca', $model->id)], 200);
    }

    function delete_stock(Request $request, $id) {

        $model = PromocionVinoteca::find($id);
        $model->stock -= $request->stock_a_eliminar;
        $model->save();

        PromocionVinotecaHelper::regresar_stock($model, $request->articles);
        
        if ($model->stock == 0) {
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
