<?php

use App\Models\Article;
use App\Services\MercadoLibre\CategoryService;
use App\Services\MercadoLibre\SetearCategoryNameService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// consultar_comprobante($afip_ticket_id)
// Route::post('/consultar-afip-ticket/afip_ticket_id', 'AfipTicketController@consultar_comprobante');


// Obtener todos los productos para N8N
Route::get('/n8n/productos-disponibles', function () {
    // $articles = Article::all();
    $articles = Article::select('id', 'name', 'final_price', 'descripcion')
                        ->with('category:name') // importante: optimizamos la carga
                        ->take(10000)
                        ->get()
                        ->map(function ($article) {
                            return [
                                'id' => $article->id,
                                'name' => $article->name,
                                'final_price' => $article->final_price,
                                'descripcion' => $article->descripcion,
                                'categoria' => $article->category->name ?? null,
                            ];
                        });
    return response()->json(['articles' => $articles]);
});
// https://api-masquito.comerciocity.com/public/n8n/productos-disponibles




// Paso 1: Redirige al usuario a Mercado Libre para autorizar la app
Route::get('/mercadolibre/auth', function () {
    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => env('MERCADO_LIBRE_CLIENT_ID'),
        'redirect_uri' => env('MERCADO_LIBRE_REDIRECT_URI'),
    ]);

    return redirect("https://auth.mercadolibre.com.ar/authorization?$query");
});



// Paso 2: Callback de Mercado Libre, recibe el code y solicita el access token
Route::get('/mercadolibre/callback', function (\Illuminate\Http\Request $request) {
    $code = $request->query('code');

    echo 'Vinculacion exitosa. Codigo de autorizacion: '.$code;
    return;

    if (!$code) {
        return response()->json(['error' => 'No se recibió el parámetro code'], 400);
    }

    $response = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => env('MERCADO_LIBRE_CLIENT_ID'),
        'client_secret' => env('MERCADO_LIBRE_CLIENT_SECRET'),
        'code' => $code,
        'redirect_uri' => env('MERCADO_LIBRE_REDIRECT_URI'),
    ]);

    if (!$response->successful()) {
        return response()->json([
            'error' => 'No se pudo obtener el access token',
            'details' => $response->json()
        ], 400);
    }

    $data = $response->json();

    // Mostrar los tokens y datos del usuario de ML
    return response()->json([
        'access_token' => $data['access_token'],
        'refresh_token' => $data['refresh_token'],
        'expires_in' => $data['expires_in'],
        'user_id' => $data['user_id'],
    ]);
});

// Para recibir notificaciones desde MercadoLibre
Route::post('/mercadolibre/webhook', 'MercadoPagoController@webhook');

Route::get('/mercadolibre/category_for_article/{article_id}', function ($article_id) {
   
    $category_service = new CategoryService(env('USER_ID'));

    $article = Article::find($article_id);

    $meli_category_id = $category_service->resolve_meli_category_for_article($article);
});


Route::get('/mercadolibre/setear-categorias/{article_id}', function ($article_id) {
   
    $category_service = new SetearCategoryNameService(env('USER_ID'));

    $category_service->setear_category_name($article_id);
});

Route::get('/mercadolibre/metodos-envio', function () {
   
    $response = Http::get("https://api.mercadolibre.com/sites/MLA/shipping_methods");

    $metodos = $response->json(); // decodifica automáticamente a array

    echo '<h1>Métodos de Envío de MercadoLibre</h1>';
    echo '<ul>';
    foreach ($metodos as $metodo) {
        foreach ($metodos as $metodo) {
        echo '<li><ul>';
        foreach ($metodo as $clave => $valor) {
            echo '<li>';
            echo '<strong>' . ucfirst($clave) . ':</strong> ';
            
            // Mostrar arrays como lista separada
            if (is_array($valor)) {
                echo implode(', ', $valor);
            } elseif (is_null($valor)) {
                echo 'N/A';
            } else {
                echo $valor;
            }

            echo '</li>';
        }
        echo '</ul><hr></li>';
    }
    }
    echo '</ul>';

    return;
});



// Recursos Afip Webservices


Route::post('/tiendanube/webhook/store_redact', function () {

    $afip_wsaa = new App\Http\Controllers\Helpers\Afip\AfipWSAAHelper($this->testing, 'wsfex');
    $afip_wsaa->checkWsaa();


    $helper = new App\Http\Controllers\Helpers\Afip\AfipFexHelper($this->afip_ticket, $this->testing);
    $helper->get_incoterms();
});

        


// Tienda Nube
Route::post('/tiendanube/webhook/store_redact', function () {
    return response()->json(['status' => 'ok']);
});

Route::post('/tiendanube/webhook/customers_redact', function () {
    return response()->json(['status' => 'ok']);
});

Route::post('/tiendanube/webhook/customers_data_request', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/tiendanube/callback', function(\Illuminate\Http\Request $request) {
    return response()->json([
        'code' => $request->query('code'),
        'state' => $request->query('state')
    ]);
});





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

Route::get('/storage/{path}', function ($path) {
    $full_path = storage_path('app/public/' . $path);

    if (!file_exists($full_path)) {
        abort(404);
    }

    return response()->file($full_path);
})->where('path', '.*');

Route::get('/imported-files/{path}', function ($path) {
    $full_path = storage_path('app/imported_files/' . $path);

    Log::info($full_path);
    if (!file_exists($full_path)) {
        abort(404);
    }

    return response()->file($full_path);
})->where('path', '.*');



Route::get('/afip-get-data', function () {
    
    $ct = new App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper();

    $ct->get_data();

    return response();
});


Route::get('/demo-setup', 'DemoSetupController@form')->name('demo.form');
Route::post('/demo-setup', 'DemoSetupController@setup')->name('demo.setup');

Route::get('/user-setup', 'UserSetupController@form')->name('user.form');
Route::post('/user-setup', 'UserSetupController@setup')->name('user.setup');

Route::get('/user/extencions/edit/{user_id?}', 'UserExtencionController@edit')->name('users.extencions.edit');
Route::post('/users/{user_id}/extencions', 'UserExtencionController@update')->name('users.extencions.update');



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


Route::get('helpers/{method}/{param?}/{param_2?}', 'HelperController@callMethod');

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
Route::get('sale/ticket-raw/{id}', 'SaleController@ticketRaw');
Route::get('sale/sale-ticket-pdf/{id}', 'SaleController@saleTicketPdf');
Route::get('sale/afip-ticket-pdf/{id}', 'SaleController@afipTicketPdf');
Route::get('sale/afip-ticket-a4-pdf/{id}', 'SaleController@afipTicketA4Pdf');
Route::get('sale/delivered-articles-pdf/{id}', 'SaleController@deliveredArticlesPdf');
Route::get('sale/etiqueta-envio/pdf/{sale_id}', 'SaleController@etiqueta_envio');


Route::get('client/pdf', 'ClientController@pdf');

Route::get('road-map/pdf/{id}', 'RoadMapController@pdf');


// Deposit Movement
Route::get('deposit-movement/pdf/{id}', 'DepositMovementController@pdf');


// Article
Route::get('article/pdf/{ids}/{moneda_id?}', 'ArticleController@pdf');
Route::get('article/tickets-pdf/{ids}', 'ArticleController@ticketsPdf');
Route::get('article/bar-codes-pdf/{ids}', 'ArticleController@barCodePdf');
Route::get('article/bar-codes-etiquetas-pdf/{ids}', 'ArticleController@barCodeEtiquetasPdf');
Route::get('article/list-pdf/{ids}', 'ArticleController@listPdf');
Route::get('article/pdf-personalizado', 'ArticleController@pdfPersonalizado');


Route::get('articles-stock-minimo/excel', 'InventoryPerformanceController@stock_minimo_excel');

Route::get('budget/pdf/{id}/{with_prices}/{with_images}', 'BudgetController@pdf');
Route::get('order-production/pdf/{id}/{with_prices}', 'OrderProductionController@pdf');
Route::get('order-production/articles-pdf/{id}', 'OrderProductionController@articlesPdf');

#Route::get('/current-acount/pdf/{credit_account_id}/{months_ago}', 'CurrentAcountController@pdfFromModel');
Route::get('/current-acount/pdf/{credit_account_id}/{months_ago}/{type?}', 'CurrentAcountController@pdfFromModel');
Route::get('/current-acount/pdf/{id}', 'CurrentAcountController@pdf');

Route::get('order/pdf/{id}/', 'OrderController@pdf');

// Excel
Route::get('article/excel/export', 'ArticleController@export');
Route::get('article-clients/excel/export/{price_type_id?}', 'ArticleController@clientsExport');
Route::get('article-base/excel/export', 'ArticleController@baseExport');
Route::get('client/excel/export', 'ClientController@export');
Route::get('provider/excel/export', 'ProviderController@export');
Route::get('apertura-caja/excel/export/{id}', 'AperturaCajaController@export');

Route::get('/provider-orders/export/{id}', function ($id) {
    return Maatwebsite\Excel\Facades\Excel::download(new App\Exports\ProviderOrderExport($id), 'pedido_proveedor_'.$id.'.xlsx');
});

Route::get('sales/excel/export/{from_date}/{until_date?}', 'SaleController@excel_export');



// Registrar Pago de usuario
Route::get('user/register-payment/{company_name}', 'CommonLaravel\UserController@registerPayment');
Route::get('caja', 'SaleController@caja');
Route::get('sale/charts/{from}/{to}', 'SaleController@charts');



Route::get('afip-txt/{mes_inicio}/{mes_fin}', 'AfipController@exportVentas');
Route::get('afip-txt-alicuotas/{mes_inicio}/{mes_fin}', 'AfipController@exportAlicuotasTxt');



Route::get('acopio-article-delivery/{id}', 'AcopioArticleDeliveryController@pdf');


Route::get('resumen-caja/pdf/{id}', 'ResumenCajaController@pdf');
