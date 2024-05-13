<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('user', 'CommonLaravel\AuthController@get_user');

// Route::middleware('auth:sanctum')->get('/user', 'CommonLaravel\AuthController@get_user');

// Route::middleware(['set.user.database'])->group(function() {
// Route::middleware(['set.user.database', 'auth:sanctum'])->group(function() {
Route::middleware(['auth:sanctum'])->group(function() {

    // CommonLaravel 
    // ----------------------------------------------------------------------------------------------------
    // Generals
    Route::post('search/{model_name}/{_filters?}/{paginate?}', 'CommonLaravel\SearchController@search');
    Route::post('search-from-modal/{model_name}', 'CommonLaravel\SearchController@searchFromModal');
    Route::post('search/save-if-not-exist/{model_name}/{propertye}/{query}', 'CommonLaravel\SearchController@saveIfNotExist');
    Route::get('previus-day/{model_name}/{index}', 'CommonLaravel\PreviusDayController@previusDays');
    Route::get('previus-next/{model_name}/{index}', 'CommonLaravel\PreviusNextController@previusNext');
    Route::get('previus-next-index/{model_name}/{id}', 'CommonLaravel\PreviusNextController@getIndexPreviusNext');
    Route::put('update/{model_name}', 'CommonLaravel\UpdateController@update');
    Route::put('delete/{model_name}', 'CommonLaravel\DeleteController@delete');
    
    // User
    Route::get('user', 'CommonLaravel\AuthController@get_user');
    Route::put('user/{id}', 'UserController@update');
    Route::put('user-password', 'CommonLaravel\UserController@updatePassword');
    Route::post('user/last-activity', 'CommonLaravel\UserController@setLastActivity');

    // Employee
    Route::resource('employee', 'CommonLaravel\EmployeeController');

    // Permissions
    Route::get('permission', 'CommonLaravel\PermissionController@index');

    // Images
    Route::post('set-image/{prop}', 'CommonLaravel\ImageController@setImage');
    Route::delete('delete-image-prop/{model_name}/{id}/{prop_name}', 'CommonLaravel\ImageController@deleteImageProp');
    Route::delete('delete-image-model/{model_name}/{model_id}/{image_id}', 'CommonLaravel\ImageController@deleteImageModel');

    // Error
    Route::post('error', 'CommonLaravel\ErrorController@store');

    // ----------------------------------------------------------------------------------------------------


    Route::get('online-configuration', 'OnlineConfigurationController@index');
    Route::put('online-configuration/{id}', 'OnlineConfigurationController@update');
    Route::post('set-comercio-city-user', 'GeneralController@setComercioCityUser');
    Route::get('update-feature', 'UpdateFeatureController@index');

    Route::resource('article', 'ArticleController')->except(['index']);
    Route::get('article/index/from-status/{status?}', 'ArticleController@index');
    // Route::get('article/index/from-status/{last_updated}/{status?}', 'ArticleController@index');
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

    Route::resource('stock-movement', 'StockMovementController')->except(['index', 'show']);
    Route::get('stock-movement/{article_id}', 'StockMovementController@index');

    Route::get('price-change/{article_id}', 'PriceChangeController@index');

    Route::resource('sale', 'SaleController');
    Route::get('sale/from-date/{from_date?}/{until_date?}', 'SaleController@index');
    Route::put('sale/update-prices/{id}', 'SaleController@updatePrices');
    Route::get('sale/charts/{from}/{to}', 'SaleController@charts');
    Route::get('sales-ventas-sin-cobrar', 'SaleController@ventas_sin_cobrar');


    Route::get('sale-modifications/{sale_id}', 'SaleModificationController@index');
    
    // Afip tickets
    Route::post('afip-ticket', 'SaleController@makeAfipTicket');

    // Article Performance
    Route::get('article-performance/{meses_atras}', 'ArticlePerformanceController@index');

    Route::resource('brand', 'BrandController');
    Route::resource('category', 'CategoryController');
    Route::resource('condition', 'ConditionController');
    Route::resource('iva', 'IvaController');
    Route::resource('provider', 'ProviderController');
    Route::post('/provider/excel/import', 'ProviderController@import');

    Route::resource('provider-price-list', 'ProviderPriceListController');
    Route::resource('sub-category', 'SubCategoryController');
    Route::resource('iva-condition', 'IvaConditionController');
    Route::resource('location', 'LocationController');
    Route::resource('current-acount-payment-method', 'CurrentAcountPaymentMethodController');
    Route::resource('client', 'ClientController');
    Route::post('client/excel/import', 'ClientController@import');
    Route::get('client/get-afip-information-by-cuit/{cuit}', 'ClientController@get_afip_information_by_cuit');

    Route::resource('seller', 'SellerController');
    Route::resource('price-type', 'PriceTypeController');

    Route::resource('provider-order', 'ProviderOrderController');
    Route::get('provider-order/from-date/{from_date?}/{until_date?}', 'ProviderOrderController@index');
    Route::get('provider-order/days-to-advise/not-received', 'ProviderOrderController@indexDaysToAdvise');
    Route::resource('provider-order-status', 'ProviderOrderStatusController');
    Route::resource('provider-order-afip-ticket', 'ProviderOrderAfipTicketController');
    
    Route::resource('order', 'OrderController');
    Route::get('order/unconfirmed/models', 'OrderController@indexUnconfirmed');
    Route::get('order/from-date/{from_date?}/{until_date?}', 'OrderController@index');
    Route::put('order/update-status/{order_id}', 'OrderController@updateStatus');
    Route::put('order/cancel/{order_id}', 'OrderController@cancel');

    Route::get('me-li-order/from-date/{from_date?}/{until_date?}', 'MeLiOrderController@index');

    Route::resource('order-status', 'OrderStatusController');
    Route::resource('buyer', 'BuyerController');
    Route::resource('delivery-zone', 'DeliveryZoneController');

    Route::resource('payment-method', 'PaymentMethodController');

    Route::resource('payment-method-type', 'PaymentMethodTypeController');

    Route::resource('deposit', 'DepositController');
    Route::resource('size', 'SizeController');
    Route::resource('color', 'ColorController');
    Route::resource('article-discount', 'ArticleDiscountController');
    Route::resource('description', 'DescriptionController');
    Route::resource('discount', 'DiscountController');
    Route::resource('surchage', 'SurchageController');
    Route::post('service', 'ServiceController@store');
    // Route::resource('budget', 'BudgetController')->except(['index']);
    Route::resource('budget', 'BudgetController');
    Route::get('budget/from-date/{from_date}/{until_date?}', 'BudgetController@index');
    Route::resource('budget-status', 'BudgetStatusController');
    Route::resource('afip-information', 'AfipInformationController');

    Route::resource('production-movement', 'ProductionMovementController');
    Route::get('production-movement/from-date/{from_date?}/{until_date?}', 'ProductionMovementController@index');
    Route::get('production-movement/current-amounts/{article_id}', 'ProductionMovementController@currentAmounts');
    Route::get('production-movement/current-amounts/all-articles/all-recipes', 'ProductionMovementController@currentAmountsAllArticles');

    Route::resource('order-production', 'OrderProductionController');
    Route::resource('order-production-status', 'OrderProductionStatusController');
    Route::resource('recipe', 'RecipeController');
    Route::resource('address', 'AddressController');

    Route::resource('title', 'TitleController');

    Route::get('message/{buyer_id}', 'MessageController@fromBuyer');
    Route::get('message/set-read/{buyer_id}', 'MessageController@setRead');
    Route::post('message', 'MessageController@store');

    Route::resource('-', '-Controller');

    // CurrentAcounts
    Route::get('/current-acount/{model_name}/{model_id}/{months_ago}', 'CurrentAcountController@index');
    Route::post('/current-acount/pago', 'CurrentAcountController@pago');
    Route::post('/current-acount/nota-credito', 'CurrentAcountController@notaCredito');
    Route::post('/current-acount/nota-debito', 'CurrentAcountController@notaDebito');
    Route::post('/current-acount/saldo-inicial', 'CurrentAcountController@saldoInicial');
    Route::delete('/current-acount/{model_name}/{id}', 'CurrentAcountController@delete');
    Route::get('check-saldos/{model_name}/{id}', 'Helpers\CurrentAcountHelper@checkSaldos');


    // CurrentAcounts Cheques
    Route::get('/cheque', 'ChequeController@index');



    // Reportes
    Route::get('reportes/{mes_inicio?}/{mes_fin?}', 'ReporteController@index');

    // Checks
    Route::get('check/from-date/{from_date?}/{until_date?}', 'CheckController@index');

    Route::get('/import-history/{model_name}', 'ImportHistoryController@index');

    Route::get('/online-price-type', 'OnlinePriceTypeController@index');

    Route::resource('/cupon', 'CuponController');

    Route::get('/mercado-pago/payment/{payment_id}', 'MercadoPagoController@payment');

    Route::get('report/from-date/{from_date}/{until_date?}/{employee_id?}', 'CajaController@reports');
    Route::get('chart/from-date/{from_date}/{until_date?}', 'CajaController@charts');

    Route::resource('commission', 'CommissionController');
    Route::get('seller-commission/{model_id}/{from_date}/{until_date}', 'SellerCommissionController@index');
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

    Route::resource('payment-method-installment', 'PaymentMethodInstallmentController');



    // Articles Pre Import
    Route::get('articles-pre-import', 'ArticlesPreImportController@index');
    Route::get('articles-pre-import/from-date/{from_date}/{until_date?}', 'ArticlesPreImportController@index');
    Route::put('articles-pre-import/update-articles', 'ArticlesPreImportController@updateArticles');


    // Articles Pre Import Ranges
    Route::resource('article-pre-import-range', 'ArticlePreImportRangeController');


    // Unidades de medida
    Route::resource('unidad-medida', 'UnidadMedidaController');
});


// Plans
Route::get('plan', 'PlanController@index');
Route::get('plan-feature', 'PlanFeatureController@index');
