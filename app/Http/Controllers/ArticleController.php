<?php

namespace App\Http\Controllers;

use App\Exports\ArticleClientsExport;
use App\Exports\ArticleExport;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeMonedaHelper;
use App\Http\Controllers\Helpers\article\ArticleUbicationsHelper;
use App\Http\Controllers\Helpers\article\ArticleVariantHelper;
use App\Http\Controllers\Helpers\article\BarCodeAutomaticoHelper;
use App\Http\Controllers\Helpers\article\ResetStockHelper;
use App\Http\Controllers\Helpers\article\UpdateAddressesStockHelper;
use App\Http\Controllers\Helpers\article\UpdateVariantsStockHelper;
use App\Http\Controllers\Pdf\ArticleBarCodePdf;
use App\Http\Controllers\Pdf\ArticleListPdf;
use App\Http\Controllers\Pdf\ArticlePdf;
use App\Http\Controllers\Pdf\ArticlePdf\TruvariArticleListPdf;
use App\Http\Controllers\Pdf\ArticleTicketPdf;
use App\Http\Controllers\Pdf\ArticleTicket\ArticleBarCodeEtiquetasPdf;
use App\Http\Controllers\StockMovementController;
use App\Imports\ArticleImport;
use App\Imports\LocationImport;
use App\Imports\ProvinciaImport;
use App\Jobs\ProcessArticleImport;
use App\Jobs\ProcessDeleteArticleFromTiendaNube;
use App\Jobs\ProcessSyncArticleToTiendaNube;
use App\Jobs\SyncProductToMercadoLibre;
use App\Models\Article;
use App\Models\User;
use App\Services\MercadoLibre\ProductService;
use App\Services\Pdf\Catalog\CatalogClassic;
use App\Services\Pdf\Catalog\TCPDCCatalog;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ArticleController extends Controller
{
    function index(Request $request) {
        $models = Article::where('user_id', $this->userId())
                            // ->where('id', 0)
                            ->where('status', 'active');

        $updated_after = $request->input('updated_after');

        if ($updated_after) {

            Log::info('updated_after: '.$updated_after);

            $models = $models->where(function($query) use ($updated_after) {
                                $query->where('updated_at', '>', $updated_after)
                                    ->orWhere('final_price_updated_at', '>', $updated_after);
                            });
        }
        $models = $models->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->paginate(200);

        return response()->json(['models' => $models], 200);
    }

    function index_deleted(Request $request) {

        $updated_after = $request->input('updated_after');

        $models = Article::where('user_id', $this->userId())
                            ->withTrashed()
                            ->whereNotNull('deleted_at');

        if ($updated_after) {

            Log::info('index_deleted updated_after: '.$updated_after);

            $models = $models->where('deleted_at', '>=', $updated_after);
        }
        
        $models = $models->orderBy('created_at', 'DESC')
                            ->get();

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
        // $model->num                               = $this->num('articles');
        $model->bar_code                          = $request->bar_code;
        $model->sku                               = $request->sku;
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
        $model->percentage_gain_blanco                   = $request->percentage_gain_blanco;
        $model->provider_price_list_id            = $request->provider_price_list_id;
        $model->iva_id                            = $request->iva_id;
        $model->aplicar_iva                       = $request->aplicar_iva;

        // $model->stock                             = $request->stock;
        $model->stock_min                         = $request->stock_min;
        $model->online                            = $request->online;
        $model->in_offer                          = $request->in_offer;
        $model->default_in_vender                 = $request->default_in_vender;
        $model->personalizar_price_en_vender                 = $request->personalizar_price_en_vender;


        // Vinoteca
        $model->bodega_id                           = $request->bodega_id;
        $model->cepa_id                             = $request->cepa_id;
        $model->origen                              = $request->origen;
        $model->presentacion                        = $request->presentacion;


        // Autopartes
        $model->espesor                         = $request->espesor;
        $model->modelo                          = $request->modelo;
        $model->pastilla                        = $request->pastilla;
        $model->diametro                        = $request->diametro;
        $model->litros                          = $request->litros;
        $model->descripcion                     = $request->descripcion;
        $model->contenido                       = $request->contenido;
        $model->cm3                             = $request->cm3;
        $model->calipers                        = $request->calipers;
        $model->juego                           = $request->juego;


        $model->unidades_individuales              = $request->unidades_individuales;
        $model->unidad_medida_id                   = $request->unidad_medida_id;
        $model->omitir_en_lista_pdf                = $request->omitir_en_lista_pdf;


        // Tienda nube
        $model->peso                                = $request->peso;
        $model->profundidad                         = $request->profundidad;
        $model->ancho                               = $request->ancho;
        $model->alto                                = $request->alto;
        $model->disponible_tienda_nube              = $request->disponible_tienda_nube;
        $model->precio_promocional                  = $request->precio_promocional;

        $model->seo_title                           = $request->seo_title;
        $model->seo_description                     = $request->seo_description;
        $model->requires_shipping                   = $request->requires_shipping;
        $model->free_shipping                       = $request->free_shipping;
        $model->video_url                           = $request->video_url;

        $model->needs_sync_with_tn                  = 1;


        // Mercado Libre
        $model->mercado_libre                       = $request->mercado_libre;
        $model->meli_listing_type_id                = $request->meli_listing_type_id;
        $model->meli_buying_mode_id                 = $request->meli_buying_mode_id;
        $model->meli_item_condition_id              = $request->meli_item_condition_id;
        $model->meli_descripcion                    = $request->meli_descripcion;

        $model->plu                                 = $request->plu;

        $model->user_id                           = $this->userId();
        if (isset($request->status)) {
            $model->status = $request->status;
        }
        $model->save();

        BarCodeAutomaticoHelper::set_bar_code($model);

        $model->addresses()->sync([]);
        
        ArticlePriceTypeMonedaHelper::attach_price_type_monedas($model, $request->price_type_monedas);

        ArticlePriceTypeHelper::attach_price_types($model, $request->price_types);

        // GeneralHelper::attachModels($model, 'addresses', $request->addresses, ['amount']);
        // ArticleHelper::setArticleStockFromAddresses($model);

        ArticleHelper::setDeposits($model, $request);

        $this->updateRelationsCreated('article', $model->id, $request->childrens);

        GeneralHelper::attachModels($model, 'tags', $request->tags);

        $model = ArticleHelper::setFinalPrice($model);

        // Relacionar proveedor y codigo de proveedor
        $this->attach_provider($model);
        // ArticleHelper::attachProvider($request, $model);

        // ArticleHelper::setStockFromStockMovement($model);

        // $this->sendAddModelNotification('article', $model->id);

        ArticleVariantHelper::set_default_properties($model);

        ArticleUbicationsHelper::init_ubications($model);

        ProductService::add_article_to_sync($model);
        TiendaNubeSyncArticleService::add_article_to_sync($model);



        $inventory_linkage_helper = new InventoryLinkageHelper();
        $inventory_linkage_helper->checkArticle($model);

        Log::info('se guardo article con id: '.$model->id);

        return response()->json(['model' => $this->fullModel('Article', $model->id)], 201);
    }

    function attach_provider($article) {
        if (
            $article->provider_id
        ) {

            $exist = $article->providers()->where('provider_id', $article->provider_id)->first();

            if ($exist) {
                
                $article->providers()->updateExistingPivot($article->provider_id, [
                    'cost'                      => $article->cost,
                    'price'                     => $article->final_price,
                    'provider_code'             => $article->provider_code,
                ]);

            } else {

                $article->providers()->attach($article->provider_id, [
                    'cost'                      => $article->cost,
                    'price'                     => $article->final_price,
                    'provider_code'             => $article->provider_code,
                ]);

            }


        }
    }

    function update(Request $request) {
        // Log::info('Se esta usando la bbdd = '.config('database.connections.mysql.database'));
        $model = Article::find($request->id);

        $actual_stock = $model->stock;
        $actual_provider_id = $model->provider_id;
        
        $model->status                            = 'active';
        $model->provider_id                       = $request->provider_id;
        $model->featured                          = $request->featured;
        $model->bar_code                          = $request->bar_code;
        $model->sku                               = $request->sku;
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
        $model->aplicar_iva                       = $request->aplicar_iva;
        $model->percentage_gain                   = $request->percentage_gain;
        $model->percentage_gain_blanco                   = $request->percentage_gain_blanco;
        $model->provider_price_list_id            = $request->provider_price_list_id;
        $model->price                             = $request->price;
        $model->apply_provider_percentage_gain    = $request->apply_provider_percentage_gain;
        // $model->stock                             = $request->stock;
        // $model->stock                             += $request->new_stock;
        $model->stock_min                         = $request->stock_min;
        $model->online                            = $request->online;
        $model->in_offer                          = $request->in_offer;
        $model->default_in_vender                 = $request->default_in_vender;
        $model->personalizar_price_en_vender                 = $request->personalizar_price_en_vender;
        


        // Vinoteca
        $model->bodega_id                           = $request->bodega_id;
        $model->cepa_id                             = $request->cepa_id;
        $model->origen                              = $request->origen;
        $model->presentacion                        = $request->presentacion;


        $model->unidades_individuales               = $request->unidades_individuales;
        $model->unidad_medida_id                    = $request->unidad_medida_id;
        $model->omitir_en_lista_pdf                 = $request->omitir_en_lista_pdf;


        $model->mercado_libre                       = $request->mercado_libre;
        $model->meli_listing_type_id                = $request->meli_listing_type_id;
        $model->meli_buying_mode_id                 = $request->meli_buying_mode_id;
        $model->meli_item_condition_id              = $request->meli_item_condition_id;
        $model->meli_descripcion                    = $request->meli_descripcion;




        // Autopartes
        
        $model->espesor                         = $request->espesor;
        $model->modelo                          = $request->modelo;
        $model->pastilla                        = $request->pastilla;
        $model->diametro                        = $request->diametro;
        $model->litros                          = $request->litros;
        $model->descripcion                     = $request->descripcion;
        $model->contenido                       = $request->contenido;
        $model->cm3                             = $request->cm3;
        $model->calipers                        = $request->calipers;
        $model->juego                           = $request->juego;




        // Tienda nube
        $model->peso                                = $request->peso;
        $model->profundidad                         = $request->profundidad;
        $model->ancho                               = $request->ancho;
        $model->alto                                = $request->alto;
        $model->disponible_tienda_nube              = $request->disponible_tienda_nube;
        $model->precio_promocional                  = $request->precio_promocional;

        $model->seo_title                           = $request->seo_title;
        $model->seo_description                     = $request->seo_description;
        $model->requires_shipping                   = $request->requires_shipping;
        $model->free_shipping                       = $request->free_shipping;
        $model->video_url                           = $request->video_url;
        
        $model->needs_sync_with_tn                  = 1;


        $model->plu                                 = $request->plu;

        
        $model->name = ucfirst($request->name);
        $model->slug = ArticleHelper::slug($request->name);
        $model->save();
        
        // GeneralHelper::attachModels($model, 'addresses', $request->addresses, ['amount']);
        // ArticleHelper::setArticleStockFromAddresses($model);

        ArticlePriceTypeMonedaHelper::attach_price_type_monedas($model, $request->price_type_monedas);
        
        ArticlePriceTypeHelper::attach_price_types($model, $request->price_types);

        $model = ArticleHelper::setFinalPrice($model);

        $this->attach_provider($model);

        ArticleHelper::setDeposits($model, $request);
        // ArticleHelper::checkAdvises($model);

        ArticleHelper::checkRecipesForSetPirces($model, $this);
        
        GeneralHelper::attachModels($model, 'tags', $request->tags);

        // $this->sendAddModelNotification('article', $model->id);

        ProductService::add_article_to_sync($model);
        TiendaNubeSyncArticleService::add_article_to_sync($model);

        
        $inventory_linkage_helper = new InventoryLinkageHelper();
        $inventory_linkage_helper->checkArticle($model);
        
        return response()->json(['model' => $this->fullModel('Article', $model->id)], 200);
    }

    // function check_tienda_nube($article) {

    //     if (env('USA_TIENDA_NUBE', false)) {
    //         dispatch(new ProcessSyncArticleToTiendaNube($article));
    //     }
    // }

    function check_delete_tienda_nube($article) {

        if (
            env('USA_TIENDA_NUBE', false)
            && $article->tiendanube_product_id
        ) {
            dispatch(new ProcessDeleteArticleFromTiendaNube($article));
        }
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

        Log::info('columns:');
        Log::info($columns);
        /*
            Agrego columnas de:
                1. Direcciones
                2. Listas de precios
                3. Precios en BLANCO
        */
        // $columns = ArticleImportHelper::add_columns($columns);

        if ($request->has('models') && $request->file('models')->isValid()) {

            Log::info('se va a guardar archivo');
            Log::info($request->file('models'));

            $original_extension = 'xlsx';
            // $original_extension = $request->file('models')->getClientOriginalExtension();
            
            $filename = 'import_' . time() . '.' . $original_extension;
            $archivo_excel_path = $request->file('models')->storeAs('imported_files', $filename);

            Log::info($archivo_excel_path);

        } else if ($request->has('archivo_excel_path')) {

            Log::info('ya viene la ruta del archivo');
            $archivo_excel_path = $request->archivo_excel_path;

        } else {
            Log::info('NO se va a guardar archivo');
            Log::info($request->file('models')->getError());
        }

        Log::info('archivo_excel_path: '.$archivo_excel_path);
        $archivo_excel = storage_path('app/' . $archivo_excel_path);

        $import_uuid = (string) Str::uuid();    

        $owner = User::find($this->userId());
        
        ProcessArticleImport::dispatch($import_uuid, $archivo_excel, $columns, $request->create_and_edit, $request->no_actualizar_articulos_de_otro_proveedor, $request->start_row, $request->finish_row, $request->provider_id, $owner, Auth()->user()->id, $archivo_excel_path);

        return response(null, 200);
    }

    function export(Request $request) {
        $models = null;
        if ($request->has('filters')) {
            $jsonData = $request->query('filters');
            $filters = json_decode($jsonData, true);

            $search_ct = new SearchController();
            $models = $search_ct->search($request, 'article', $filters);
        } else if ($request->has('articles_id')) {

            $ids = explode('-', $request->query('articles_id'));
            $models = Article::find($ids);
        }
        
        return Excel::download(new ArticleExport($models), 'comerciocity-articulos_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
    }

    function clientsExport($price_type_id = null) {
        Log::info('controller: '.$price_type_id);
        return Excel::download(new ArticleClientsExport($price_type_id), 'cc-articulos-clientes_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
    }

    function baseExport() {
        return Excel::download(new ArticleExport(null, true), 'base-comerciocity-articulos.xlsx');
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

    function destroy($id, $send_notification = true) {
        $model = Article::find($id);
        
        Log::info(Auth()->user()->name.' va a eliminar ARTICLE '.$model->name.' desde controller, id: '.$model->id);

        $recipes_donde_esta_este_articulo = ArticleHelper::get_recipes_que_tienen_este_articulo_como_insumo($model);

        ArticleHelper::check_article_recipe_to_delete($model);

        $this->check_delete_tienda_nube($model);
        
        // ImageController::deleteModelImages($model);
        $model->delete();
        ArticleHelper::check_recipes_despues_de_eliminar_articulo($recipes_donde_esta_este_articulo, $this);

        if ($send_notification) {
            $this->sendDeleteModelNotification('article', $model->id);
        }

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

    function barCodePdf($ids) {
        new ArticleBarCodePdf($ids);
    }

    function barCodeEtiquetasPdf($ids) {
        // $user = $this->user();
        // if ($user->)
        new ArticleBarCodeEtiquetasPdf($ids);
    }

    function ticketsPdf($ids) {
        new ArticleTicketPdf($ids);
    }

    function pdf($ids, $moneda_id = null) {
        $user = $this->user();     

        if ($moneda_id == 'undefined') {
            $moneda_id = null;
        }

        if (env('APP_ENV') == 'production') {
            $image = $user->image_url;
        } else {
            $image = 'https://api.freelogodesign.org/assets/thumb/logo/ad95beb06c4e4958a08bf8ca8a278bad_400.png';
        }

        $pdf = new TCPDCCatalog();
        $pdf->generate(
            $image,
            $user->company_name,
            [
                'Telefono' => $user->phone,
                'Email' => $user->email,
                'Email' => $user->email,
            ],
            $ids,
            $moneda_id,
        );
    }

    function listPdf($ids) {
        new ArticleListPdf($ids);
    }

    function pdfPersonalizado() {
        if ($this->user()->article_pdf_personalizado) {
            if ($this->user()->article_pdf_personalizado == 'truvari') {
                new TruvariArticleListPdf($this->user());
            }
        }
    }

    function resetStock(Request $request) {
        foreach ($request->articles_id as $article_id) {

            $helper = new ResetStockHelper();
            $helper->reset_stock($article_id);

        }

        return response(null, 200);
    }

    function update_addresses_stock(Request $request) {

        $helper = new UpdateAddressesStockHelper($request->article_id, $request->addresses);
        $helper->update_addresses();
        $helper->set_stock_min_max();

        return response()->json(['model' => $this->fullModel('Article', $request->article_id)], 200);

    }

    function update_variants_stock(Request $request) {

        $helper = new UpdateVariantsStockHelper($request->article_id, $request->variants_to_update);
        $helper->update_variants();

        return response()->json(['model' => $this->fullModel('Article', $request->article_id)], 200);

    }

    function articles_por_defecto() {
        $models = Article::where('user_id', $this->userId())
                            ->where('status', 'active')
                            ->where('default_in_vender', 1)
                            ->orderBy('created_at', 'ASC')
                            ->withAll()
                            ->get();

        return response()->json(['models' => $models], 200);
    }

    function ultimos_actualizados() {

        $articulos_por_defecto = Article::where('user_id', $this->userId())
                                        ->orderBy('id', 'DESC')
                                        ->where('default_in_vender', 1)
                                        ->withAll()
                                        ->get();
                                        
        $models = Article::where('user_id', $this->userId())
                            ->orderBy('id', 'DESC')
                            // ->orderBy('updated_at', 'DESC')
                            ->take(30)
                            ->withAll()
                            ->get();


        $results = $articulos_por_defecto->merge($models->reverse());
        // $results = $articulos_por_defecto->merge(array_reverse($models));

        // Invertimos el orden usando Collection::reverse() y reindexamos con values()
        $models_invertidos = $models->reverse()->values();

        return response()->json(['models' => $results], 200);
    }
}
