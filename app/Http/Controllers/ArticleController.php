<?php

namespace App\Http\Controllers;

use App\Exports\ArticleClientsExport;
use App\Exports\ArticleExport;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Pdf\ArticleListPdf;
use App\Http\Controllers\Pdf\ArticlePdf;
use App\Http\Controllers\Pdf\ArticleTicketPdf;
use App\Http\Controllers\StockMovementController;
use App\Imports\ArticleImport;
use App\Imports\LocationImport;
use App\Imports\ProvinciaImport;
use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Session;

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
        $model->costo_mano_de_obra                = $request->costo_mano_de_obra;
        $model->provider_cost_in_dollars          = $request->provider_cost_in_dollars;
        $model->apply_provider_percentage_gain    = $request->apply_provider_percentage_gain;
        $model->price                             = $request->price;
        $model->percentage_gain                   = $request->percentage_gain;
        $model->provider_price_list_id            = $request->provider_price_list_id;
        $model->iva_id                            = $request->iva_id;
        // $model->stock                             = $request->stock;
        $model->stock_min                         = $request->stock_min;
        $model->online                            = $request->online;
        $model->in_offer                          = $request->in_offer;
        $model->default_in_vender                 = $request->default_in_vender;

        $model->user_id                           = $this->userId();
        if (isset($request->status)) {
            $model->status = $request->status;
        }
        $model->save();

        // GeneralHelper::attachModels($model, 'addresses', $request->addresses, ['amount']);
        // ArticleHelper::setArticleStockFromAddresses($model);

        ArticleHelper::setDeposits($model, $request);

        $this->updateRelationsCreated('article', $model->id, $request->childrens);

        ArticleHelper::setFinalPrice($model);
        // ArticleHelper::attachProvider($request, $model);

        ArticleHelper::setStockFromStockMovement($model);

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
        $model->costo_mano_de_obra                = $request->costo_mano_de_obra;
        $model->cost_in_dollars                   = $request->cost_in_dollars;
        $model->provider_cost_in_dollars          = $request->provider_cost_in_dollars;
        $model->brand_id                          = $request->brand_id;
        $model->iva_id                            = $request->iva_id;
        $model->percentage_gain                   = $request->percentage_gain;
        $model->provider_price_list_id            = $request->provider_price_list_id;
        $model->price                             = $request->price;
        $model->apply_provider_percentage_gain    = $request->apply_provider_percentage_gain;
        // $model->stock                             = $request->stock;
        // $model->stock                             += $request->new_stock;
        $model->stock_min                         = $request->stock_min;
        $model->online                            = $request->online;
        $model->in_offer                          = $request->in_offer;
        $model->default_in_vender                 = $request->default_in_vender;
        // if (strtolower($model->name) != strtolower($request->name)) {
            $model->name = ucfirst($request->name);
            $model->slug = ArticleHelper::slug($request->name);
        // }
        $model->save();
        
        // GeneralHelper::attachModels($model, 'addresses', $request->addresses, ['amount']);
        // ArticleHelper::setArticleStockFromAddresses($model);

        ArticleHelper::setFinalPrice($model);
        ArticleHelper::setDeposits($model, $request);
        // ArticleHelper::checkAdvises($model);
        ArticleHelper::attachProvider($request, $model, $actual_provider_id, $actual_stock);

        ArticleHelper::checkRecipesForSetPirces($model, $this);

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
        $columns = ArticleImportHelper::addAddressesColumns($columns);
        // Log::info('colunbs');
        // Log::info($columns);
        Excel::import(new ArticleImport($columns, $request->create_and_edit, $request->start_row, $request->finish_row, $request->provider_id, $request->import_history_id, $request->pre_import_id), $request->file('models'));
        
        $this->sendUpdateModelsNotification('article');

        $import_history_id = Session::get('import_history_id');
        $pre_import_id = Session::get('pre_import_id');

        return response()->json(['import_history_id' => $import_history_id, 'pre_import_id' => $pre_import_id], 201);
    }

    function export() {
        return Excel::download(new ArticleExport, 'comerciocity-articulos_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
    }

    function clientsExport() {
        return Excel::download(new ArticleClientsExport, 'cc-articulos-clientes_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
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

        $recipes_donde_esta_este_articulo = ArticleHelper::get_recipes_que_tienen_este_articulo_como_insumo($model);

        ImageController::deleteModelImages($model);
        $model->delete();
        ArticleHelper::check_recipes_despues_de_eliminar_articulo($recipes_donde_esta_este_articulo, $this);

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

    function pdf($ids) {
        new ArticlePdf($ids);
    }

    function listPdf($ids) {
        new ArticleListPdf($ids);
    }

    function resetStock(Request $request) {
        foreach ($request->articles_id as $article_id) {
            $article = Article::find($article_id);
            if (!is_null($article->stock)) {
                $new_stock = 0 - $article->stock;
            } else {
                $new_stock = 0;
            }

            $stock_movement_ct = new StockMovementController();
            $request = new \Illuminate\Http\Request();
            $request->model_id = $article_id;
            $request->amount = $new_stock;
            $request->concepto = 'Reseteo de stock';
            $stock_movement_ct->store($request);
        }

        return response(null, 200);
    }
}
