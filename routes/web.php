<?php

use Illuminate\Support\Facades\Route;

Route::post('login', 'CommonLaravel\AuthController@login');
Route::post('logout', 'CommonLaravel\AuthController@logout');

// Password Reset
Route::post('/password-reset/send-verification-code',
	'CommonLaravel\PasswordResetController@sendVerificationCode'
);
Route::post('/password-reset/check-verification-code',
	'CommonLaravel\PasswordResetController@checkVerificationCode'
);
Route::post('/password-reset/update-password',
	'CommonLaravel\PasswordResetController@updatePassword'
);


// ------------------------------------------------------------------------------


// Registrar nuevo usuario / negocio

Route::get('/register-user/{name}/{doc_number}/{company_name}/{iva_included}/{extencions_id}/{database?}', 'HelperController@register_user');

// api-pets.comerciocity.com/public/register-user/Mariano/123/Pets/0/6-9


// Archivos de intercambio PETS
Route::get('/leer-archivo-articulos', 'TsvFileController@leer_archivo_articulos');
Route::get('/leer-archivo-clientes', 'TsvFileController@leer_archivo_clientes');
Route::get('/leer-archivo-precios', 'TsvFileController@leer_archivo_precios');

// Cambiar BBDD
Route::get('/cambiar-bbdd/{company_name}/{bbbdd_destino}', 'BaseDeDatosController@copiar_modelos');

Route::get('/cambiar-bbdd/user/{company_name}/{bbbdd_destino}', 'BaseDeDatosController@copiar_usuario');

Route::get('/cambiar-bbdd/employees/{company_name}/{bbbdd_destino}', 'BaseDeDatosController@copiar_employees');

Route::get('/cambiar-bbdd/clients/{company_name}/{bbbdd_destino}/{from_id?}', 'BaseDeDatosController@copiar_clients');

Route::get('/cambiar-bbdd/providers/{company_name}/{bbbdd_destino}/{from_id?}', 'BaseDeDatosController@copiar_providers');

Route::get('/cambiar-bbdd/orders/{company_name}/{bbbdd_destino}', 'BaseDeDatosController@copiar_orders');

Route::get('/cambiar-bbdd/carts/{company_name}/{bbbdd_destino}', 'BaseDeDatosController@copiar_carts');

Route::get('/cambiar-bbdd/current-acounts/{company_name}/{bbbdd_destino}/{from_id?}', 'BaseDeDatosController@copiar_current_acounts');

Route::get('/cambiar-bbdd/provider-orders/{company_name}/{bbbdd_destino}/{from_id?}', 'BaseDeDatosController@copiar_provider_orders');

Route::get('/cambiar-bbdd/articulos/{company_name}/{bbbdd_destino}/{from_id?}', 'BaseDeDatosController@copiar_articulos');

Route::get('/cambiar-bbdd/ventas/{company_name}/{bbbdd_destino}/{from_id?}', 'BaseDeDatosController@copiar_ventas');

Route::get('/cambiar-bbdd/buyers/{company_name}/{bbbdd_destino}', 'BaseDeDatosController@copiar_buyers');

// Power Bi
Route::get('/power-bi/articulos', 'PowerBiController@articulos');

// Article Performance
Route::get('/article-performance/{company_name}/{meses_atras}', 'ArticlePerformanceController@setArticlesPerformance');

// Reportes
Route::get('/reportes/inventario/{company_name}/{periodo}', 'ReporteController@inventario');

Route::get('/reportes/clientes/{company_name}/{periodo}', 'ReporteController@clientes');

Route::get('/reportes/excel-articulos/{company_name}/{mes}', 'ReporteController@excel_articulos');

// Clientes Potenciales
Route::get('/cliente-potencial/{nombre_negocio}/{email}', 'ClientePotencialController@clientePotencial');


// Afip Get Persona 
Route::get('/get-persona/{cuit}', 'AfipConstanciaInscripcionController@get_constancia_inscripcion');



Route::get('/super-budget', 'SuperBudgetController@pdf');


Route::get('helpers/{method}/{param?}', 'HelperController@callMethod');

Route::get('recaulculate-cc-sales-debe/{client_id}', 'Helpers\CurrentAcountHelper@recalculateCurrentAcountsSalesDebe');

Route::get('articulos-repetidos/{provider_id}', 'HelperController@articulosRepetidos');
Route::get('check-insuficiente-amount/{company_name}', 'HelperController@checkCartArticlesInsuficienteAmount');
Route::get('rehacer-facturas', 'HelperController@rehacerFacturas');
Route::get('imagenes-a-jpg/{company_name}', 'HelperController@imagesWebpToJpg');
Route::get('buyers-sin-vincular/{company_name}', 'HelperController@getBuyerSinVincular');
Route::get('images-beta', 'HelperController@updateBetaImges');
Route::get('proveedores-eliminados/{company_name}', 'HelperController@reemplazarProveedoresEliminados');
Route::get('repetidos', 'HelperController@codigosRepetidos');
Route::get('set-clientes-oscar', 'HelperController@setClientesOscar');
Route::get('set-properties/{company_name}/{for_articles?}', 'HelperController@setProperties');
Route::get('check-images/{company_name}', 'HelperController@checkImages');
Route::get('clear-order-productions-current-acount/{company_name}', 'HelperController@clearOrderProductionCurrentAcount');
Route::get('delete-clients', 'HelperController@deleteClients');
Route::get('check-budgets-status/{company_name}', 'HelperController@checkBudgetStatus');
Route::get('clientes-repetidos/{company_name}', 'HelperController@clientesRepetidos');
Route::get('clients-sellers', 'HelperController@setClientSeller');
Route::get('recalculate-current-acounts/{company_name}/{client_id?}', 'HelperController@recaulculateCurrentAcounts');
Route::get('check-pagos/{model_name}/{model_id}/{si_o_si}', 'Helpers\CurrentAcountHelper@checkPagos');
Route::get('check-saldos/{model_name}/{id}', 'CurrentAcountController@check_saldos_y_pagos');
Route::get('clients-check-saldos/{model_name}', 'HelperController@checkClientsSaldos');
Route::get('set-comerciocity-extencion', 'HelperController@setComerciocityExtencion');
Route::get('set-online-configuration', 'HelperController@setOnlineConfiguration');

Route::get('prueba', function() {
	dd(App\Http\Controllers\Helpers\PaywayHelper::getPaymentMethodId('Visa'));
});

// Route::get('get-persona', 'AfipWsController@getPersona');

// HOME
// Registro
Route::post('user', 'UserController@store');

// Clientes
Route::get('home/clients', 'HomeController@clients');


// PDF
Route::get('sale/pdf/{id}/{with_prices}/{with_costs}/{precios_netos}/{confirmed?}', 'SaleController@pdf');
Route::get('sale/ticket-pdf/{id}', 'SaleController@ticketPdf');
Route::get('sale/afip-ticket-pdf/{id}', 'SaleController@afipTicketPdf');
Route::get('sale/delivered-articles-pdf/{id}', 'SaleController@deliveredArticlesPdf');


// Deposit Movement
Route::get('deposit-movement/pdf/{id}', 'DepositMovementController@pdf');


// Article
Route::get('article/pdf/{ids}', 'ArticleController@pdf');
Route::get('article/tickets-pdf/{ids}', 'ArticleController@ticketsPdf');
Route::get('article/bar-codes-pdf/{ids}', 'ArticleController@barCodePdf');
Route::get('article/list-pdf/{ids}', 'ArticleController@listPdf');

Route::get('budget/pdf/{id}/{with_prices}', 'BudgetController@pdf');
Route::get('order-production/pdf/{id}/{with_prices}', 'OrderProductionController@pdf');
Route::get('order-production/articles-pdf/{id}', 'OrderProductionController@articlesPdf');

Route::get('/current-acount/pdf/{model_name}/{model_id}/{months_ago}', 'CurrentAcountController@pdfFromModel');
Route::get('/current-acount/pdf/{ids}/{model_name}', 'CurrentAcountController@pdf');

Route::get('order/pdf/{id}/', 'OrderController@pdf');

// Excel
Route::get('article/excel/export', 'ArticleController@export');
Route::get('article-clients/excel/export', 'ArticleController@clientsExport');
Route::get('client/excel/export', 'ClientController@export');
Route::get('provider/excel/export', 'ProviderController@export');

// Registrar Pago de usuario
Route::get('user/register-payment/{company_name}', 'CommonLaravel\UserController@registerPayment');
Route::get('caja', 'SaleController@caja');
Route::get('sale/charts/{from}/{to}', 'SaleController@charts');


