<?php

namespace App\Http\Controllers;

use App\Exports\ArticleClientsExport;
use App\Exports\ArticleExport;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\CriterioDePrecioHelper;
use App\Http\Controllers\Helpers\DesglosePrecioHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeMonedaHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Http\Controllers\Helpers\article\ArticleUbicationsHelper;
use App\Http\Controllers\Helpers\article\ArticleVariantHelper;
use App\Http\Controllers\Helpers\article\BarCodeAutomaticoHelper;
use App\Http\Controllers\Helpers\article\ResetStockHelper;
use App\Http\Controllers\Helpers\article\UpdateAddressesStockHelper;
use App\Http\Controllers\Helpers\article\UpdateVariantsStockHelper;
use App\Http\Controllers\Helpers\import\article\InitExcelImport;
use App\Http\Controllers\Pdf\ArticleBarCodePdf;
use App\Http\Controllers\Pdf\ArticleListPdf;
use App\Http\Controllers\Pdf\ArticleOfferSheetPdf;
use App\Http\Controllers\Pdf\ArticleTablePdf;
use App\Http\Controllers\Pdf\ArticlePdf\TruvariArticleListPdf;
use App\Http\Controllers\Pdf\ArticleTicketPdf;
use App\Http\Controllers\Pdf\ArticleTicket\ArticleBarCodeEtiquetasPdf;
use App\Imports\ArticleImport;
use App\Imports\LocationImport;
use App\Imports\ProvinciaImport;
use App\Jobs\ProcessArticleImport;
use App\Http\Controllers\Helpers\ExportHistoryHelper;
use App\Jobs\ProcessArticleExportJob;
use App\Jobs\ProcessDeleteArticleFromTiendaNube;
use App\Models\ArticlePdf as ArticlePdfLayout;
use App\Jobs\ProcessSyncArticleToTiendaNube;
use App\Jobs\SyncProductToMercadoLibre;
use App\Models\Article;
use App\Models\PdfColumnProfile;
use App\Services\DemoEventoEmitter;
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

    // Se usa para descargar los articulos para modo offline
    function index(Request $request) {
        // Techo en 1000 (no 2000 como Provider/Client): cada fila trae ~20 relaciones via
        // withAll(), la respuesta más pesada del sistema.
        $per_page = (int) $request->input('per_page', 500);
        if ($per_page <= 0) {
            $per_page = 500;
        }
        if ($per_page > 1000) {
            $per_page = 1000;
        }

        $models = Article::where('user_id', $this->userId())
                            // ->where('id', 0)
                            ->where('status', 'active')
                            ->where(function($q) {
                                $q->where('es_insumo', 0)
                                    ->orWhereNull('es_insumo');
                            });

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
                            ->paginate($per_page);

        return response()->json(['models' => $models], 200);
    }

    /**
     * Devuelve únicamente artículos marcados como insumos.
     *
     * Se usa en Producción V2 para seleccionar insumos sin mezclar con el listado general.
     *
     * @param  \Illuminate\Http\Request  $request Request HTTP (opcional: updated_after).
     * @return \Illuminate\Http\JsonResponse
     */
    function get_insumos(Request $request) {
        // Techo en 1000: mismo criterio que ArticleController@index (withAll() por fila).
        $per_page = (int) $request->input('per_page', 500);
        if ($per_page <= 0) {
            $per_page = 500;
        }
        if ($per_page > 1000) {
            $per_page = 1000;
        }

        // Query base: insumos activos del usuario autenticado.
        $models = Article::where('user_id', $this->userId())
                            ->where('status', 'active')
                            ->where('es_insumo', 1)
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->paginate($per_page);

        return response()->json(['models' => $models], 200);
    }

    function index_deleted(Request $request) {

        /**
         * Define cantidad de registros por página para sincronización offline.
         * Se limita para evitar requests demasiado pesadas en catálogos grandes.
         */
        $per_page = (int) $request->input('per_page', 500);
        if ($per_page <= 0) {
            $per_page = 500;
        }
        if ($per_page > 2000) {
            $per_page = 2000;
        }

        $updated_after = $request->input('updated_after');

        $models = Article::where('user_id', $this->userId())
                            ->withTrashed()
                            ->whereNotNull('deleted_at');

        if ($updated_after) {

            Log::info('index_deleted updated_after: '.$updated_after);

            $models = $models->where('deleted_at', '>=', $updated_after);
        }
        
        $models = $models->orderBy('deleted_at', 'DESC')
                            ->paginate($per_page);

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

    /**
     * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026) — Resuelve `articles.cost` a partir de
     * lo que tipeó el usuario en el ABM del listado.
     *
     * El formulario tiene DOS inputs: uno para el costo NETO (sin IVA) y otro para el BRUTO (con
     * IVA). El request trae el número tipeado en `cost` y, en `cost_incluye_iva`, en cuál de los dos
     * lo tipeó. Si fue en el de bruto, acá se le saca el IVA.
     *
     * 🔴 **Siempre se guarda el neto.** Es la convención del sistema y no se negocia: hay 176
     * lecturas de `->cost` en 62 archivos que la asumen, y `articles` es tabla compartida con
     * tienda. Lo que cambia con esta misión es que `cost` pasa a ser DERIVADO — lo calcula el
     * sistema — en vez de ser lo que alguien tipeó.
     *
     * Hasta esta misión el ABM hacía `$model->cost = $request->cost` literal, o sea daba por hecho
     * que el número ya venía neto, mientras la compra a proveedor sí le sacaba el IVA. El mismo
     * artículo costeaba distinto según por dónde entrara: un Monotributista que cargaba 1000 desde
     * el listado terminaba costeando 1210, porque aplicar_descuentos_e_iva() le suma el IVA encima
     * de un número que ya lo tenía adentro.
     *
     * 🔴 `cost_incluye_iva` viaja SIEMPRE, aunque sea en `false`, y acá no hay ningún fallback por
     * condición fiscal ni por configuración de la cuenta. Si la clave no llegara y esto asumiera
     * algo, un guardado que ni toca el costo —corregir el nombre, cambiar la categoría— terminaría
     * descomponiendo un número que YA era neto: 1000 → 826,45 → 683,01, un 21% por guardado. Lo
     * midió el checker de la Fase 5 sobre una versión anterior de esta misma misión.
     *
     * 🔴 Se llama DESPUES de asignar `iva_id`, nunca antes. Si el usuario cambió la alícuota y el
     * costo en el mismo submit, el back-out tiene que usar la alícuota NUEVA: por eso
     * back_out_iva() fuerza `load('iva')` en vez de `loadMissing()`, y por eso acá el orden importa.
     *
     * @param  \App\Models\Article $model
     * @param  Request             $request
     * @return \App\Models\Article
     */
    private function set_costo_desde_request($model, Request $request)
    {
        $cost = $request->cost;

        // Costo vacío: no hay nada que descomponer.
        if (is_null($cost) || $cost === '') {
            $model->cost = $cost;
            return $model;
        }

        /*
         * Para un Responsable Inscripto manda el formulario: declara en cuál de los dos inputs se
         * tipeó. Para un Monotributista migrado no se descompone nunca y el resolvedor ignora lo
         * declarado — ver el bloque en ArticlePricesHelper::el_costo_cargado_es_bruto().
         *
         * 🔴 Que para el MT la respuesta sea SIEMPRE "no descomponer" es lo que hace seguro usar el
         * resolvedor acá. Con la regla anterior (MT ⇒ siempre bruto) esto no se podía: el formulario
         * manda el modelo entero en cada guardado, así que corregirle el nombre a un artículo llega
         * con el `cost` que devolvió el servidor, y forzar "bruto" le sacaba el IVA a un número que
         * ya no lo tenía — 1000 → 826,45 → 683,01, un 21% por guardado, medido por el checker de la
         * Fase 5 de la misión anterior. "No descomponer" no tiene ese riesgo: es idempotente.
         */
        $lo_tipeado_es_bruto = ArticlePricesHelper::el_costo_cargado_es_bruto(
            UserHelper::user(),
            $request->cost_incluye_iva
        );

        if (!$lo_tipeado_es_bruto) {
            $model->cost = $cost;
            return $model;
        }

        $model->cost = ArticlePricesHelper::back_out_iva($model, $cost);

        return $model;
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
        // `cost` NO se asigna acá: lo resuelve set_costo_desde_request() más abajo, después de
        // `iva_id` y `aplicar_iva`, porque el back-out del IVA necesita la alícuota ya seteada.
        $model->cost_in_dollars                   = $request->cost_in_dollars;
        $model->costo_mano_de_obra                = $request->costo_mano_de_obra;
        $model->provider_cost_in_dollars          = $request->provider_cost_in_dollars;
        $model->apply_provider_percentage_gain    = $request->apply_provider_percentage_gain;
        /*
         * Mision 44: un margen o un precio manual que llegan vacios o en cero se guardan
         * como null. Un percentage_gain = 0 conviviendo con un price cargado es el estado
         * que dejaba los DOS inputs de la ficha bloqueados y sin salida; normalizarlo aca
         * es lo que impide que la interfaz lo vuelva a generar. No cambia ningun precio:
         * sumar 0% y no sumar nada dan el mismo numero.
         */
        $model->price                             = CriterioDePrecioHelper::normalizar($request->price);
        $model->percentage_gain                   = CriterioDePrecioHelper::normalizar($request->percentage_gain);
        $model->percentage_gain_blanco                   = $request->percentage_gain_blanco;
        $model->provider_price_list_id            = $request->provider_price_list_id;
        $model->iva_id                            = $request->iva_id;
        $model->aplicar_iva                       = $request->aplicar_iva;

        // Con `iva_id` y `aplicar_iva` ya asignados, recién ahora se puede descomponer el costo.
        $model = $this->set_costo_desde_request($model, $request);

        // $model->stock                             = $request->stock;
        $model->stock_min                         = $request->stock_min;
        $model->online                            = $request->online;
        $model->in_offer                          = $request->in_offer;
        $model->precio_pausado                    = $request->precio_pausado;
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
        $model->medida                             = $request->medida;
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
        $model->es_insumo                           = $request->es_insumo;

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

        /**
         * Dinamica anterior al merge de refractor, detras de la preferencia del comercio
         * `users.aplicar_descuentos_proveedor_al_asignar` (APAGADA por defecto): crear un articulo
         * con proveedor le materializa los descuentos de ese proveedor.
         *
         * Va ANTES de setFinalPrice porque es justamente el costo_real lo que tiene que salir con
         * los descuentos ya aplicados. Con la preferencia apagada cuesta un solo acceso a columna y
         * el articulo se guarda exactamente igual que antes.
         *
         * Sin proveedor anterior: el articulo se acaba de crear.
         */
        ArticleProviderDiscountHelper::aplicar_al_asignar_proveedor($model, null);

        $model = ArticleHelper::setFinalPrice($model);

        // Relacionar proveedor y codigo de proveedor
        $this->attach_provider($model);
        // ArticleHelper::attachProvider($request, $model);

        // ArticleHelper::setStockFromStockMovement($model);

        // $this->sendAddModelNotification('article', $model->id);

        ArticleVariantHelper::set_default_properties($model);

        ArticleUbicationsHelper::init_ubications($model);

        // ProductService::add_article_to_sync($model);
        // TiendaNubeSyncArticleService::add_article_to_sync($model);



        $inventory_linkage_helper = new InventoryLinkageHelper();
        $inventory_linkage_helper->checkArticle($model);

        Log::info('se guardo article con id: '.$model->id);

        /**
         * Evento de la demo (mision 50). Va del lado del servidor y no del SPA porque desde
         * el front seria falsificable y, peor, se perderia cuando el lead cree el articulo
         * por un camino distinto al guiado — que es justo lo que la demo quiere permitir
         * (decision T4 de demo_experiencia.md §9).
         *
         * 🔴 En una instancia de cliente real esto es un no-op de costo cero: la primera
         * guarda de emitir() mira el marcador de sesion de demo y sale sin tocar la base.
         * Este endpoint es de los mas calientes del sistema y no puede pagar una query mas.
         *
         * Va despues del save() y de todo lo que cuelga de el, con el articulo ya escrito:
         * un evento emitido antes de que la escritura este confirmada le miente al roadmap.
         */
        DemoEventoEmitter::emitir('articulo.creado', null, ['id' => $model->id]);

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
        // `cost`: ver la nota en store(). Se resuelve después de `iva_id` / `aplicar_iva`.
        $model->costo_mano_de_obra                = $request->costo_mano_de_obra;
        $model->cost_in_dollars                   = $request->cost_in_dollars;
        $model->provider_cost_in_dollars          = $request->provider_cost_in_dollars;
        $model->brand_id                          = $request->brand_id;
        $model->iva_id                            = $request->iva_id;
        $model->aplicar_iva                       = $request->aplicar_iva;

        // Con `iva_id` y `aplicar_iva` ya asignados, recién ahora se puede descomponer el costo.
        $model = $this->set_costo_desde_request($model, $request);

        /* Mision 44: mismo criterio que en store(), ver el comentario de alla. */
        $model->percentage_gain                   = CriterioDePrecioHelper::normalizar($request->percentage_gain);
        $model->percentage_gain_blanco                   = $request->percentage_gain_blanco;
        $model->provider_price_list_id            = $request->provider_price_list_id;
        $model->price                             = CriterioDePrecioHelper::normalizar($request->price);
        $model->apply_provider_percentage_gain    = $request->apply_provider_percentage_gain;
        // $model->stock                             = $request->stock;
        // $model->stock                             += $request->new_stock;
        $model->stock_min                         = $request->stock_min;
        $model->online                            = $request->online;
        $model->in_offer                          = $request->in_offer;
        $model->precio_pausado                    = $request->precio_pausado;
        $model->default_in_vender                 = $request->default_in_vender;
        $model->personalizar_price_en_vender                 = $request->personalizar_price_en_vender;
        


        // Vinoteca
        $model->bodega_id                           = $request->bodega_id;
        $model->cepa_id                             = $request->cepa_id;
        $model->origen                              = $request->origen;
        $model->presentacion                        = $request->presentacion;


        $model->unidades_individuales               = $request->unidades_individuales;
        $model->unidad_medida_id                    = $request->unidad_medida_id;
        $model->medida                              = $request->medida;
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
        $model->es_insumo                           = $request->es_insumo;

        
        $model->name = ucfirst($request->name);
        $model->slug = ArticleHelper::slug($request->name);
        $model->save();
        
        // GeneralHelper::attachModels($model, 'addresses', $request->addresses, ['amount']);
        // ArticleHelper::setArticleStockFromAddresses($model);

        ArticlePriceTypeMonedaHelper::attach_price_type_monedas($model, $request->price_type_monedas);
        
        ArticlePriceTypeHelper::attach_price_types($model, $request->price_types);

        /**
         * Mismo criterio que en store(), con el proveedor que el articulo tenia ANTES de este
         * guardado (`$actual_provider_id`, capturado arriba de todo, antes de pisar la propiedad).
         *
         * En la practica cubre el hueco de "articulo sin proveedor al que se le pone uno": el
         * cambio A -> B desde el listado no llega hasta aca, lo intercepta el modal de confirmacion
         * (ChangeProvider.vue) y pega contra `change_provider`, que tiene sus propios flags y NO
         * depende de esta preferencia. El helper se llama igual para cualquier cambio real de
         * proveedor —incluido A -> B por si alguna vez llega por esta via— y sale solo cuando el
         * proveedor no cambio.
         */
        ArticleProviderDiscountHelper::aplicar_al_asignar_proveedor($model, $actual_provider_id);

        $model = ArticleHelper::setFinalPrice($model);

        $this->attach_provider($model);

        ArticleHelper::setDeposits($model, $request);
        // ArticleHelper::checkAdvises($model);

        ArticleHelper::checkRecipesForSetPirces($model, $this);
        
        GeneralHelper::attachModels($model, 'tags', $request->tags);

        // $this->sendAddModelNotification('article', $model->id);

        // ProductService::add_article_to_sync($model);
        // TiendaNubeSyncArticleService::add_article_to_sync($model);

        
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

        /**
         * El alta rápida desde vender también es un artículo creado (misión 50). Va acá y no
         * sólo en store() porque este ES el "camino distinto al guiado" que la decisión T4 de
         * demo_experiencia.md §9 dice que no se puede perder: el lead que arma una venta y
         * carga el artículo desde ahí hizo la acción igual, y el roadmap tiene que enterarse.
         */
        DemoEventoEmitter::emitir('articulo.creado', null, ['id' => $model->id]);

        return response()->json(['model' => $this->fullModel('Article', $model->id)], 201);
    }

    function import(Request $request) {
        $columns = GeneralHelper::getImportColumns($request);

        // Prompt 310: flags "permitir_valores_en_blanco" por columna mapeada (default false).
        $blank_flags = GeneralHelper::getImportBlankFlags($request);

        // Log::info('columns:');
        // Log::info($columns);
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
            return response()->json([
                'hubo_un_error' => true, 
                'message' => $request->file('models')->getError()
            ]);
        }

        Log::info('archivo_excel_path: '.$archivo_excel_path);
        $archivo_excel = storage_path('app/' . $archivo_excel_path);

        $import_uuid = (string) Str::uuid();    

        $owner = User::find($this->userId());
        
        $excel = new InitExcelImport();
        $result = $excel->importar([
            'import_uuid'           => $import_uuid, 
            'archivo_excel'         => $archivo_excel, 
            'columns'               => $columns,
            'blank_flags'           => $blank_flags,

            /*
             * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026): la planilla declara si sus
             * costos vienen con IVA adentro, igual que la compra lo declara con
             * `provider_orders.precios_incluyen_iva`. Es la ÚNICA fuente de esa decisión para el
             * import: no hay fallback por condición fiscal ni por configuración de la cuenta.
             *
             * Default `false` (= los costos de la planilla son netos), que es el comportamiento
             * histórico del import: una importación que hoy anda no cambia de resultado.
             */
            'precios_incluyen_iva'  => filter_var($request->precios_incluyen_iva, FILTER_VALIDATE_BOOLEAN),
            'create_and_edit'       => $request->create_and_edit,

            /*
             * Hoja a importar, 0-based. Default 0 = primera hoja: un FormData que no manda
             * 'hoja' (la SPA sin desplegar) importa exactamente lo que importaba hasta hoy.
             *
             * No va acompañado de una fila de encabezado: la importación se rige por start_row,
             * que ya viaja y que el usuario ve. Y start_row/finish_row tienen que estar
             * calculados sobre ESTA hoja — el CSV que arma InitExcelImport es la hoja elegida,
             * 1:1, así que el número de fila cambia de significado al cambiar de hoja.
             */
            'hoja'                  => $request->input('hoja', 0),

            /*
             * Nombre de la hoja elegida, OPCIONAL (default null = "usá el índice").
             *
             * Existe por lo mismo que existe ExcelWorkbookReader::resolver_indice(): el
             * índice lo calcula SheetJS en el navegador y quien lee después es OpenSpout,
             * y los dos pueden discrepar. En el camino con IA eso queda tapado porque hay
             * ida y vuelta con el backend; acá, en el import clásico, el índice del
             * navegador va derecho a armar_archivo_csv() y nadie lo revisa. Cuando el
             * nombre viaja, gana él; cuando no, se usa el índice crudo, como hasta hoy.
             */
            'hoja_nombre'           => $request->input('hoja_nombre'),
            'start_row'             => $request->start_row,
            'finish_row'            => $request->finish_row,
            'provider_id'           => $request->provider_id, 
            'user'                  => $owner, 
            'auth_user_id'          => Auth()->user()->id, 
            'archivo_excel_path'    => $archivo_excel_path,
            'registrar_art_cre'    => $request->registrar_art_cre,
            'registrar_art_act'    => $request->registrar_art_act,

            'permitir_provider_code_repetido'                       => $request->permitir_provider_code_repetido,
            'permitir_provider_code_repetido_en_multi_providers'    => $request->permitir_provider_code_repetido_en_multi_providers,
            'actualizar_articulos_de_otro_proveedor'                => $request->actualizar_articulos_de_otro_proveedor,
            'actualizar_por_provider_code'                          => $request->actualizar_por_provider_code,
            'actualizar_proveedor'                                  => $request->actualizar_proveedor,
            'filas_repetidas_del_archivo'                           => $request->filas_repetidas_del_archivo,

            /*
             * Modo elegido por el usuario para interpretar el punto en columnas numéricas
             * ambiguas (grupo 239, prompt 04). Se normaliza acá, único punto de entrada:
             * no confiamos en que el frontend siempre mande un valor válido.
             */
            'interpretacion_punto'                                  => ImportHelper::normalizarInterpretacionPunto($request->interpretacion_punto),

        ]);
        
        if ($result['hubo_un_error']) {
            return response()->json([
                // 'hubo_un_error' => true,
                'message'       => $result['message'] ?? 'Ocurrió un error al preparar la importación.',
                // 'info_to_show'  => $result['info_to_show'],
                // 'functions_to_execute'  => $result['functions_to_execute'],
            ], 409);
        } else {
            return response(null, 200);
        }

    }

    function export(Request $request) {
        /**
         * Lista final de IDs de artículos a exportar.
         * Se calcula en request y se procesa el archivo dentro del job.
         */
        $article_ids = [];

        if ($request->has('filters')) {
            // Filtros serializados que llegan desde frontend para el exportado.
            $jsonData = $request->query('filters');
            $filters = json_decode($jsonData, true);

            if (!is_array($filters) || !count($filters)) {
                return response()->json([
                    'message' => 'No hay filtros activos para exportar articulos',
                ], 422);
            }

            $search_ct = new SearchController();
            $models = $search_ct->search($request, 'article', $filters);

            // Se extraen solo IDs para evitar serializar modelos completos al job.
            $article_ids = $models->pluck('id')->toArray();

            if (!count($article_ids)) {
                return response()->json([
                    'message' => 'No hay articulos que coincidan con el filtro para exportar',
                ], 422);
            }
        } else if ($request->has('articles_id')) {
            // IDs seleccionados manualmente desde listado.
            $ids = explode('-', $request->query('articles_id'));
            $article_ids = array_values(array_filter(array_map('intval', $ids)));

            if (!count($article_ids)) {
                return response()->json([
                    'message' => 'No hay articulos para exportar',
                ], 422);
            }
        }

        $export_history = ExportHistoryHelper::create_pending(
            $this->userId(),
            $this->userId(false),
            'article'
        );

        ProcessArticleExportJob::dispatch(
            $this->userId(),
            $this->userId(false),
            $article_ids,
            $export_history->id
        );

        return response()->json([
            'message' => 'La exportacion de articulos se esta procesando',
        ], 200);
    }

    function clientsExport($price_type_id = null) {
        Log::info('controller: '.$price_type_id);
        return Excel::download(new ArticleClientsExport($price_type_id), 'cc-articulos-clientes_'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
    }

    function baseExport() {
        return Excel::download(new ArticleExport(null, $this->userId(), true), 'base-comerciocity-articulos.xlsx');
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

        // Propaga el delete a los artículos espejo en clientes con inventory linkage.
        $inventory_linkage_helper = new InventoryLinkageHelper(null, $model->user_id);
        $inventory_linkage_helper->delete_client_articles_for_provider_article($model);
        
        // ImageController::deleteModelImages($model);
        $model->delete();
        ArticleHelper::check_recipes_despues_de_eliminar_articulo($recipes_donde_esta_este_articulo, $this);

        if ($send_notification) {
            // $this->sendDeleteModelNotification('article', $model->id);
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

    function barCodeEtiquetasPdf(Request $request, $ids) {
        $ancho = $request->query('ancho');
        $alto = $request->query('alto');
        $propiedades = null;
        $propiedades_config_raw = $request->query('propiedades_config');

        if ($propiedades_config_raw) {
            $decoded = json_decode($propiedades_config_raw, true);
            if (is_array($decoded)) {
                $propiedades = $decoded;
            }
        }

        if (!is_array($propiedades)) {
            $propiedades_raw = $request->query('propiedades');
            if ($propiedades_raw) {
                $propiedades = array_filter(explode(',', $propiedades_raw));
            }
        }

        $codigo_barras_alto = $request->query('codigo_barras_alto');
        $interlineado = $request->query('interlineado');

        new ArticleBarCodeEtiquetasPdf(
            $ids,
            $ancho ? (int) $ancho : null,
            $alto ? (int) $alto : null,
            $propiedades,
            $codigo_barras_alto !== null && $codigo_barras_alto !== '' ? (int) $codigo_barras_alto : null,
            $interlineado !== null && $interlineado !== '' ? (int) $interlineado : null
        );
    }

    function ticketsPdf($ids) {
        new ArticleTicketPdf($ids);
    }

    function pdf($ids, $moneda_id = null) {
        $user = $this->user();     

        if ($moneda_id == 'undefined') {
            $moneda_id = null;
        }

        if (config('app.APP_ENV') == 'production') {
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

    /**
     * PDF tabular de artículos según plantilla PdfColumnProfile (model_name article).
     * Query: pdf_column_profile_id (requerido), articles_id o filters (como export Excel).
     * Opcional: price_type_id cuando el dueño usa listas de precio (columna precio final del pivot).
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    function tablePdf(Request $request)
    {
        $profile_id = (int) $request->query('pdf_column_profile_id');
        $profile = PdfColumnProfile::where('user_id', $this->userId())
            ->where('model_name', 'article')
            ->where('id', $profile_id)
            ->with(['pdf_column_options' => function ($relation) {
                $relation->orderByPivot('order', 'asc');
            }])
            ->firstOrFail();

        $article_ids = [];

        if ($request->has('articles_id') && $request->query('articles_id') !== '') {
            $ids = explode('-', $request->query('articles_id'));
            $article_ids = array_map('intval', $ids);
        } elseif ($request->has('filters')) {
            $json_data = $request->query('filters');
            $filters = json_decode($json_data, true);
            $search_ct = new SearchController();
            $models = $search_ct->search($request, 'article', $filters);
            $article_ids = $models->pluck('id')->toArray();
        }

        if (! count($article_ids)) {
            abort(404, 'No hay artículos para generar el PDF');
        }

        /** Lista de precios opcional para resolver `article_final_price` desde el pivot. */
        $price_type_id = $request->query('price_type_id');

        $article_with = [
            'category',
            'sub_category',
            'brand',
            'provider',
            'iva',
            'unidad_medida',
            'images' => function ($query) {
                $query->orderBy('id', 'asc');
            },
        ];

        if (! is_null($price_type_id) && $price_type_id !== '' && UserHelper::uses_listas_de_precio()) {
            $article_with[] = 'price_types';
        }

        $articles = Article::where('user_id', $this->userId())
            ->whereIn('id', $article_ids)
            ->with($article_with)
            ->orderBy('created_at', 'DESC')
            ->get();

        new ArticleTablePdf($profile, $articles);
    }

    /**
     * PDF de ofertas (media página A4 por artículo) según plantilla `article_pdfs` del usuario autenticado.
     *
     * @param int    $article_pdf_id ID de la plantilla.
     * @param string $ids            IDs de artículos separados por guión.
     * @return void
     */
    function articleOfferSheetPdf($article_pdf_id, $ids)
    {
        $layout = ArticlePdfLayout::where('user_id', $this->userId())
            ->where('id', $article_pdf_id)
            ->firstOrFail();

        new ArticleOfferSheetPdf($layout, $ids);
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
                            ->whereNotNull('default_in_vender')
                            ->orderBy('default_in_vender', 'DESC')
                            ->withAll()
                            ->get();

        return response()->json(['models' => $models], 200);
    }

    function ultimos_actualizados() {

        // Solo articulos activos, mismo criterio que index(), articles_por_defecto() y los tres
        // buscadores de SearchController: un articulo que ningun buscador encuentra tampoco se
        // lista. Los inactivos son los "fantasma" que crean SaleProviderOrderHelper y
        // ProviderOrderArticleImport, y que NewProviderOrderHelper::check_article_status() activa
        // cuando la orden de compra actualiza stock.
        $articulos_por_defecto = Article::where('user_id', $this->userId())
                                        ->orderBy('id', 'DESC')
                                        ->where('default_in_vender', 1)
                                        ->where('status', 'active')
                                        ->withAll()
                                        ->get();

        $models = Article::where('user_id', $this->userId())
                            ->orderBy('id', 'DESC')
                            ->where('status', 'active')
                            ->where(function($q) {
                                $q->where('es_insumo', 0)
                                    ->orWhereNull('es_insumo');
                            })
                            // ->orderBy('updated_at', 'DESC')
                            ->withAll()
                            ->take(50)
                            ->get();


        $results = $articulos_por_defecto->merge($models->reverse());
        // $results = $articulos_por_defecto->merge(array_reverse($models));

        // Invertimos el orden usando Collection::reverse() y reindexamos con values()
        $models_invertidos = $models->reverse()->values();

        return response()->json(['models' => $results], 200);
    }

    function ventas_con_acopio($article_id) {
        $article = Article::findOrFail($article_id);

        $sales = $article->sales()
                ->withPivot('delivered_amount')
                ->wherePivotNotNull('delivered_amount')
                ->where('en_acopio', true)
                ->withAll()
                ->get();

        return response()->json(['sales'    => $sales], 200);
    }

    /**
     * Desglose del calculo del precio final del articulo, para el boton "?" del modal.
     *
     * El articulo se resuelve SIEMPRE acotado a la cuenta (userId()), no con un find() suelto, por
     * dos motivos que aparecieron juntos en el hallazgo
     * 20260805-final-price-description-500-si-no-existe-el-articulo:
     *
     *   1. Con un id inexistente, find() devuelve null y setFinalPrice() reventaba con 500. Un id
     *      que no existe es un 404, no un error del servidor.
     *   2. Sin el filtro por cuenta, un id de OTRO cliente devolvia su desglose de precios: costo
     *      real, margenes y precio final. Es una fuga de datos entre las ~40 cuentas del sistema, y
     *      el 404 de arriba es justamente lo que la cierra sin decir si el id existe o no.
     *
     * @param int $id Id del articulo.
     * @return \Illuminate\Http\JsonResponse
     */
    function get_final_price_description($id) {
        $article = Article::where('id', $id)
                        ->where('user_id', $this->userId())
                        ->first();

        if (is_null($article)) {
            return response()->json(['message' => 'No se encontro el articulo'], 404);
        }

        $detalle = ArticleHelper::setFinalPrice($article, null, null, null, true, null, true);

        // Un articulo sin costo NI precio hace que setFinalPrice() corte antes de armar nada y
        // devuelva el MODELO en vez de un array. Se normaliza aca, en el borde: el modal ahora abre
        // al instante, asi que sin esto el usuario ve girar el spinner y despues un cuadro vacio.
        if (!is_array($detalle)) {
            $detalle = DesglosePrecioHelper::sin_calculo();
        }

        // `description` se DERIVA de `detalle`: una sola emision, dos vistas de lo mismo. Sigue
        // siendo array de strings a proposito, para que un bundle viejo de la PWA -que pinta cada
        // renglon tal cual- no reciba objetos y muestre '[object Object]'.
        return response()->json([
            'description'    => DesglosePrecioHelper::solo_textos($detalle),
            'detalle'        => $detalle,
        ], 200);
    }

    /**
     * Prompt 357/01 — desglose del calculo del precio de UNA lista de precio, para el boton "?" de
     * cada tarjeta de lista en el modal del articulo. Devuelve la cadena completa: el costo real y
     * el precio final unico que ya devolvia get_final_price_description(), mas el tramo propio de
     * esa lista al final.
     *
     * El $guardar_cambios en true es deliberado, igual que en el endpoint de al lado: pedir la
     * explicacion recalcula y guarda. Ponerlo en false daria la ilusion de una simulacion sin
     * efectos que igual escribiria, porque aplicar_precios_segun_listas_de_precios() toca los
     * pivots con syncWithoutDetaching()/updateExistingPivot() sin mirar esa bandera (esta advertido
     * en el docblock de setFinalPrice).
     */
    function get_price_type_description($id, $price_type_id) {
        // Mismo filtro por cuenta que el metodo hermano de arriba: este endpoint tenia el guard del
        // 404 pero tampoco acotaba por userId(), asi que devolvia el desglose de precios de un
        // articulo de otro cliente. Los dos endpoints del boton "?" quedan con el mismo criterio.
        $article = Article::where('id', $id)
                        ->where('user_id', $this->userId())
                        ->first();

        if (is_null($article)) {
            return response()->json(['message' => 'No se encontro el articulo'], 404);
        }

        $detalle = ArticleHelper::setFinalPrice($article, null, null, null, true, null, true, $price_type_id);

        // Mismo guard que el endpoint hermano de arriba, por el mismo motivo.
        if (!is_array($detalle)) {
            $detalle = DesglosePrecioHelper::sin_calculo();
        }

        // Mismo criterio que el endpoint hermano de arriba: `description` se DERIVA de `detalle`
        // (una sola emision, dos vistas) y se mantiene como array de strings para no romperle el
        // desglose a una PWA que todavia no actualizo su bundle.
        return response()->json([
            'description'    => DesglosePrecioHelper::solo_textos($detalle),
            'detalle'        => $detalle,
        ], 200);
    }

    /**
     * Prompt 308: cambio MANUAL de proveedor de un artículo desde el listado, con dos flags
     * independientes (ver modal del prompt 309):
     *   - eliminar_descuentos_proveedor_anterior (default true)
     *   - crear_descuentos_proveedor_nuevo (default true)
     * La lógica de negocio (qué descuentos borrar/crear, recálculo de costo_real) vive en
     * ArticleProviderDiscountHelper::change_provider(), no acá.
     *
     * @param \Illuminate\Http\Request $request Espera: id (artículo), provider_id (nuevo
     *                                          proveedor), y los dos flags opcionales.
     * @return \Illuminate\Http\JsonResponse
     */
    function change_provider(Request $request) {

        $model = Article::find($request->id);

        // Los dos flags son independientes y ambos activados por defecto (ver prompt 308/309):
        // si no vienen en el request, se asume que el usuario quiere el comportamiento
        // "reemplazar" completo (barrer lo viejo + crear lo nuevo).
        $eliminar_descuentos_proveedor_anterior = $request->has('eliminar_descuentos_proveedor_anterior')
            ? filter_var($request->eliminar_descuentos_proveedor_anterior, FILTER_VALIDATE_BOOLEAN)
            : true;

        $crear_descuentos_proveedor_nuevo = $request->has('crear_descuentos_proveedor_nuevo')
            ? filter_var($request->crear_descuentos_proveedor_nuevo, FILTER_VALIDATE_BOOLEAN)
            : true;

        $model = ArticleProviderDiscountHelper::change_provider(
            $model,
            $request->provider_id,
            $eliminar_descuentos_proveedor_anterior,
            $crear_descuentos_proveedor_nuevo
        );

        $this->attach_provider($model);

        return response()->json(['model' => $this->fullModel('Article', $model->id)], 200);
    }

    /**
     * Prompt 308 (tarea 4): datos para pre-llenar el modal de cambio de proveedor (prompt 309) —
     * qué descuentos tagueados tiene HOY el proveedor anterior del artículo (candidatos a
     * borrarse) y qué bonificaciones estándar (`provider_discounts`) tiene el proveedor destino
     * (candidatas a crearse). Consulta pura, no modifica nada.
     *
     * @param int $id          Id del artículo.
     * @param int $provider_id Id del proveedor DESTINO (todavía no aplicado al artículo).
     * @return \Illuminate\Http\JsonResponse
     */
    function change_provider_preview($id, $provider_id) {

        $article = Article::find($id);

        $preview = ArticleProviderDiscountHelper::get_change_provider_preview($article, $provider_id);

        return response()->json($preview, 200);
    }
}
