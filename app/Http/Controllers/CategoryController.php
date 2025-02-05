<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\category\PriceTypeHelper;
use App\Http\Controllers\Helpers\category\SetPriceTypesHelper;
use App\Models\Article;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Http\Request;

class CategoryController extends Controller
{

    public function index() {
        $models = Category::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Category::create([
            'num'                   => $this->num('categories'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);

        GeneralHelper::attachModels($model, 'price_types', $request->price_types, ['percentage']);
        
        SetPriceTypesHelper::set_price_types($model);

        SetPriceTypesHelper::set_rangos($model);


        $this->sendAddModelNotification('Category', $model->id);
        return response()->json(['model' => $this->fullModel('Category', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Category', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Category::find($id);
        $model->name                = $request->name;
        $model->save();
        GeneralHelper::attachModels($model, 'price_types', $request->price_types, ['percentage']);

        PriceTypeHelper::update_article_prices($model);
        
        $this->sendAddModelNotification('Category', $model->id);
        return response()->json(['model' => $this->fullModel('Category', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Category::find($id);
        $this->detachArticlesCategory($model);
        $this->deleteSubCategories($model);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Category', $model->id);
        $this->sendUpdateModelsNotification('sub_category', false);
        return response(null);
    }

    public function detachArticlesCategory($category) {
        Article::where('user_id', $this->userId())
                ->where('category_id', $category->id)
                ->update([
                    'category_id'       => 0,
                    'sub_category_id'   => 0,
                ]);
    }

    public function deleteSubCategories($category) {
        SubCategory::where('category_id', $category->id)->delete();
    }
}
