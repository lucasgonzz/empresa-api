<?php

use App\Models\Article;
use App\Services\MercadoLibre\CategoryService;
use App\Services\MercadoLibre\SetearCategoryNameService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * RUTAS DE MANTENIMIENTO, DEBUG Y ADMINISTRACIÓN INTERNA (misión cerrar-rutas-web-publicas, 10/10/2026)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * QUÉ ES: las rutas que no son de ningún comercio sino de quien mantiene la instalación — sondas de
 * debug, `helpers/{method}`, las copias entre bases, las altas viejas por formulario, la mensualidad, el
 * configurador de extensiones, integraciones sueltas sin dueño. Hasta esta misión vivían en `web.php`,
 * que se monta con el grupo `web` y NO tiene ninguna capa de autenticación: cualquiera que escribiera la
 * URL borraba clientes, copiaba bases o creaba un dueño. Se mudaron TAL CUAL (mismo método, URI, acción y
 * nombre); lo único que cambió es la puerta.
 *
 * QUIÉN LO ABRE: RouteServiceProvider carga este archivo con `['web', 'solo.mantenimiento']`, así que TODO
 * lo de acá pasa por App\Http\Middleware\RutaDeMantenimiento (leé su docblock). Se abre:
 *   - en la suite de tests (`APP_ENV=testing`);
 *   - con `RUTAS_DE_MANTENIMIENTO=true` en el .env (el entorno local de Lucas; NO por `APP_ENV=local`);
 *   - con la clave maestra: `.../extensiones?clave=<la clave>` una vez (redirige a la misma URL sin la
 *     clave y deja la sesión habilitada 2 horas), o el header `X-Clave-Mantenimiento` desde un script;
 *   - y SOLO el configurador de extensiones (el bloque del final, `solo.mantenimiento:demo`), también en
 *     una instancia de demo (`FOR_USER=demo`), porque la filmación del centro de recursos lo usa sin login.
 * Si no, 404: el mismo de una ruta que no existe.
 *
 * 🔴 UNA RUTA NUEVA DE ESTE TIPO (debug, mantenimiento, un helper, algo que se abre a mano y no es de un
 * comercio) VA ACÁ, no en `web.php`: acá queda cerrada sin acordarse de nada. El guardián
 * `tests/Feature/Seguridad/RutasPublicas/1_Ninguna_ruta_publica_sin_saberlo_Test` pone en rojo cualquier
 * ruta sin protección que no esté declarada pública con su motivo.
 *
 * ⚠️ Los controladores de estas rutas NO se tocaron: se cerraron por la puerta, no por adentro. Siguen
 * haciendo exactamente lo que hacían (incluido lo que está roto), así que abrir una con la clave tiene el
 * mismo efecto que antes.
 */


// ------------------------------------------------------------------------------------------------
// Debug y sondas
// ------------------------------------------------------------------------------------------------

Route::get('hola', function() {
    echo 'Hola';
});

Route::get('prueba', function() {
	dd(App\Http\Controllers\Helpers\PaywayHelper::getPaymentMethodId('Visa'));
});

Route::get('/afip-get-data', function () {
    
    $ct = new App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper();

    $ct->get_data();

    return response();
});

Route::get('/mercadolibre/category_for_article/{article_id}', function ($article_id) {
   
    $category_service = new CategoryService(config('app.APP_ENV'));

    $article = Article::find($article_id);

    $meli_category_id = $category_service->resolve_meli_category_for_article($article);
});

Route::get('/mercadolibre/setear-categorias/{article_id}', function ($article_id) {
   
    $category_service = new SetearCategoryNameService(config('app.APP_ENV'));

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


// ------------------------------------------------------------------------------------------------
// HelperController: el despachador de métodos y sus rutas sueltas
// ------------------------------------------------------------------------------------------------

Route::get('helpers/{method}/{param?}/{param_2?}', 'HelperController@callMethod');

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
Route::get('release-all-user-sessions', 'HelperController@release_all_user_sessions');
Route::get('check-budgets-status/{company_name}', 'HelperController@checkBudgetStatus');
Route::get('clientes-repetidos/{company_name}', 'HelperController@clientesRepetidos');
Route::get('clients-sellers', 'HelperController@setClientSeller');
Route::get('recalculate-current-acounts/{company_name}/{client_id?}', 'HelperController@recaulculateCurrentAcounts');
Route::get('check-pagos/{model_name}/{model_id}/{si_o_si}', 'Helpers\CurrentAcountHelper@checkPagos');
Route::get('check-saldos/{model_name}/{id}', 'CurrentAcountController@check_saldos_y_pagos');
Route::get('clients-check-saldos/{model_name}', 'HelperController@checkClientsSaldos');
Route::get('set-comerciocity-extencion', 'HelperController@setComerciocityExtencion');
Route::get('set-online-configuration', 'HelperController@setOnlineConfiguration');


// ------------------------------------------------------------------------------------------------
// Copias entre bases, el alta vieja de 2023 y los archivos de intercambio de PETS
// ------------------------------------------------------------------------------------------------

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

// Registrar nuevo usuario / negocio

Route::get('/register-user/{name}/{doc_number}/{company_name}/{iva_included}/{extencions_id}/{database?}', 'HelperController@register_user');

// api-pets.comerciocity.com/public/register-user/Mariano/123/Pets/0/6-9

// Archivos de intercambio PETS
Route::get('/leer-archivo-articulos', 'TsvFileController@leer_archivo_articulos');
Route::get('/leer-archivo-clientes', 'TsvFileController@leer_archivo_clientes');
Route::get('/leer-archivo-precios', 'TsvFileController@leer_archivo_precios');


// ------------------------------------------------------------------------------------------------
// Gemelas web de rutas que la SPA usa por api.php
// ------------------------------------------------------------------------------------------------

Route::get('/import-history/rollback/{import_history_id}', 'ImportHistoryController@rollback');

// consultar_comprobante($afip_ticket_id)
// Route::post('/consultar-afip-ticket/afip_ticket_id', 'AfipTicketController@consultar_comprobante');

Route::get('afip-ticket/consultar-comprobante/{afip_ticket_id}', 'AfipTicketController@consultar_comprobante');

// Afip Get Persona 
Route::get('/get-persona/{cuit}', 'AfipConstanciaInscripcionController@get_constancia_inscripcion');

// Route::get('get-persona', 'AfipWsController@getPersona');


// ------------------------------------------------------------------------------------------------
// Integraciones y reportes sueltos (n8n, Power BI, rendimiento, prospectos, caja)
// ------------------------------------------------------------------------------------------------

// Obtener todos los productos para N8N
Route::get('/n8n/productos-disponibles/{last_updated?}', function ($last_updated = null) {
    $query = Article::select('id', 'name', 'final_price', 'descripcion', 'stock')
                    ->with(['category:name', 'addresses:id,street', 'images']); // importante: optimizamos la carga


    // echo('last_updated: '.$last_updated);
    if (!is_null($last_updated)) {
        try {
            $parsed_last_updated = Carbon::createFromFormat('Y-m-d H:i:s', $last_updated);
            $query->where('updated_at', '>=', $parsed_last_updated);
        } catch (\Exception $e) {
            // Si el formato es inválido, no aplicamos filtro por updated_at.
        }
    }

    $articles = $query->get()
                      ->map(function ($article) {
                          return [
                              'id' => $article->id,
                              'name' => $article->name,
                              'final_price' => $article->final_price,
                              'descripcion' => $article->descripcion,
                              'stock' => $article->stock,
                              'categoria' => $article->category->name ?? null,
                              'addresses' => $article->addresses->map(function ($address) {
                                  return [
                                      'name' => $address->street,
                                      'stock' => $address->pivot->amount ?? null,
                                  ];
                              })->values(),
                              // 'images' => $article->images,
                              'images' => $article->images->map(function ($image) {
                                  return [
                                      'hosting_url' => $image->hosting_url,
                                  ];
                              })->values(),
                          ];
                      });
    return response()->json(['articles' => $articles]);
});
// https://api-masquito.comerciocity.com/public/n8n/productos-disponibles

// Power Bi
Route::get('/power-bi/articulos', 'PowerBiController@articulos');

// Article Performance
Route::get('/article-performance/{company_name}/{meses_atras}', 'ArticlePerformanceController@setArticlesPerformance');

// Clientes Potenciales
Route::get('/cliente-potencial/{nombre_negocio}/{email}', 'ClientePotencialController@clientePotencial');

Route::get('/super-budget', 'SuperBudgetController@pdf');

Route::get('caja', 'SaleController@caja');


// ------------------------------------------------------------------------------------------------
// Altas y plan (los formularios viejos: hoy las altas van por api/admin-sync/*)
// ------------------------------------------------------------------------------------------------

Route::get('/demo-setup', 'DemoSetupController@form')->name('demo.form');
Route::post('/demo-setup', 'DemoSetupController@setup')->name('demo.setup');

Route::get('/user-setup', 'UserSetupController@form')->name('user.form');
Route::post('/user-setup', 'UserSetupController@setup')->name('user.setup');

// HOME
// Registro
Route::post('user', 'UserController@store');

// Clientes
Route::get('home/clients', 'HomeController@clients');

// Route::get('/user/payment-expired-at/{user_id?}', function() {
//     echo 'asd';
// });
Route::get('/mensualidad/{user_id?}', 'UserPaymentExpiredAtController@edit')->name('users.payment_expired_at.edit');

Route::put('/users/payment-expired-at/{user_id}', 'UserPaymentExpiredAtController@update')->name('users.payment_expired_at.update');

// Registrar Pago de usuario
Route::get('user/register-payment/{company_name}', 'CommonLaravel\UserController@registerPayment');


// ------------------------------------------------------------------------------------------------
// Configurador de extensiones: la única parte de este archivo que se abre también en las demos
// ------------------------------------------------------------------------------------------------

/*
 * `withoutMiddleware` + `middleware(':demo')`: se CAMBIA la guarda del archivo por su variante con el
 * parámetro `demo`, no se le suma. Si quedaran las dos, la de sin parámetro cortaría primero con un 404
 * en la demo y la filmación del centro de recursos (que entra sin login, `_video/centro9`) dejaría de
 * andar. El GET y el POST van juntos a propósito: el formulario postea a `users.extencions.update`.
 * Fuera de una demo, piden la clave como todo lo demás.
 */
Route::withoutMiddleware('solo.mantenimiento')
    ->middleware('solo.mantenimiento:demo')
    ->group(function () {
        /*
         * Ruta corta al configurador de extensiones del usuario de config('app.USER_ID') (misión 54).
         * La de abajo se mantiene porque acepta {user_id} y esa forma se sigue necesitando.
         */
        Route::get('/extensiones', 'UserExtencionController@edit')->name('extensiones');
        Route::get('/user/extencions/edit/{user_id?}', 'UserExtencionController@edit')->name('users.extencions.edit');
        Route::post('/users/{user_id}/extencions', 'UserExtencionController@update')->name('users.extencions.update');
    });
