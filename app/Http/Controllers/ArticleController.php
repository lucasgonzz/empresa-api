<?php

namespace App\Http\Controllers;

use App\Exports\ArticleExport;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Imports\ArticleImport;
use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ArticleController extends Controller
{
    function index($status = 'active') {
        $models = Article::where('user_id', $this->userId())
                            ->where('status', $status)
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->paginate(100);
        return response()->json(['models' => $models], 200);
    }

    function show($id) {
        return response()->json(['model' => $this->fullModel('article', $id)], 200);
    }

    function store(Request $request) {
        $model = new Article();
        $model->num                               = $this->num('articles');
        $model->bar_code                          = $request->bar_code;
        $model->provider_code                     = $request->provider_code;
        $model->provider_id                       = $request->provider_id;
        $model->category_id                       = $request->category_id;
        $model->sub_category_id                   = $request->sub_category_id;
        $model->brand_id                          = $request->brand_id;
        $model->name                              = ucfirst($request->name);
        $model->slug                              = ArticleHelper::slug($request->name);
        $model->cost                              = $request->cost;
        $model->cost_in_dollars                   = $request->cost_in_dollars;
        $model->provider_cost_in_dollars          = $request->provider_cost_in_dollars;
        $model->apply_provider_percentage_gain    = $request->apply_provider_percentage_gain;
        $model->price                             = $request->price;
        $model->percentage_gain                   = $request->percentage_gain;
        $model->provider_price_list_id            = $request->provider_price_list_id;
        $model->iva_id                            = $request->iva_id;
        $model->stock                             = $request->stock;
        $model->stock_min                         = $request->stock_min;
        $model->user_id                           = $this->userId();
        if (isset($request->status)) {
            $model->status = $request->status;
        }
        $model->save();
        // ArticleHelper::setTags($model, $request->tags);
        // ArticleHelper::setDiscounts($model, $request->discounts);
        // ArticleHelper::setDescriptions($model, $request->descriptions);
        // ArticleHelper::setSizes($model, $request->sizes_id);
        // ArticleHelper::setColors($model, $request->colors);
        // ArticleHelper::setCondition($model, $request->condition_id);
        // ArticleHelper::setSpecialPrices($model, $request);
        // ArticleHelper::setDeposits($model, $request);
        // $model->user->notify(new CreatedArticle($model));
        ArticleHelper::attachProvider($model, $request);
        ArticleHelper::setFinalPrice($model);
        $this->sendAddModelNotification('article', $model->id);
        return response()->json(['model' => $this->fullModel('Article', $model->id)], 201);
    }

    function update(Request $request) {
        $model = Article::find($request->id);
        ArticleHelper::saveProvider($model, $request);
        $model->status                            = 'active';
        $model->provider_id                       = $request->provider_id;
        $model->bar_code                          = $request->bar_code;
        $model->provider_code                     = $request->provider_code;
        $model->provider_id                       = $request->provider_id;
        $model->category_id                       = $request->category_id;
        $model->sub_category_id                   = $request->sub_category_id;
        $model->cost                              = $request->cost;
        $model->cost_in_dollars                   = $request->cost_in_dollars;
        $model->provider_cost_in_dollars          = $request->provider_cost_in_dollars;
        $model->brand_id                          = $request->brand_id;
        $model->iva_id                            = $request->iva_id;
        $model->percentage_gain                   = $request->percentage_gain;
        $model->provider_price_list_id            = $request->provider_price_list_id;
        $model->price                             = $request->price;
        $model->apply_provider_percentage_gain    = $request->apply_provider_percentage_gain;
        $model->stock                             = $request->stock;
        $model->stock                             += $request->new_stock;
        $model->stock_min                         = $request->stock_min;
        if (strtolower($model->name) != strtolower($request->name)) {
            $model->name = ucfirst($request->name);
            $model->slug = ArticleHelper::slug($request->name);
        }
        $model->save();
        // ArticleHelper::checkAdvises($model);
        // ArticleHelper::setTags($model, $request->tags);
        // ArticleHelper::setDiscounts($model, $request->discounts);
        // ArticleHelper::setDescriptions($model, $request->descriptions);
        // ArticleHelper::setSizes($model, $request->sizes_id);
        // ArticleHelper::setColors($model, $request->colors);
        // ArticleHelper::setCondition($model, $request->condition_id);
        // ArticleHelper::setDeposits($model, $request);
        ArticleHelper::setFinalPrice($model);
        ArticleHelper::checkAdvises($model);
        $this->sendAddModelNotification('article', $model->id);
        return response()->json(['model' => $this->fullModel('Article', $model->id)], 200);
    }

    function newArticle(Request $request) {
        $model = new Article();
        $model->user_id = $this->userId();
        $model->price = $request->price;
        if ($request->bar_code != '') {
            $model->bar_code = $request->bar_code;
        }
        if ($request->name != '') {
            $model->name = $request->name;
        }
        $model->save();
        ArticleHelper::setFinalPrice($model);
        return response()->json(['model' => $this->fullModel('Article', $model->id)], 201);
    }

    function import(Request $request) {
        $columns = GeneralHelper::getImportColumns($request);
        Excel::import(new ArticleImport($columns, $request->start_row, $request->finish_row, $request->provider_id), $request->file('models'));
        $this->sendUpdateModelsNotification('article');
    }

    function export() {
        return Excel::download(new ArticleExport, 'comerciocity-articulos_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
    }

    function setFeatured($id) {
        $model = Article::find($id);
        if (!is_null($model->featured)) {
            $model->featured = null;
        } else {
            $models_featured = Article::where('user_id', $this->userId())
                                        ->whereNotNull('featured')
                                        ->get();
            $model->featured = count($models_featured) + 1;
        }
        $model->save();
        return response()->json(['model' => $this->fullModel('Article', $model->id)], 200);
    }

    function setOnline($id) {
        $model = Article::find($id);
        if ($model->online) {
            $model->online = 0;
        } else {
            $model->online = 1;
        }
        $model->save();
        return response()->json(['model' => $this->fullModel('Article', $model->id)], 200);
    }

    function destroy($id) {
        $model = Article::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('article', $model->id);
        return response(null);
    }
}
