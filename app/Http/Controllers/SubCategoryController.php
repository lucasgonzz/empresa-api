<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\category\PriceTypeHelper;
use App\Http\Controllers\Helpers\category\SetPriceTypesHelper;
use App\Models\SubCategory;
use App\Services\TiendaNube\TiendaNubeCategoryImageService;
use Illuminate\Http\Request;

class SubCategoryController extends Controller
{

    public function index() {
        $models = SubCategory::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = SubCategory::create([
            'num'                   => $this->num('sub_categories'),
            'name'                  => $request->name,
            'category_id'           => $request->category_id,
            'image_url'             => $request->image_url,
            'show_in_vender'        => $request->show_in_vender,
            'user_id'               => $this->userId(),
        ]);
        
        SetPriceTypesHelper::set_price_types($model);

        GeneralHelper::attachModels($model, 'price_types', $request->price_types, ['percentage']);

        $this->check_tienda_nube_image($model);
        
        return response()->json(['model' => $this->fullModel('SubCategory', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('SubCategory', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = SubCategory::find($id);
        $model->name                = $request->name;
        $model->category_id         = $request->category_id;
        $model->show_in_vender      = $request->show_in_vender;
        $model->image_url           = $request->image_url;
        $model->save();

        GeneralHelper::attachModels($model, 'price_types', $request->price_types, ['percentage']);

        PriceTypeHelper::update_article_prices(null, $model);

        $this->check_tienda_nube_image($model);
        return response()->json(['model' => $this->fullModel('SubCategory', $model->id)], 200);
    }

    public function destroy($id) {
        $model = SubCategory::find($id);
        if (!is_null($model)) {
            $model->delete();
            ImageController::deleteModelImages($model);
            $this->sendDeleteModelNotification('sub_category', $model->id);
        }
        return response(null);
    }

    function check_tienda_nube_image($model) {
        if (
            env('USA_TIENDA_NUBE', false)
            && !is_null($model->image_url)
        ) {
            $tn = new TiendaNubeCategoryImageService();
            $tn->upload_category_image($model);
        }
    }
}
