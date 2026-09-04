<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('user', 'CommonLaravel\AuthController@get_user');

// Route::middleware('auth:sanctum')->get('/user', 'CommonLaravel\AuthController@get_user');

// Route::middleware(['set.user.database'])->group(function() {
// Route::middleware(['set.user.database', 'auth:sanctum'])->group(function() {
Route::middleware(['auth:sanctum'])->group(function() {

    /*
        Todos los catalogos del arranque en UNA sola respuesta (mision 41, 12/8/2026). Reemplaza a
        ~70 requests que la SPA dispara una detras de otra al iniciar sesion.

        🔴 ES POST Y NO GET, A PROPOSITO. Son cerca de 70 nombres de modelo: una query string con
        todos queda cerca del limite de largo de URL de algunos proxies, y el dia que se pase nadie
        lo va a ver como "URL demasiado larga" sino como catalogos que no cargan en un cliente y si
        en otro. No lo cambies a GET. Si te molesta que un POST no sea cacheable: este endpoint no
        se cachea por diseno --se decidio dejar la cache para despues, ver el alcance de la mision--.

        Va adentro de este mismo grupo de middleware que los endpoints individuales, y no en otro:
        cada modelo se resuelve llamando al index() real de su controller, asi que los filtros por
        usuario y por permisos tienen que estar puestos igual que para ellos.
    */
    Route::post('recursos-iniciales', 'RecursosInicialesController@index');

    /*
        Eventos de UX de la demo (mision 50). Va adentro de este grupo porque el grupo 'api' ya
        trae DemoSessionVigente, que es el que corta el acceso cuando el turno del lead termino.

        Responde 204 y no 403 cuando la sesion no es de demo: este mismo empresa-spa se despliega
        en las instancias de los ~40 clientes reales.
    */
    Route::post('demo/evento', 'DemoEventoController@store');

    /*
        El plan de la demo para el panel lateral del lead (mision 51). Mismo criterio que el
        endpoint de arriba: 204 mudo fuera de una sesion de demo, sin tocar la base.
    */
    Route::get('demo/plan', 'DemoPlanController@index');

    // CommonLaravel
    // ----------------------------------------------------------------------------------------------------
    // Generals
    Route::post('search/{model_name}/{_filters?}/{paginate?}', 'CommonLaravel\SearchController@search');
    Route::post('search-from-modal/{model_name}', 'CommonLaravel\SearchController@searchFromModal');
    // Buscador general unificado (view-header): OR entre props propias + relaciones + AND de filtros extra.
    Route::post('global-search/{model_name}', 'CommonLaravel\SearchController@globalSearch');
    Route::post('search/save-if-not-exist/{model_name}/{propertye}/{query}', 'CommonLaravel\SearchController@saveIfNotExist');
    // Excel de ventas fidedigno a la pantalla: recibe el estado completo del filtro por POST.
    Route::post('sales/excel/export', 'SaleController@excel_export_view');
    Route::post('sales/excel/breakdown-export', 'SaleController@excel_breakdown_export_view');
    Route::get('previus-day/{model_name}/{index}/{date_param?}', 'CommonLaravel\PreviusDayController@previusDays');
    Route::get('previus-next/{model_name}/{index}', 'CommonLaravel\PreviusNextController@previusNext');
    Route::get('previus-next-index/{model_name}/{id}', 'CommonLaravel\PreviusNextController@getIndexPreviusNext');
    /* Abre un modelo por id, en una sola request. Reemplaza al par indice + posicion de arriba. */
    Route::get('previus-next-by-id/{model_name}/{id}', 'CommonLaravel\PreviusNextController@byId');
    Route::put('update/{model_name}', 'CommonLaravel\UpdateController@update');
    Route::put('delete/{model_name}', 'CommonLaravel\DeleteController@delete');

    // Papelera
    Route::get('papelera/{model_name}', 'PapeleraController@index');
    Route::put('papelera/restaurar/{model_name}/{model_id}', 'PapeleraController@restaurar');
    Route::post('papelera/restaurar-lote/{model_name}', 'PapeleraController@restaurar_lote');
    Route::post('papelera/restaurar-filtrados/{model_name}', 'PapeleraController@restaurar_filtrados');
    
    // User
    Route::get('user', 'CommonLaravel\AuthController@get_user');
    // Preferencias de UI del chat con el asistente de IA (misión chat-ia-y-modulo-ia). Misma
    // familia que set-dark-mode: es una preferencia POR PERSONA (Auth::user()) y va acá, fuera
    // del gate de la extensión — el gate protege los DATOS del chat, no una coordenada de pantalla.
    // 🔴 Tiene que registrarse ANTES de `user/{id}`: las rutas se matchean en orden de registro
    // y esta tiene dos segmentos, igual que la del comodín — abajo de `user/{id}`, el PUT caería
    // en UserController@update con id = "set-chat-ia-preferencias" (set-dark-mode zafa solo
    // porque sus tres segmentos no calzan en el comodín de dos).
    Route::put('user/set-chat-ia-preferencias', 'UserController@set_chat_ia_preferencias');
    // Impresora del Ticket 2.0. Misma familia que las dos de arriba: preferencia POR PERSONA,
    // y con la misma trampa del orden — dos segmentos, así que abajo de `user/{id}` caería en
    // UserController@update con id = "set-impresora".
    Route::put('user/set-impresora', 'UserController@set_impresora');
    Route::put('user/{id}', 'UserController@update');
    Route::put('user-password', 'CommonLaravel\UserController@updatePassword');
    Route::post('user/last-activity', 'CommonLaravel\UserController@setLastActivity');
    Route::put('user/set_eliminar_articulos_offline/{user_id}/{value}', 'UserController@set_eliminar_articulos_offline');
    Route::put('user/set-img-auto-timeout/{value}', 'UserController@set_img_auto_timeout');
    Route::put('user/set-dark-mode/{value}', 'UserController@set_dark_mode');



    // Configuración de layout del módulo de vender por usuario
    Route::put('user-configuration/vender-layout', 'UserConfigurationController@updateVenderLayout');

    // Atajos de teclado configurables del módulo Vender (por usuario autenticado)
    Route::get('vender-keyboard-shortcut', 'VenderKeyboardShortcutController@show');
    Route::put('vender-keyboard-shortcut', 'VenderKeyboardShortcutController@update');

    // Employee
    Route::resource('employee', 'CommonLaravel\EmployeeController');

    // Permissions
    Route::get('permission', 'CommonLaravel\PermissionController@index');

    // Extencions
    Route::get('extencion', 'CommonLaravel\ExtencionController@index');

    // Images
    Route::post('set-image/{prop}', 'CommonLaravel\ImageController@setImage');
    Route::delete('delete-image-prop/{model_name}/{id}/{prop_name}', 'CommonLaravel\ImageController@deleteImageProp');
    Route::delete('delete-image-model/{model_name}/{model_id}/{image_id}', 'CommonLaravel\ImageController@deleteImageModel');

    // Error
    Route::post('error', 'CommonLaravel\ErrorController@store');

    // ----------------------------------------------------------------------------------------------------


    Route::get('online-configuration', 'OnlineConfigurationController@index');
    Route::put('online-configuration/{id}', 'OnlineConfigurationController@update');
    // Prompt 358: prueba de la config SMTP propia del cliente. La usa el dueño del comercio desde
    // el ERP (no es pública), por eso va dentro del mismo grupo de middleware de autenticación.
    Route::post('online-configuration/test-mail', 'OnlineConfigurationController@testMail');
    // Grupo 202, prompt 02: paleta de colores generada por IA a partir del logo del comercio.
    // Tiene que quedar ANTES de cualquier ruta 'online-configuration/{id}' para que Laravel no
    // matchee 'generate-palette' como si fuera un {id}.
    Route::post('online-configuration/generate-palette', 'OnlineConfigurationController@generatePalette');
    Route::post('set-comercio-city-user', 'GeneralController@setComercioCityUser');
    Route::get('update-feature', 'UpdateFeatureController@index');

    // Notificaciones de versión sincronizadas desde admin-api (por usuario autenticado).
    Route::get('synced-version-notification/pending', 'SyncedVersionNotificationController@pending');
    Route::post('synced-version-notification/{id}/mark-read', 'SyncedVersionNotificationController@markRead');

    // Soporte tipo chat para clientes del sistema.
    Route::get('support-ticket', 'SupportTicketController@index');
    Route::post('support-ticket', 'SupportTicketController@store');
    Route::get('support-ticket/{id}', 'SupportTicketController@show');
    Route::put('support-ticket/{id}', 'SupportTicketController@update');
    Route::post('support-message', 'SupportMessageController@store');
    Route::post('support-message/{id}/retry-remote-sync', 'SupportMessageController@retry_remote_sync');
    Route::post('support-message/{id}/mark-read', 'SupportMessageController@mark_read');
    Route::post('support-message/typing', 'SupportMessageController@typing');

    Route::get('online-template', 'OnlineTemplateController@index');

    Route::get('concepto-stock-movement', 'ConceptoStockMovementController@index');

    // Filter history
    Route::get('filter-history/from-date/{from_date?}/{until_date?}', 'FilterHistoryController@index');

    // Inventory performance
    Route::get('inventory-performance', 'InventoryPerformanceController@index');

    // Artículos bajo el stock mínimo del último reporte, paginados y con buscador
    // (reemplaza el envío de todos los artículos dentro del JSON del reporte principal).
    Route::get('inventory-performance/articles-stock-minimo', 'InventoryPerformanceController@articles_stock_minimo');

    // Inputs Size
    Route::resource('inputs-size', 'InputsSizeController');

    // Afip Tipo Comprobantes
    Route::get('afip-tipo-comprobante', 'AfipTipoComprobanteController@index');

    // Grupos de articulos para tipos de precio en VENDER
    Route::resource('article-price-type-group', 'ArticlePriceTypeGroupController');

    // Consultora de precios
    Route::get('consultora-de-precio/buscador/{codigo}', 'ConsultoraDePrecioController@buscador');


    // Dias de entrega
    Route::resource('/delivery-day', 'DeliveryDayController');


    // Devolciones
    Route::get('devoluciones/search-sale/{num}', 'DevolucionesController@search_sale');
    Route::post('devoluciones/', 'DevolucionesController@store');


    // Hojas de ruta
    Route::resource('road-map', 'RoadMapController');
    Route::get('road-map/search-sales/{fecha_entrega}', 'RoadMapController@search_sales');
    Route::get('road-map/{employee_id}/from-date/{date_param}/{from_date?}/{until_date?}', 'RoadMapController@index');

    // Observaciones de clientes
    Route::post('road-map-client-observation', 'RoadMapClientObservationController@store');

    // Repartidores 
    Route::resource('dealer', 'DealerController');

    // Catálogo global de plataformas (credenciales de app Comercio City; solo listado para selects)
    Route::resource('platform', 'PlatformController')->only(['index']);

    // Conectores OAuth (Mercado Libre, Tienda Nube)
    Route::resource('platform-connector', 'PlatformConnectorController');

    // Comisiones por ventas termiandas 
    Route::resource('venta-terminada-commission', 'VentaTerminadaCommissionController');

    // Promocion vinoteca comision
    Route::resource('promocion-vinoteca-commission', 'PromocionVinotecaCommissionController');
    

    // Observaciones para pdf de articulos
    Route::resource('article-pdf-observation', 'ArticlePdfObservationController');

    // Plantillas de PDF de ofertas (media página A4)
    Route::resource('article-pdf', 'ArticlePdfController');



    // Vinoteca

    Route::resource('cepa', 'CepaController');
    Route::resource('bodega', 'BodegaController');
    Route::resource('promocion-vinoteca', 'PromocionVinotecaController');
    Route::put('promocion-vinoteca/delete-stock/{id}', 'PromocionVinotecaController@delete_stock');

    // Categorias y rangos para tipos de precios | Golo norte
    Route::resource('category-price-type-range', 'CategoryPriceTypeRangeController');


    // Combos
    Route::resource('combo', 'ComboController');


    // Cajas
    Route::resource('caja', 'CajaController');
    Route::put('abrir-caja/{caja_id}', 'CajaController@abrir_caja');
    Route::put('cerrar-caja/{caja_id}', 'CajaController@cerrar_caja');

    // Línea de tiempo de liquidaciones pendientes de una caja (Grupo 223 · Prompt 02)
    Route::get('caja/{id}/liquidaciones-pendientes', 'CajaController@liquidaciones_pendientes');

    // Apertura de cajas
    Route::get('apertura-caja/{caja_id}', 'AperturaCajaController@index');
    Route::get('apertura-caja/show/{id}', 'AperturaCajaController@show');
    Route::post('apertura-caja/reabrir/{id}', 'AperturaCajaController@reabrir');

    // Movimientos de Caja
    Route::resource('movimiento-caja', 'MovimientoCajaController')->except('index', 'show');
    Route::get('movimiento-caja/{apertura_caja_id}', 'MovimientoCajaController@index');

    // Concepto de movimientos de Caja
    Route::resource('concepto-movimiento-caja', 'ConceptoMovimientoCajaController');

    // Override de liquidación/comisión por método de pago dentro de una caja (Grupo 223 · Prompt 01)
    // 'index' y 'show' se excluyen del resource porque comparten el mismo patrón de URI
    // (`{param}` único) y colisionarían entre sí; se define el listado filtrado por caja_id
    // aparte, mismo criterio que 'movimiento-caja' un poco más arriba.
    Route::get('caja-liquidacion-config/{caja_id}', 'CajaLiquidacionConfigController@index');
    Route::resource('caja-liquidacion-config', 'CajaLiquidacionConfigController')->except('index', 'show');

    // Cajas por defecto
    Route::resource('default-payment-method-caja', 'DefaultPaymentMethodCajaController');

    // Movimientos entre Cajas
    Route::resource('movimiento-entre-caja', 'MovimientoEntreCajaController')->except('index');
    Route::get('movimiento-entre-caja/from-date/{from_date?}/{until_date?}', 'MovimientoEntreCajaController@index');



    // Cuotas
    Route::resource('cuota', 'CuotaController');

    // Impuestos sobre ventas (Capa 2 — Prompt 260, ej. IIBB)
    Route::resource('sale-tax', 'SaleTaxController');



    // Insumos (Producción V2)
    // Importante: esta ruta debe ir ANTES del resource para que no la capture `article/{id}` (show)
    Route::get('article/get-insumos', 'ArticleController@get_insumos');

    // Prompt 308: cambio MANUAL de proveedor de un artículo (dos flags independientes) y su
    // preview de descuentos para el modal (prompt 309). Igual que get-insumos: deben ir ANTES
    // del resource para que `article/{article}` (PUT/GET del resource) no las capture.
    Route::put('article/change-provider', 'ArticleController@change_provider');
    Route::get('article/change-provider/preview/{id}/{provider_id}', 'ArticleController@change_provider_preview');

    Route::resource('article', 'ArticleController')->except(['index']);
    Route::get('article/index/from-status', 'ArticleController@index');
    Route::get('article/index/eliminados', 'ArticleController@index_deleted');
    Route::get('/article/deleted-models/{last_updated}', 'ArticleController@deletedModels');
    Route::post('/article/excel/import', 'ArticleController@import');
    Route::post('/article/new-article', 'ArticleController@newArticle');
    Route::get('/article/set-featured/{id}', 'ArticleController@setFeatured');
    Route::get('/article/set-online/{id}', 'ArticleController@setOnline');
    Route::get('/article/charts/{id}/{from_date}/{until_date}', 'ArticleController@charts');
    Route::get('/article/sales/{id}/{from_date}/{until_date}', 'ArticleController@sales');
    Route::get('/article/providers-history/{article_id}', 'ArticleController@providersHistory');

    Route::put('/article/reset-stock/to-0', 'ArticleController@resetStock');

    Route::get('/article-ticket-info', 'ArticleTicketInfoController@index');

    Route::put('/article-update-addresses', 'ArticleController@update_addresses_stock');

    Route::put('/article-update-varians-stock', 'ArticleController@update_variants_stock');

    
    // Ultimos articulos actualizados
    Route::get('/articles-ultimos-actualizados', 'ArticleController@ultimos_actualizados');

    // Articulos por defecto en VENDER
    Route::get('/articles-por-defecto', 'ArticleController@articles_por_defecto');

    Route::get('/article-acopios/{article_id}', 'ArticleController@ventas_con_acopio');

    // Descripcion del precio final
    Route::get('/article/final-price-description/{id}', 'ArticleController@get_final_price_description');
    Route::get('/article/price-type-description/{id}/{price_type_id}', 'ArticleController@get_price_type_description');
    
    // Exportar Excel (procesamiento en cola)
    Route::get('article/excel/export', 'ArticleController@export');
    Route::get('client/excel/export', 'ClientController@export');
    Route::get('provider/excel/export', 'ProviderController@export');



    // Vender
    // Esta ruta se usa en VENDER - LISTADO - CONSULTORA DE PRECIOS
    Route::get('/vender/buscar-articulo-por-codido/{code}', 'VenderController@search_bar_code');
    Route::post('/vender/buscar-articulo-por-nombre/{from_provider_order?}', 'VenderController@search_nombre')->middleware('throttle:300,1');



    Route::resource('stock-movement', 'Stock\StockMovementController')->except(['index', 'show']);
    // Route::resource('stock-movement', 'StockMovementController')->except(['index', 'show']);
    Route::get('stock-movement/{article_id}/{ultimos_movimientos}/{concepto_id}', 'Stock\StockMovementController@index');

    Route::get('price-change/{article_id}', 'PriceChangeController@index');

    Route::put('sale/{sale_id}/delivery-info', 'SaleController@update_delivery_info');
    Route::post('sale/{sale_id}/send-client-mail', 'SaleController@send_client_mail');
    Route::post('sale/send-client-mail-bulk', 'SaleController@send_client_mail_bulk');
    Route::put('sale/{sale_id}/etiqueta-sender', 'SaleController@update_etiqueta_sender');
    Route::resource('sale-sender-info', 'SaleSenderInfoController')->except(['create', 'edit']);
    Route::resource('sale', 'SaleController');
    Route::get('sale/from-date/{modulo}/{from_date?}/{until_date?}', 'SaleController@index');
    Route::put('sale/update-prices/{id}', 'SaleController@updatePrices');
    Route::get('sale/charts/{from}/{to}', 'SaleController@charts');
    Route::get('sales-ventas-sin-cobrar', 'SaleController@ventas_sin_cobrar');
    Route::put('sale-set-terminada/{sale_id}', 'SaleController@set_terminada');
    Route::put('sale-clear-actualizandose-por/{sale_id}', 'SaleController@clear_actualizandose_por');
    Route::get('sale/por-entregar/{from}/{to}', 'SaleController@por_entregar');
    Route::put('sale/unidades-entregadas/{sale_id}', 'SaleController@unidades_entregadas');
    Route::put('sale-cerrar-venta/{sale_id}', 'SaleController@cerrar_venta');


    // 
    Route::get('acopio-article-delivery/{sale_id}', 'AcopioArticleDeliveryController@from_sale');

    // Hacer Nota de credito AFIP
    Route::post('sale/nota-credito-afip/{sale_id}', 'SaleController@nota_credito_afip');



    // AFIP / Problemas al facturar
    Route::get('afip-ticket/problemas-al-facturar', 'AfipTicketController@problemas_al_facturar');
    Route::get('afip-ticket/consultar-comprobante/{afip_ticket_id}', 'AfipTicketController@consultar_comprobante');


    Route::delete('afip-ticket/{id}', 'AfipTicketController@destroy');


    Route::get('sale-modifications/{sale_id}', 'SaleModificationController@index');

    // Adjuntos por ítem de venta
    Route::post('sale-article-attachment', 'SaleArticleAttachmentController@store');
    Route::get('sale-article-attachment/by-sale/{sale_id}', 'SaleArticleAttachmentController@by_sale');
    Route::get('sale-article-attachment/file/{id}', 'SaleArticleAttachmentController@show_file');
    Route::delete('sale-article-attachment/{id}', 'SaleArticleAttachmentController@destroy');


    // Movimientos de depositos
    Route::resource('deposit-movement', 'DepositMovementController');
    Route::get('deposit-movement/from-date/{from_date?}/{until_date?}', 'DepositMovementController@index');

    Route::get('deposit-movement-en-curso', 'DepositMovementController@en_curso');

    Route::get('deposit-movement-status', 'DepositMovementStatusController@index');


    // Metodos de pago para facturar
    Route::resource('afip-selected-payment-method', 'AfipSelectedPaymentMethodController');


    // Descuentos en metodos de pago
    Route::resource('cc-payment-method-discount', 'CurrentAcountPaymentMethodDiscountController');


    // Agenda
    
    // Pending
    Route::resource('pending', 'PendingController');
    Route::get('pending/from-date/{from_date}/{until_date}', 'PendingController@index');
    Route::get('pending-recurrentes', 'PendingController@recurrentes');

    // Pending Completed
    Route::resource('pending-completed', 'PendingCompletedController');
    Route::get('pending-completed/from-date/{from_date}/{until_date}', 'PendingCompletedController@index');
    
    // Unidad Frecuencia
    Route::resource('unidad-frecuencia', 'UnidadFrecuenciaController');


    
    // Expenses | Gastos
    Route::resource('expense', 'ExpenseController');
    Route::get('expense/from-date/{from_date?}/{until_date?}', 'ExpenseController@index');

    Route::resource('expense-concept', 'ExpenseConceptController');
    
    // Afip tickets
    Route::post('afip-ticket', 'SaleController@makeAfipTicket');
    Route::get('afip/get-importes/{sale_id}', 'AfipTicketController@get_importes');

    // Consolidación de ventas para facturación
    Route::get('sales/por-consolidar', 'SaleController@ventasPorConsolidar');
    Route::post('sales/consolidar-facturacion', 'SaleController@consolidarFacturacion');

    // Article Performance
    Route::get('article-performance/{article_id}', 'ArticlePerformanceController@index');

    Route::resource('brand', 'BrandController');
    Route::resource('category', 'CategoryController');
    Route::resource('condition', 'ConditionController');
    Route::resource('iva', 'IvaController');
    // provider/options tiene que declararse ANTES del resource: Route::resource registra
    // GET provider/{provider} para el show, y esa ruta captura "options" como si fuera un id
    // si queda declarada primero (4/8/2026).
    Route::get('provider/options', 'ProviderController@options');
    Route::resource('provider', 'ProviderController');
    Route::get('provider/get-afip-information-by-cuit/{cuit}', 'ProviderController@get_afip_information_by_cuit');
    Route::post('/provider/excel/import', 'ProviderController@import');

    Route::resource('provider-price-list', 'ProviderPriceListController');
    Route::resource('sub-category', 'SubCategoryController');
    Route::resource('iva-condition', 'IvaConditionController');
    Route::resource('location', 'LocationController');
    Route::resource('current-acount-payment-method', 'CurrentAcountPaymentMethodController');
    // client/options tiene que declararse ANTES del resource, mismo motivo que provider/options.
    Route::get('client/options', 'ClientController@options');
    Route::resource('client', 'ClientController');
    Route::post('client/excel/import', 'ClientController@import');
    Route::get('client/get-afip-information-by-cuit/{cuit}', 'ClientController@get_afip_information_by_cuit');
    Route::patch('client/{id}/phone', 'ClientController@update_phone');

    Route::resource('seller', 'SellerController');
    Route::resource('price-type', 'PriceTypeController');

    Route::resource('provider-order', 'ProviderOrderController');
    Route::post('provider-order/excel/import', 'ProviderOrderController@import_excel_articles');
    Route::get('provider-order/from-date/{from_date?}/{until_date?}', 'ProviderOrderController@index');
    Route::get('provider-order/days-to-advise/not-received', 'ProviderOrderController@indexDaysToAdvise');
    Route::resource('provider-order-status', 'ProviderOrderStatusController');
    Route::resource('provider-order-afip-ticket', 'ProviderOrderAfipTicketController');
    Route::resource('provider-order-afip-ticket-iva', 'ProviderOrderAfipTicketIvaController');
    Route::resource('provider-order-discount', 'ProviderOrderDiscountController');
    
    Route::resource('order', 'OrderController');
    Route::get('order/unconfirmed/models', 'OrderController@indexUnconfirmed');
    Route::get('order/from-date/{from_date?}/{until_date?}', 'OrderController@index');
    Route::put('order/update-status/{order_id}', 'OrderController@updateStatus');
    Route::put('order/cancel/{order_id}', 'OrderController@cancel');

    Route::get('meli-order-status', 'MeliOrderStatusController@index');
    Route::get('meli-order/from-date/{from_date?}/{until_date?}', 'MeLiOrderController@index');
    Route::post('meli-order/create-sale/{id}', 'MeLiOrderController@create_sale');
    Route::resource('meli-order', 'MeLiOrderController')->except(['index', 'create', 'edit']);


    Route::resource('order-status', 'OrderStatusController');
    Route::resource('buyer', 'BuyerController');
    Route::resource('delivery-zone', 'DeliveryZoneController');

    Route::resource('payment-method', 'PaymentMethodController');

    Route::resource('payment-method-type', 'PaymentMethodTypeController');

    Route::resource('deposit', 'DepositController');
    Route::resource('size', 'SizeController');
    Route::resource('color', 'ColorController');

    Route::resource('article-discount', 'ArticleDiscountController');
    Route::resource('article-discount-blanco', 'ArticleDiscountBlancoController');
    Route::resource('article-surchage', 'ArticleSurchageController');
    Route::resource('article-surchage-blanco', 'ArticleSurchageBlancoController');


    Route::resource('tipo-envase', 'TipoEnvaseController');

    Route::resource('description', 'DescriptionController');
    Route::resource('discount', 'DiscountController');
    Route::resource('surchage', 'SurchageController');
    Route::post('service', 'ServiceController@store');
    // Route::resource('budget', 'BudgetController')->except(['index']);
    Route::post('budget/{id}/duplicate', 'BudgetController@duplicate');
    Route::resource('budget', 'BudgetController');
    Route::get('budget/from-date/{from_date}/{until_date?}', 'BudgetController@index');
    Route::resource('budget-status', 'BudgetStatusController');
    Route::resource('afip-information', 'AfipInformationController');

    Route::resource('production-movement', 'ProductionMovementController');
    Route::get('production-movement/from-date/{from_date?}/{until_date?}', 'ProductionMovementController@index');
    Route::get('production-movement/current-amounts/{article_id}', 'ProductionMovementController@currentAmounts');
    Route::get('production-movement/current-amounts/all-articles/all-recipes', 'ProductionMovementController@currentAmountsAllArticles');
    Route::get('production-movement/es-el-ultimo-creado/{production_movement_id}/{article_id}', 'ProductionMovementController@esElUltimoCreado');

    Route::resource('order-production', 'OrderProductionController');
    Route::resource('order-production-status', 'OrderProductionStatusController');
    Route::resource('address', 'AddressController');

    Route::resource('title', 'TitleController');

    Route::get('message/{buyer_id}', 'MessageController@fromBuyer');
    Route::get('message/set-read/{buyer_id}', 'MessageController@setRead');
    Route::post('message', 'MessageController@store');

    //Route::resource('-', '-Controller');

    // CurrentAcounts
    
    // Antes usaba esta ruta para obtener los current_acounts, ahora llamo al controlador de CreditAccount
    // Route::get('/current-acount/{model_name}/{model_id}/{months_ago}', 'CurrentAcountController@index');
    Route::get('/current-acount/{credit_account_id}/{cantidad_movimientos}', 'CreditAccountController@index');

    Route::post('/current-acount/pago', 'CurrentAcountController@pago');
    Route::post('/current-acount/nota-credito', 'CurrentAcountController@notaCredito');
    Route::post('/current-acount/nota-debito', 'CurrentAcountController@notaDebito');
    Route::post('/current-acount/saldo-inicial', 'CurrentAcountController@saldoInicial');
    Route::delete('/current-acount/{model_name}/{id}', 'CurrentAcountController@delete');
    Route::get('check-saldos/{credit_account_id}', 'CurrentAcountController@check_saldos_y_pagos');



    // Comprobantes

    // Notas de credito
    Route::get('nota-credito/from-date/{from_date?}/{until_date?}', 'NotaCreditoController@index');
    Route::get('nota-credito', 'NotaCreditoController@index');

    // Pagos de Clientes
    Route::get('pago-de-cliente/from-date/{from_date?}/{until_date?}', 'PagoDeClienteController@index');


    // CurrentAcounts Cheques
    Route::get('/cheque', 'ChequeController@index');
    Route::put('/cheque/cobrar', 'ChequeController@cobrar');
    Route::put('/cheque/pagar', 'ChequeController@pagar');
    Route::put('/cheque/rechazar', 'ChequeController@rechazar');
    Route::put('/cheque/endosar', 'ChequeController@endosar');
    Route::delete('/cheque/{id}', 'ChequeController@destroy');



    // Reportes
    Route::get('company-performance/{mes_inicio?}/{mes_fin?}', 'CompanyPerformanceController@index');

    // Estado de Resultados devengado (Grupo 225 · Prompt 01): aditivo, no reemplaza el reporte
    // viejo de arriba (company-performance) hasta que se ejecute el grupo 227.
    Route::get('reportes/estado-resultados', 'EstadoResultadosController@index');

    // Posición fiscal: IVA, IIBB y pagos a cuenta de Ganancias (Grupo 225 · Prompt 02). Aditivo,
    // no reemplaza ningún reporte existente.
    Route::get('reportes/posicion-fiscal', 'PosicionFiscalController@index');

    // Flujo de Caja percibido: ingresos/egresos por caja y método de pago + plata en tránsito
    // (Grupo 226 · Prompt 01). Aditivo, no reemplaza ningún reporte existente.
    Route::get('reportes/flujo-caja', 'FlujoCajaController@index');

    // Drill-down genérico de cualquier tarjeta de los reportes de arriba (Estado de Resultados,
    // Posición Fiscal, Flujo de Caja) — Grupo 226 · Prompt 02. Un solo endpoint parametrizado por
    // `concepto` en vez de veinte endpoints específicos.
    Route::get('reportes/detalle', 'ReporteDetalleController@index');

    // Article Purchase
    Route::post('article-purchase', 'ArticlePurchaseController@index');


    Route::get('/export-history/{model_name}', 'ExportHistoryController@index');

    Route::get('/masive-update/{model_name}', 'MasiveUpdateController@index');
    Route::get('/masive-update/detail/{id}', 'MasiveUpdateController@show');
    Route::post('/masive-update/{id}/revert', 'MasiveUpdateController@revert');

    Route::get('/import-history/{model_name}', 'ImportHistoryController@index');
    Route::get('/import-history/updated-models/{id}', 'ImportHistoryController@updated_models');
    Route::get('/import-history/created-models/{id}', 'ImportHistoryController@created_models');
    Route::get('/import-history/chunks/{import_history_id}', 'ImportHistoryController@chunks');
    Route::post('/import-history/rollback/{import_history_id}', 'ImportHistoryController@rollback');
    /* Lista de artículos creados con código repetido para el modal de resultado de importación. */
    Route::get('/import-history/repeated-code-articles/{import_history_id}', 'ImportHistoryController@repeated_code_articles');

    // Desglose completo de una corrida de recalculo de precios (el broadcast solo lleva el top).
    Route::get('/price-update-run/{id}/desglose', 'PriceUpdateRunController@desglose');
    Route::get('/import-history/{import_history_id}/conflicts', 'ImportHistoryController@conflicts');

    /*
     * Importación asistida por Claude IA (artículos, clientes, proveedores).
     *  - POST /ai-excel-import/analyze              : encola el análisis del Excel (grupo 291, prompt 02) y devuelve el uuid de la corrida
     *  - GET  /ai-excel-import/analysis/{uuid}      : consulta estado/resultado de la corrida encolada por /analyze o /get-recomendacion
     *  - POST /ai-excel-import/import               : lanza la importación con el mapeo confirmado por el usuario
     *  - POST /ai-excel-import/refresh-provider-stats : recalcula conteos de códigos existentes al cambiar proveedor (sin releer el Excel si ya hay un análisis previo, grupo 291 prompt 03)
     *  - POST /ai-excel-import/get-recomendacion    : encola la recomendación de configuración con el proveedor real confirmado (grupo 291, prompt 03) y devuelve el uuid de la corrida
     *  - GET  /ai-excel-import/analysis-en-curso    : corrida abierta del usuario (en curso, o terminada y sin ver) para recuperar el hilo tras cerrar la pestaña
     *  - POST /ai-excel-import/analysis/{uuid}/visto : marca la corrida como vista, para dejar de ofrecerla
     */
    Route::post('/ai-excel-import/analyze', 'AiExcelImportController@analyze');
    /*
     * Va ANTES de /analysis/{uuid}: si se declara después, "analysis-en-curso" no
     * matchea ninguna ruta propia y cae en el patrón de arriba solo si comparten
     * prefijo — no es el caso acá (son segmentos distintos), pero el orden explícito
     * evita que un futuro cambio de nombre las haga colisionar en silencio.
     */
    Route::get('/ai-excel-import/analysis-en-curso', 'AiExcelImportController@analysisEnCurso');
    Route::get('/ai-excel-import/analysis/{uuid}', 'AiExcelImportController@analysisStatus');
    Route::post('/ai-excel-import/analysis/{uuid}/visto', 'AiExcelImportController@marcarAnalisisVisto');
    Route::post('/ai-excel-import/import',  'AiExcelImportController@import');
    Route::post('/ai-excel-import/refresh-provider-stats', 'AiExcelImportController@refreshProviderStats');
    Route::post('/ai-excel-import/get-recomendacion', 'AiExcelImportController@getRecomendacion');

    Route::get('/online-price-type', 'OnlinePriceTypeController@index');

    Route::resource('/cupon', 'CuponController');

    Route::get('/mercado-pago/payment/{payment_id}', 'MercadoPagoController@payment');

    // OAuth de Mercado Pago (grupo 170, prompt 598): el comercio autenticado conecta/desconecta
    // su propia cuenta para cobrar. El callback (público, sin auth) se declara más abajo, fuera
    // de este grupo de middleware, porque es un redirect del navegador del comercio hacia este
    // backend y el comercio se identifica por el `state` persistido en connect, no por sesión.
    Route::get('integraciones/mercadopago/connect', 'MercadoPagoOAuthController@connect');
    Route::post('integraciones/mercadopago/disconnect', 'MercadoPagoOAuthController@disconnect');

    // OAuth de Zippin (grupo 171, prompt 599): el comercio autenticado conecta/desconecta su
    // propia cuenta para gestionar envíos. El callback (público, sin auth) se declara más abajo,
    // fuera de este grupo de middleware, mismo criterio que mercadopago/callback.
    Route::get('integraciones/zippin/connect', 'ZippinOAuthController@connect');
    Route::post('integraciones/zippin/disconnect', 'ZippinOAuthController@disconnect');

    Route::get('report/from-date/{from_date}/{until_date?}/{employee_id?}', 'CajaViejaController@reports');
    Route::get('chart/from-date/{from_date}/{until_date?}', 'CajaViejaController@charts');

    Route::resource('commission', 'CommissionController');
    // Grupo 268 · Prompt 03: el alias viejo (3 segmentos, sin moneda_id) TIENE que quedar
    // registrado ANTES que la ruta nueva (4 segmentos, con {until_date?} opcional) - si no, una
    // URL de 3 segmentos podria matchear la ruta nueva primero (el ultimo segmento opcional la
    // deja aceptar 3 o 4) e interpretar mal los parametros (moneda_id recibiendo la fecha).
    Route::get('seller-commission/{model_id}/{from_date}/{until_date}', 'SellerCommissionController@indexLegacy');
    Route::get('seller-commission/{model_id}/{moneda_id}/{from_date}/{until_date?}', 'SellerCommissionController@index');
    Route::post('seller-commission/saldo-inicial', 'SellerCommissionController@saldoInicial');
    Route::post('seller-commission/pago', 'SellerCommissionController@pago');
    Route::delete('seller-commission/{id}', 'SellerCommissionController@destroy');

    Route::resource('sale-type', 'SaleTypeController');

    Route::get('pagado-por/{model_name}/{model_id}/{debe_id}/{haber_id}', 'PagadoPorController@index');

    Route::resource('provider-order-extra-cost', 'ProviderOrderExtraCostController');

    Route::get('recipe/article-used-in-recipes/{article_id}', 'RecipeController@articleUsedInRecipes');

    Route::resource('task', 'TaskController');
    Route::put('task-finish/{id}', 'TaskController@finish');

    Route::get('inventory-linkage-scope', 'InventoryLinkageScopeController@index');
    Route::resource('inventory-linkage', 'InventoryLinkageController');

    Route::resource('article-property', 'ArticlePropertyController');

    Route::resource('article-property-type', 'ArticlePropertyTypeController');
    
    Route::resource('article-property-value', 'ArticlePropertyValueController');

    Route::post('article-variant', 'ArticleVariantController@store');
    Route::put('article-variant/{id}', 'ArticleVariantController@update');
    Route::delete('article-variant/{id}', 'ArticleVariantController@destroy');
    Route::put('article/{id}/variants-disponibilidad', 'ArticleVariantController@set_disponibilidad_masiva');

    Route::resource('payment-method-installment', 'PaymentMethodInstallmentController');



    // Articles Pre Import
    Route::get('articles-pre-import', 'ArticlesPreImportController@index');
    Route::get('articles-pre-import/from-date/{from_date}/{until_date?}', 'ArticlesPreImportController@index');
    Route::put('articles-pre-import/update-articles', 'ArticlesPreImportController@updateArticles');


    // Articles Pre Import Ranges
    Route::resource('article-pre-import-range', 'ArticlePreImportRangeController');


    // Unidades de medida
    Route::resource('unidad-medida', 'UnidadMedidaController');


    Route::resource('column-position', 'ColumnPositionController');
    // El where() es solo un guard de forma: valida que el segmento sea un identificador valido.
    // La lista completa de preference_type validos vive en TableColumnPreferenceController::assert_preference_type()
    // que aborta 404 para tipos invalidos. Esto evita duplicar la lista de tipos en dos lugares.
    Route::get('table-column-preference/{model_name}/{preference_type}', 'TableColumnPreferenceController@show')
        ->where('preference_type', '[a-z0-9_]+');
    Route::put('table-column-preference/{model_name}/{preference_type}', 'TableColumnPreferenceController@update')
        ->where('preference_type', '[a-z0-9_]+');

    Route::resource('table-column-preferences', 'TableColumnPreferenceCrudController');
    Route::get('pdf-column-options', 'PdfColumnOptionController@index');
    Route::get('pdf-column-options/{id}', 'PdfColumnOptionController@show');
    // Duplica un perfil de diseño de PDF con toda su configuración y columnas (pivots).
    Route::post('pdf-column-profiles/{id}/duplicate', 'PdfColumnProfileController@duplicate');
    Route::resource('pdf-column-profiles', 'PdfColumnProfileController');

    Route::get('etiqueta-medidas', 'EtiquetaMedidaController@index');
    Route::post('etiqueta-medidas', 'EtiquetaMedidaController@store');
    Route::delete('etiqueta-medidas/{id}', 'EtiquetaMedidaController@destroy');

    Route::resource('price-type-surchage', 'PriceTypeSurchageController');

    Route::get('google/custom-search/aumentar-contador', 'GoogleController@aumentar_contador_custom_search');
    Route::get('google/get-current', 'GoogleController@get_current');
    Route::post('google/batch-assign-images', 'GoogleController@batch_assign_images');

    // Diagnóstico de intentos de búsqueda de imagen automática (grupo 201): últimas corridas y detalle por corrida.
    Route::get('article-image-search-attempts/recent', 'ArticleImageSearchAttemptController@recent_batches');
    // Resumen reconstruido de una corrida (grupo 217, prompt 03): mismo objeto que el payload de Pusher.
    Route::get('article-image-search-attempts/summary/{batch_uuid}', 'ArticleImageSearchAttemptController@summary');
    Route::get('article-image-search-attempts/batch/{batch_uuid}', 'ArticleImageSearchAttemptController@by_batch');

    // Descripciones inteligentes: preview individual, guardado, batch masivo (job + Pusher) y revisión.
    Route::post('article-description-ai/preview/{article_id}', 'ArticleDescriptionAiController@preview');
    Route::post('article-description-ai/store/{article_id}', 'ArticleDescriptionAiController@store');
    Route::post('article-description-ai/batch-generate', 'ArticleDescriptionAiController@batch_generate');
    Route::get('article-description-ai/pending-review', 'ArticleDescriptionAiController@pending_review');
    Route::put('article-description-ai/approve/{id}', 'ArticleDescriptionAiController@approve');
    Route::delete('article-description-ai/discard/{id}', 'ArticleDescriptionAiController@discard');


    Route::post('payment-plan', 'PaymentPlanController@store');

    Route::resource('payment-plan-cuota', 'PaymentPlanCuotaController');
    Route::get('payment-plan-cuota/{estado}/from-date/{from_date?}/{until_date?}', 'PaymentPlanCuotaController@index');


    Route::resource('tienda-nube-order', 'TiendaNubeOrderController');
    Route::get('tienda-nube-order/from-date/{from_date?}/{until_date?}', 'TiendaNubeOrderController@index');

    Route::get('tienda-nube-order-status', 'TiendaNubeOrderStatusController@index');

    Route::get('moneda', 'MonedaController@index');

    Route::get('pais-exportacion', 'PaisExportacionController@index');

    // Route::resource('current-acount-payment-method-discount', 'CurrentAcountPaymentMethodDiscountController');

    Route::resource('provincia', 'ProvinciaController');

    Route::resource('provider-discount', 'ProviderDiscountController');

    Route::resource('client-reputation', 'ClientReputationController');

    Route::resource('expense-category', 'ExpenseCategoryController');

    Route::resource('meli-category', 'MeliCategoryController');
    Route::get('meli-category-predictor/{article_name}', 'MeliCategoryController@category_predictor');
    Route::get('meli-category-predictor/asignar-meli-category/{article_id}/{mercado_libre_category_id}', 'MeliCategoryController@asignar_meli_category');



    Route::resource('article-meli-attribute', 'ArticleMeliAttributeController');

    Route::get('meli-listing-type', 'MeliListingTypeController@index');
    Route::get('meli-buying-mode', 'MeliBuyingModeController@index');
    Route::get('meli-item-condition', 'MeliItemConditionController@index');

    Route::get('sync-to-meli-article/from-date/{from_date?}/{until_date?}', 'SyncToMeliArticleController@index');
    
    Route::get('sync-from-meli-article/from-date/{from_date?}/{until_date?}', 'SyncFromMeliArticleController@index');
    Route::get('sync-from-meli-article/{id}', 'SyncFromMeliArticleController@show');
    Route::post('sync-from-meli-article', 'SyncFromMeliArticleController@store');
    Route::delete('sync-from-meli-article/{id}', 'SyncFromMeliArticleController@destroy');
    
    Route::get('sync-from-meli-order/from-date/{from_date?}/{until_date?}', 'SyncFromMeliOrderController@index');
    Route::post('sync-from-meli-order', 'SyncFromMeliOrderController@store');

    Route::get('sale-channel', 'SaleChannelController@index');

    Route::resource('article-ubication', 'ArticleUbicationController');
    Route::put('article-ubication/article/{article_id}', 'ArticleUbicationController@update_article');

    Route::resource('stock-suggestion', 'StockSuggestionController');
    Route::post('stock-suggestion/{id}/create-deposit-movement', 'StockSuggestionController@create_deposit_movement');
    Route::post('stock-suggestion-article', 'StockSuggestionArticleController@ver_por_deposito');



    Route::get('sync-to-tn-article/from-date/{from_date?}/{until_date?}', 'SyncToTNArticleController@index');
    Route::get('sync-to-tn-article/failed-count', 'SyncToTNArticleController@failed_count');


    Route::resource('article-price-range', 'ArticlePriceRangeController');

    Route::resource('turno-caja', 'TurnoCajaController');

    Route::resource('resumen-caja', 'ResumenCajaController');
    Route::get('resumen-caja/from-date/{from_date?}/{until_date?}', 'ResumenCajaController@index');

    Route::resource('tag', 'TagController');

    Route::get('import-status', 'ImportStatusController@index');


    /*
    |--------------------------------------------------------------------------
    | Production V2 (Batches)
    |--------------------------------------------------------------------------
    | Convención:
    | - production-batches: CRUD de lotes
    | - production-batch-movements: CRUD de movimientos (store/destroy/show opcional)
    | - preview + update_inputs: acciones específicas
    */

    Route::resource('recipe', 'RecipeController');
    Route::post('recipe/search-article', 'RecipeController@search_article');


    // Lotes (CRUD común)
    Route::resource('production-batch', 'ProductionBatchController');
    Route::get('production-batch/from-date/{from_date?}/{until_date?}', 'ProductionBatchController@index');

    // Movimientos (resource para comunes)
    // Nota: si no querés index/show/update, podés limitarlo con only()
    Route::resource('production-batch-movement', 'ProductionBatchMovementController');

    // Preview de movimiento (para calcular insumos planificados y devolver la tablita editable)
    Route::post('production-batch-movement/preview', 'ProductionBatchMovementController@preview');


    // Editar consumos reales del movimiento (delta de stock)
    Route::put('production-batch-movement/inputs/{id}', 'ProductionBatchMovementController@update_inputs');

    Route::resource('recipe-route', 'RecipeRouteController');

    Route::resource('recipe-route-type', 'RecipeRouteTypeController');
    Route::resource('production-batch-status', 'ProductionBatchStatusController');

    Route::resource('production-batch-movement-type', 'ProductionBatchMovementTypeController');

    Route::resource('c-a-payment-method-type', 'CAPaymentMethodTypeController');




    Route::resource('sale-status', 'SaleStatusController');


});


// Bot WhatsApp para clientes finales
// Webhook público (Kapso lo llama sin auth)
Route::post('whatsapp-bot/webhook', 'WhatsappBotController@receive');

// Configuración autenticada (singleton por empresa; POST/PUT con o sin id para el ABM de empresa-spa)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('whatsapp-bot/config', 'WhatsappBotController@get_config');
    Route::post('whatsapp-bot/config', 'WhatsappBotController@update_config');
    Route::put('whatsapp-bot/config', 'WhatsappBotController@update_config');
    Route::put('whatsapp-bot/config/{id}', 'WhatsappBotController@update_config');
});

// Endpoints REST del módulo de chats de WhatsApp con clientes (grupo 137, Prompt 02),
// gateados por auth Sanctum + la extensión 'whatsapp' (ver ExtencionEmpresaWhatsappSeeder).
Route::middleware(['auth:sanctum', 'check_extencion_empresa:whatsapp'])->group(function () {
    Route::get('whatsapp-chats', 'WhatsappChatController@index');
    Route::get('whatsapp-chats/{id}/messages', 'WhatsappChatController@messages');
    Route::post('whatsapp-chats', 'WhatsappChatController@store');
    Route::post('whatsapp-chats/{id}/messages', 'WhatsappChatController@send_message');
    Route::post('whatsapp-chats/{id}/suggest', 'WhatsappChatController@suggest');
    Route::post('whatsapp-chats/{id}/summary', 'WhatsappChatController@summary');
    Route::put('whatsapp-chats/{id}/toggle-ai', 'WhatsappChatController@toggle_ai');
    Route::put('whatsapp-chats/{id}/link-client', 'WhatsappChatController@link_client');
    Route::put('whatsapp-chats/{id}/read', 'WhatsappChatController@mark_read');

    // Descarga del adjunto de un mensaje (misión whatsapp-sidebar-multimedia). Va ADENTRO de
    // este grupo y no suelta: los audios y las fotos de una conversación viven en el disco
    // `local`, fuera del docroot, justamente porque `/storage/{path}` de routes/web.php es
    // público y sin auth. Esta es la única puerta, y suma el chequeo de pertenencia del chat.
    Route::get('whatsapp-chats/{chat_id}/media/{message_id}', 'WhatsappChatController@media');

    // Envío de una foto o un audio desde el composer (misión whatsapp-sidebar-multimedia).
    // Multipart: `file` + `caption` opcional. Solo dentro de la ventana de 24 h de Meta; fuera
    // de ella devuelve 422 `fuera_de_ventana` SIN salir a Kapso, y el front ofrece plantillas.
    Route::post('whatsapp-chats/{id}/media', 'WhatsappChatController@send_media');

    // Envío de plantilla de Meta (grupo 137, Prompt 04): único camino cuando la ventana de 24 h está cerrada.
    Route::post('whatsapp-chats/{id}/send-template', 'WhatsappChatController@send_template');

    // Comprobante de venta enviado por el agente (grupo 137, Prompt 05): botón manual del modal de Ventas.
    Route::post('sales/{id}/send-whatsapp-agent', 'SaleController@send_whatsapp_agent');

    // CRUD de plantillas de WhatsApp (grupo 137, Prompt 04).
    Route::get('whatsapp-templates', 'WhatsappTemplateController@index');
    Route::post('whatsapp-templates', 'WhatsappTemplateController@store');
    Route::put('whatsapp-templates/{id}', 'WhatsappTemplateController@update');
    Route::delete('whatsapp-templates/{id}', 'WhatsappTemplateController@destroy');
    Route::put('whatsapp-templates/{id}/solicitar-alta', 'WhatsappTemplateController@solicitar_alta');

    // Confirmación humana de la respuesta del agente (grupo 137, misión whatsapp-agente).
    Route::put('whatsapp-chats/messages/{message_id}/confirm', 'WhatsappChatController@confirm_ai_message');
    Route::delete('whatsapp-chats/messages/{message_id}', 'WhatsappChatController@discard_ai_message');

    // Inyecta un mensaje entrante como si lo hubiera mandado el cliente (solo dueño). Corre
    // exactamente el mismo camino que el webhook real: ventana de 24 h, debounce y agente.
    // Throttle propio y bajo, contra los 300/min genéricos: cada llamada gasta un embedding
    // pago de OpenAI más una respuesta paga de Anthropic. 10 por minuto alcanza de sobra para
    // probar la agrupación de mensajes seguidos y le pone un techo real al gasto.
    // La respuesta que genera el agente para un entrante simulado NO sale por Kapso: la frena
    // WhatsappBotSendService::chat_en_simulacion(), y el porqué está escrito ahí.
    Route::post('whatsapp-bot/simulate-inbound', 'WhatsappBotController@simulate_inbound')
        ->middleware('throttle:10,1');
});

// Callback público Mercado Libre (notificaciones); sin auth Sanctum.
Route::post('meli/notifications', 'MeLiOrderController@receive_notification');

// Callback público OAuth Mercado Pago (grupo 170, prompt 598): redirect del navegador del
// comercio de vuelta a este backend tras autorizar en Mercado Pago. Sin auth Sanctum a
// propósito (el navegador no manda el bearer token del SPA en esta redirección); el comercio se
// identifica mediante el `state` aleatorio que connect persistió y que este endpoint valida.
Route::get('integraciones/mercadopago/callback', 'MercadoPagoOAuthController@callback');

// Callback público OAuth Zippin (grupo 171, prompt 599): redirect del navegador del comercio de
// vuelta a este backend tras autorizar en Zippin. Sin auth Sanctum a propósito (el navegador no
// manda el bearer token del SPA en esta redirección); el comercio se identifica mediante el
// `state` aleatorio que connect persistió y que este endpoint valida.
Route::get('integraciones/zippin/callback', 'ZippinOAuthController@callback');

// Grupo 211: export de articulos para flujos automatizados externos (n8n). Sin auth a proposito
// (decision de Lucas): el consumidor solo pega una URL. El comercio se identifica por el
// articles_export_key aleatorio del path, que ademas resuelve el user_id — nunca se acepta un
// user_id por parametro. Throttle propio para que una corrida mal configurada no tumbe la base.
Route::get('integraciones/articulos/{export_key}', 'Integraciones\ArticulosExportController@index')
        ->middleware('throttle:120,1');

// Sugerencias inteligentes de stock (v2): rutas de la vista propia, gateadas por auth Sanctum +
// la extensión 'sugerencias_inteligentes' (ver ExtencionSugerenciasInteligentesSeeder). Las tres
// rutas viejas del módulo (resource + create-deposit-movement + stock-suggestion-article) quedan
// arriba SIN gate a propósito: son el flujo de modales que conservan los clientes sin la
// extensión, y create-deposit-movement lo comparten los dos flujos.
Route::middleware(['auth:sanctum', 'check_extencion_empresa:sugerencias_inteligentes'])->group(function () {
    Route::get('stock-suggestion/{id}/articles', 'StockSuggestionController@articles');
    // Reintento del resumen IA desde la vista: el job no reintenta solo
    // ($tries = 1, D29), este botón es el camino de vuelta tras un 529.
    Route::post('stock-suggestion/{id}/resumen', 'StockSuggestionController@regenerar_resumen');
});

// Chat con el asistente de IA del negocio (misión chat-ia-y-modulo-ia), gateado por auth
// Sanctum + la extensión 'asistente_ia' (ver ExtencionAsistenteIaSeeder). La tenencia adentro
// del controller es por PERSONA (auth_user_id + user_id): el gate corta a quien no tiene la
// extensión, el filtro doble corta al empleado que quiera leer los chats del dueño. El POST
// del mensaje guarda y despacha el job de respuesta; el evento del canal privado avisa con
// ids y la SPA busca el texto acá (show_message, también usado por el polling de respaldo).
Route::middleware(['auth:sanctum', 'check_extencion_empresa:asistente_ia'])->group(function () {
    Route::get('ai-conversations', 'AiConversationController@index');
    Route::post('ai-conversations', 'AiConversationController@store');
    Route::delete('ai-conversations/{id}', 'AiConversationController@destroy');
    Route::get('ai-conversations/{id}/messages', 'AiConversationController@messages');
    Route::post('ai-conversations/{id}/messages', 'AiConversationController@send_message');
    Route::get('ai-conversations/{id}/messages/{message_id}', 'AiConversationController@show_message');
});

// Sugerencias de compra a proveedores (misión sugerencias-compra-proveedores), gateado por auth
// Sanctum + la extensión 'sugerencias_compras' (ver ExtencionSugerenciasComprasSeeder). Sin
// endpoint update: reprocesar es crear una corrida nueva (D10 del plan), no editar la existente.
Route::middleware(['auth:sanctum', 'check_extencion_empresa:sugerencias_compras'])->group(function () {
    Route::get('purchase-suggestion', 'PurchaseSuggestionController@index');
    Route::post('purchase-suggestion', 'PurchaseSuggestionController@store');
    Route::get('purchase-suggestion/{id}', 'PurchaseSuggestionController@show');
    Route::delete('purchase-suggestion/{id}', 'PurchaseSuggestionController@destroy');
    Route::get('purchase-suggestion/{id}/articles', 'PurchaseSuggestionController@articles');
    Route::post('purchase-suggestion/{id}/resumen', 'PurchaseSuggestionController@resumen');
    Route::post('purchase-suggestion/{id}/create-provider-order', 'PurchaseSuggestionController@create_provider_order');
});

// Motor de ofertas por cliente, gateado por Sanctum + 'motor_de_ofertas' (ExtencionMotorDeOfertasSeeder).
// 🔴 POST client-offer es el ÚNICO escritor de client_offers, la tabla que LEE LA TIENDA por SQL.
Route::middleware(['auth:sanctum', 'check_extencion_empresa:motor_de_ofertas'])->group(function () {
    Route::get('offer-suggestion', 'OfferSuggestionController@index');
    Route::post('offer-suggestion', 'OfferSuggestionController@store');
    Route::get('offer-suggestion/{id}', 'OfferSuggestionController@show');
    Route::delete('offer-suggestion/{id}', 'OfferSuggestionController@destroy');
    Route::get('offer-suggestion/{id}/lines', 'OfferSuggestionController@lines');
    Route::post('offer-suggestion/{id}/resumen', 'OfferSuggestionController@resumen');
    Route::get('client-offer', 'ClientOfferController@index');
    Route::post('client-offer', 'ClientOfferController@store');
    Route::delete('client-offer/{id}', 'ClientOfferController@destroy');
});

// Actividad de los clientes en el ecommerce (misión actividad-de-clientes-y-oferta-por-whatsapp),
// gateado por Sanctum + la extensión 'tracking_buyers' (ExtencionTrackingBuyersSeeder).
// Esa extensión es EL interruptor del tracking de las dos puntas: sin ella la tienda no manda un
// solo evento y la ingesta no escribe una sola fila, así que la pantalla no tendría nada que
// mostrar. Las dos rutas son de LECTURA PURA: no escriben en buyer_tracking_events ni en
// buyer_tracking_daily.
Route::middleware(['auth:sanctum', 'check_extencion_empresa:tracking_buyers'])->group(function () {
    Route::get('actividad-de-clientes', 'ActividadDeClientesController@show');
    // La lectura en criollo. SINCRÓNICA a propósito, no un job: el modal no puede quedar
    // esperando a un worker. Molde: WhatsappChatController@summary (llama a Anthropic desde el
    // request y devuelve el texto sin persistir nada).
    Route::post('actividad-de-clientes/resumen', 'ActividadDeClientesController@resumen');
});

// Cotización del dólar al iniciar sesión. Gateado por Sanctum + la extensión
// 'costo_en_dolares' (ExtencionSeeder, índice 33, tabla extencion_empresas).
// El gate por rol (is_admin) va adentro del controller: la extensión es de la
// EMPRESA y el rol es de la PERSONA, y son dos preguntas distintas.
Route::middleware(['auth:sanctum', 'check_extencion_empresa:costo_en_dolares'])->group(function () {
    Route::get('dolar-cotizacion', 'DolarCotizacionController@show');
    Route::post('dolar-cotizacion', 'DolarCotizacionController@store');
    Route::put('dolar-cotizacion/preferencias', 'DolarCotizacionController@preferencias');
});


// Plans
Route::get('plan', 'PlanController@index');
Route::get('plan-feature', 'PlanFeatureController@index');

// Sincronización entrante desde admin-api central:
// - publish-version: publicación de versión + notificaciones
// - demo-setup:      disparo remoto del setup de demo
// - user-setup:      disparo remoto del setup del sistema real (cliente que ya compró)
// demo-setup y user-setup sin middleware para integración directa desde admin-api sin API key.
Route::prefix('admin-sync')
    ->group(function () {
        Route::post('demo-setup', 'AdminSync\\DemoSetupController@store');
        Route::post('user-setup', 'AdminSync\\UserSetupController@store');
    });

// Ingreso a la demo con la sesion ya iniciada (token emitido por admin-api).
Route::post('demo/ingreso', 'DemoIngresoController@store');

Route::middleware('admin.api.key')
    ->prefix('admin-sync')
    ->group(function () {
        Route::put('update-default-version', 'AdminSync\\UpdateDefaultVersionController@update');
        Route::post('publish-version', 'AdminSync\\PublishVersionController@store');
        Route::post('support/messages', 'AdminSync\\SupportMessageController@store');
        Route::post('support/messages/read', 'AdminSync\\SupportMessageController@mark_read');
        Route::post('support/typing', 'AdminSync\\SupportTypingController@store');
        Route::post('support/tickets', 'AdminSync\\SupportTicketController@store');
        Route::put('support/tickets/{ticket_uuid}', 'AdminSync\\SupportTicketController@update');
        Route::get('employees', 'AdminSync\\EmployeesController@index');
        // Branding real del comercio (logo, color, nombre) para que admin-api arme el favicon/logo
        // del ecommerce sin depender de la tienda-api del cliente (ver grupo 208, prompt 01)
        Route::get('branding/{user_id?}', 'AdminSync\\BrandingController@show');
        // Mensualidad: consulta y actualización desde admin (capa opcional de sincronización, ver prompt 326)
        Route::get('mensualidad-info/{user_id?}', 'AdminSync\\MensualidadController@show');
        Route::put('mensualidad-update/{user_id?}', 'AdminSync\\MensualidadController@update');
        Route::post('ai-excel-import/analyze', 'AdminSync\\AiExcelImportController@analyze');
        Route::post('ai-excel-import/import', 'AdminSync\\AiExcelImportController@import');
        // Canal "sistema:" de WhatsApp: consulta de datos del owner (stock, ventas, facturas, clientes).
        Route::post('sistema-query', 'AdminSync\\SistemaQueryController@query_data');
        // Reemision/revocacion del token de ingreso a la demo (grupo 233, prompt 05). Va dentro
        // de este grupo con admin.api.key para que quede protegida sola el dia que Lucas prenda
        // el flag services.admin_api.require_api_key (hoy sigue apagado).
        Route::post('demo-token', 'AdminSync\\DemoTokenController@store');
    });

// Reporte de errores del SPA (sin auth — puede ocurrir antes del login)
Route::post('internal/report-front-error', [\App\Http\Controllers\Internal\ErrorReportController::class, 'store']);
