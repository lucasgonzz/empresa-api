<?php

namespace App\Http\Controllers;

use App\Exports\ArticleExport;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Pdf\ArticleTicketPdf;
use App\Imports\ArticleImport;
use App\Imports\LocationImport;
use App\Imports\ProvinciaImport;
use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ArticleController extends Controller
{
    function index($last_updated, $status = 'active') {
        $models = Article::where('user_id', $this->userId())
                            ->where('status', $status)
                            ->where(function($query) use ($last_updated) {
                                $query->where('updated_at', '>', $last_updated);
                                $query->orWhere('final_price_updated_at', '>', $last_updated);
                            })
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->paginate(200);
        return response()->json(['models' => $models], 200);
    }

    function deletedModels($last_updated) {
        $models = Article::where('user_id', $this->userId())
                            // ->whereNotNull('deleted_at')
                            ->withTrashed()
                            ->where('deleted_at', '>', $last_updated)
                            ->orderBy('created_at', 'DESC')
                            // ->withAll()
                            ->get();
        // return response()->json(['models' => []], 200);
        return response()->json(['models' => $models], 200);
    }

    function show($id) {
        return response()->json(['model' => $this->fullModel('article', $id)], 200);
    }

    function guardar($datos) {
        $articulo = new Article();
        $articulo->nombre = $datos->nombre;
        $articulo->codigo_de_barras = $datos->codigo_de_barras;
        $articulo->precio = $datos->precio;
        $articulo->guardar();
        $mensaje = "se guardo bien";
        return $mensaje;
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
        $model->online                            = $request->online;
        $model->user_id                           = $this->userId();
        if (isset($request->status)) {
            $model->status = $request->status;
        }
        $model->save();

        GeneralHelper::attachModels($model, 'addresses', $request->addresses, ['amount']);
        ArticleHelper::setArticleStockFromAddresses($model);

        ArticleHelper::setDeposits($model, $request);

        $this->updateRelationsCreated('article', $model->id, $request->childrens);

        ArticleHelper::setFinalPrice($model);
        ArticleHelper::attachProvider($request, $model);

        $this->sendAddModelNotification('article', $model->id);

        $inventory_linkage_helper = new InventoryLinkageHelper();
        $inventory_linkage_helper->checkArticle($model);

        return response()->json(['model' => $this->fullModel('Article', $model->id)], 201);
    }

    function update(Request $request) {
        $model = Article::find($request->id);

        $actual_stock = $model->stock;
        $actual_provider_id = $model->provider_id;
        
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
        // $model->stock                             += $request->new_stock;
        $model->stock_min                         = $request->stock_min;
        $model->online                            = $request->online;
        // if (strtolower($model->name) != strtolower($request->name)) {
            $model->name = ucfirst($request->name);
            $model->slug = ArticleHelper::slug($request->name);
        // }
        $model->save();
        
        GeneralHelper::attachModels($model, 'addresses', $request->addresses, ['amount']);
        ArticleHelper::setArticleStockFromAddresses($model);

        ArticleHelper::setFinalPrice($model);
        ArticleHelper::setDeposits($model, $request);
        ArticleHelper::checkAdvises($model);
        ArticleHelper::attachProvider($request, $model, $actual_provider_id, $actual_stock);
        $this->sendAddModelNotification('article', $model->id);

        $inventory_linkage_helper = new InventoryLinkageHelper();
        $inventory_linkage_helper->checkArticle($model);
        
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
        // Log::info('colunbs');
        // Log::info($columns);
        // Excel::import(new ProvinciaImport(), $request->file('models'));
        // Excel::import(new LocationImport(), $request->file('models'));
        Excel::import(new ArticleImport($columns, $request->create_and_edit, $request->start_row, $request->finish_row, $request->provider_id), $request->file('models'));
        $this->sendUpdateModelsNotification('article');
    }

    function export() {
        return Excel::download(new ArticleExport, 'comerciocity-articulos_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
    }

    function providersHistory($article_id) {
        $model = Article::where('id', $article_id)
                        ->with('providers')
                        ->first();
        return response()->json(['model' => $model], 200);
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

    function charts($id, $from_date, $until_date) {
        $result = ArticleHelper::getChartsFromArticle($id, $from_date, $until_date);
        return response()->json(['result' => $result], 200);
    }

    function sales($id, $from_date, $until_date) {
        $result = ArticleHelper::getSalesFromArticle($id, $from_date, $until_date);
        return response()->json(['result' => $result], 200);
    }

    function ticketsPdf($ids) {
        new ArticleTicketPdf($ids);
    }
}
