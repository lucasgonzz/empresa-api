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

Route::get('set-properties/{company_name}/{for_articles?}', 'HelperController@setProperties');
Route::get('check-images/{company_name}', 'HelperController@checkImages');
Route::get('clear-order-productions-current-acount/{company_name}', 'HelperController@clearOrderProductionCurrentAcount');

Route::get('get-persona', 'AfipWsController@getPersona');

// Registro
Route::post('user', 'UserController@store');

Route::post('user', 'UserController@store');

// PDF
Route::get('sale/pdf/{id}/{with_prices}', 'SaleController@pdf');
Route::get('sale/ticket-pdf/{id}', 'SaleController@ticketPdf');
Route::get('sale/afip-ticket-pdf/{id}', 'SaleController@afipTicketPdf');

Route::get('budget/pdf/{id}/{with_prices}', 'BudgetController@pdf');
Route::get('order-production/pdf/{id}/{with_prices}', 'OrderProductionController@pdf');
Route::get('order-production/articles-pdf/{id}', 'OrderProductionController@articlesPdf');

Route::get('/current-acount/pdf/{model_name}/{model_id}/{months_ago}', 'CurrentAcountController@pdfFromModel');
Route::get('/current-acount/pdf/{ids}/{model_name}', 'CurrentAcountController@pdf');

// Excel
Route::get('article/excel/export', 'ArticleController@export');
Route::get('client/excel/export', 'ClientController@export');

// Registrar Pago de usuario
Route::get('user/register-payment/{company_name}', 'CommonLaravel\UserController@registerPayment');
Route::get('caja', 'SaleController@caja');
Route::get('sale/charts/{from}/{to}', 'SaleController@charts');
