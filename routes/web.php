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

Route::get('set-clientes-oscar', 'HelperController@setClientesOscar');
Route::get('set-properties/{company_name}/{for_articles?}', 'HelperController@setProperties');
Route::get('check-images/{company_name}', 'HelperController@checkImages');
Route::get('clear-order-productions-current-acount/{company_name}', 'HelperController@clearOrderProductionCurrentAcount');
Route::get('delete-clients', 'HelperController@deleteClients');
Route::get('check-budgets-status/{company_name}', 'HelperController@checkBudgetStatus');
Route::get('clientes-repetidos/{company_name}', 'HelperController@clientesRepetidos');
Route::get('clients-sellers', 'HelperController@setClientSeller');
Route::get('recalculate-current-acounts/{company_name}', 'HelperController@recaulculateCurrentAcounts');
Route::get('check-pagos/{model_name}/{model_id}/{si_o_si}', 'Helpers\CurrentAcountHelper@checkPagos');
Route::get('check-saldos/{model_name}/{id}', 'Helpers\CurrentAcountHelper@checkSaldos');
Route::get('clients-check-saldos/{model_name}', 'Helpers\CurrentAcountHelper@checkClientsSaldos');
Route::get('set-comerciocity-extencion', 'HelperController@setComerciocityExtencion');
Route::get('set-online-configuration', 'HelperController@setOnlineConfiguration');

Route::get('prueba', function() {
	echo Carbon\Carbon::today()->addDays(30)->format('Ymd');
});

Route::get('get-persona', 'AfipWsController@getPersona');

// HOME
// Registro
Route::post('user', 'UserController@store');

// Clientes
Route::get('home/clients', 'HomeController@clients');


// PDF
// Route::get('sale/pdf/{id}/{with_prices}', 'SaleController@pdf');
Route::get('sale/pdf/{id}/{with_prices}/{with_costs}', 'SaleController@pdf');
Route::get('sale/ticket-pdf/{id}', 'SaleController@ticketPdf');
Route::get('sale/afip-ticket-pdf/{id}', 'SaleController@afipTicketPdf');
Route::get('sale/delivered-articles-pdf/{id}', 'SaleController@deliveredArticlesPdf');

Route::get('budget/pdf/{id}/{with_prices}', 'BudgetController@pdf');
Route::get('order-production/pdf/{id}/{with_prices}', 'OrderProductionController@pdf');
Route::get('order-production/articles-pdf/{id}', 'OrderProductionController@articlesPdf');

Route::get('/current-acount/pdf/{model_name}/{model_id}/{months_ago}', 'CurrentAcountController@pdfFromModel');
Route::get('/current-acount/pdf/{ids}/{model_name}', 'CurrentAcountController@pdf');

Route::get('order/pdf/{id}/', 'OrderController@pdf');

// Excel
Route::get('article/excel/export', 'ArticleController@export');
Route::get('client/excel/export', 'ClientController@export');

// Registrar Pago de usuario
Route::get('user/register-payment/{company_name}', 'CommonLaravel\UserController@registerPayment');
Route::get('caja', 'SaleController@caja');
Route::get('sale/charts/{from}/{to}', 'SaleController@charts');


