<?php

use App\Http\Controllers\Helpers\StoragePathHelper;
use App\Services\PlatformConnector\PlatformConnectorOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Callback OAuth Mercado Libre: intercambia `code` y persiste en `platform_connectors`.
 * Las credenciales de app viven en el conector (ABM), no en .env.
 */
Route::get('/platform-connector/mercadolibre/callback', function (\Illuminate\Http\Request $request) {
    $service = new PlatformConnectorOAuthService();

    return $service->handle_mercado_libre_callback($request);
});

// Para recibir notificaciones desde MercadoLibre
Route::post('/mercadolibre/webhook', 'MercadoPagoController@webhook');

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

/**
 * Callback OAuth Tienda Nube: intercambia `code` JSON contra `/apps/authorize/token`.
 * Registrar en la app de TN la URL pública equivalente a esta ruta (p. ej. `.../api/public/platform-connector/tiendanube/callback` según el host).
 */
Route::get('/platform-connector/tiendanube/callback', function (\Illuminate\Http\Request $request) {
    $service = new PlatformConnectorOAuthService();

    return $service->handle_tienda_nube_callback($request);
});





Route::post('login', 'CommonLaravel\AuthController@login');
Route::post('login-forzado', 'CommonLaravel\AuthController@login_forzado');
Route::post('logout', 'CommonLaravel\AuthController@logout');
Route::post('version-session-token', 'CommonLaravel\AuthController@create_version_session_token')
    ->middleware('auth');
Route::post('login-from-version-session-token', 'CommonLaravel\AuthController@login_from_version_session_token');

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

/*
 * Las tres rutas de abajo son PUBLICAS (routes/web.php se monta con el grupo 'web', que no tiene
 * ninguna capa de autenticacion) y sirven archivos del disco a partir de un parametro del usuario.
 * Hasta el 16/8/2026 concatenaban ese parametro directo a storage_path(), asi que un "../" alcanzaba
 * para leer cualquier archivo del servidor, empezando por el .env.
 *
 * El confinamiento vive en StoragePathHelper y se hace sobre realpath(), no sobre el string: un
 * chequeo textual de ".." no ve los symlinks. Leer el docblock de esa clase antes de tocar esto.
 *
 * El {path} sigue aceptando '.*' a proposito: los consumidores legitimos usan subcarpetas anidadas
 * -- articles_images/zip/<desc>/<desc>.jpg que arma el comando set_article_images, y
 * support_messages/<ticket_id>/<hash>.ext de los adjuntos del chat de soporte. Achicar el regex
 * romperia eso y no es donde va la defensa.
 *
 * Y responden 404, nunca 403: un 403 le confirma al atacante que el archivo existe.
 */
/*
 * Las tres rutas comparten este closure a proposito, en vez de repetir el cuerpo tres veces.
 * El motivo es concreto y ya paso: cuando el bug se reporto, se reportaron DOS de las tres rutas.
 * La tercera (/exported-files/) tenia la misma forma, el mismo defecto y nadie la vio, justamente
 * porque eran tres copias del mismo cuerpo que hay que acordarse de mantener juntas. Con un solo
 * cuerpo, la proxima ruta de esta familia no puede quedar atras sin que se note.
 *
 * @param  string    $etiqueta   Nombre de la ruta, para el log.
 * @param  string    $base_dir   Directorio permitido, absoluto.
 * @param  string    $path       Path pedido por el usuario.
 * @param  callable  $servir     Recibe el path resuelto y devuelve la respuesta.
 * @return \Symfony\Component\HttpFoundation\Response
 */
$servir_archivo_confinado = function ($etiqueta, $base_dir, $path, $servir) {
    $resultado = StoragePathHelper::inspeccionar($base_dir, $path);

    /*
     * Solo se loguea el intento de escape, no cada 404. Antes /imported-files/ tenia un
     * Log::info($full_path) que escribia la ruta absoluta de CADA descarga legitima: ruido en el
     * log y, de paso, el layout del servidor anotado en un archivo que se comparte para
     * diagnosticar otras cosas.
     *
     * El path se recorta a 255 caracteres porque el ->where('path', '.*') no le pone techo y un
     * pedido de varios miles de bytes se escribiria entero, tantas veces como el atacante quiera.
     */
    if (StoragePathHelper::es_intento_de_escape($resultado['motivo'])) {
        Log::warning($etiqueta.': intento de salir del directorio permitido', [
            'path_pedido' => mb_substr((string) $path, 0, 255),
            'motivo'      => $resultado['motivo'],
            'ip'          => request()->ip(),
        ]);
    }

    if (is_null($resultado['path'])) {
        abort(404);
    }

    /*
     * El try/catch no es decorativo: entre el realpath() de la verificacion y este servido hay una
     * ventana en la que el archivo puede desaparecer, y es un caso real en /exported-files/, donde
     * los exportados son temporales que una limpieza puede borrar mientras alguien los descarga.
     * Sin esto, response()->file() tira FileNotFoundException -> HTTP 500, y la diferencia entre
     * 500 y 404 vuelve a ser el oraculo de existencia que este arreglo viene a sacar. Encima el
     * mensaje de la excepcion lleva la ruta absoluta al log, que es lo otro que se saco aca.
     */
    try {
        return $servir($resultado['path']);
    } catch (\Exception $e) {
        /*
         * Se loguea porque este catch convierte en 404 cualquier fallo del servido, no solo el
         * archivo que desaparecio: si alguna vez un cliente reporta "veo 404 en un archivo que
         * existe", sin esta linea no queda ningun rastro para diagnosticarlo.
         *
         * Y se atrapa \Exception y no \Throwable a proposito: un \Error es un bug de programacion y
         * tiene que seguir saliendo como 500, no enmascararse de 404.
         */
        Log::warning($etiqueta.': fallo el servido de un archivo ya verificado', [
            'archivo' => $resultado['path'],
            'error'   => $e->getMessage(),
        ]);

        abort(404);
    }
};

Route::get('/storage/{path}', function ($path) use ($servir_archivo_confinado) {
    /*
     * Esta ruta es el fallback para las instalaciones SIN el symlink public/storage. Donde el
     * symlink existe, el servidor web sirve el archivo antes de llegar a index.php y esto no corre
     * nunca; en hosting compartido, donde no se puede crear el symlink, es lo unico que sirve las
     * imagenes de articulos. Por eso no se borra.
     */
    return $servir_archivo_confinado('storage', storage_path('app/public'), $path, function ($archivo) {
        return response()->file($archivo);
    });
})->where('path', '.*');

Route::get('/imported-files/{path}', function ($path) use ($servir_archivo_confinado) {
    return $servir_archivo_confinado('imported-files', storage_path('app/imported_files'), $path, function ($archivo) {
        return response()->file($archivo);
    });
})->where('path', '.*');

Route::get('/exported-files/{path}', function ($path) use ($servir_archivo_confinado) {
    // Se expone archivo exportado para descarga desde notificación global.
    return $servir_archivo_confinado('exported-files', storage_path('app/exported-files'), $path, function ($archivo) {
        return response()->download($archivo);
    });
})->where('path', '.*');



// Reportes
/*
 * Reportes de un comercio por su nombre (sumados a la misión pdf-de-venta-publico en la
 * verificación del 10/10/2026): hasta acá cualquiera los bajaba escribiendo el company_name en la
 * URL. Misma regla que el resto de las descargas (DescargaDelComercio), con el dueño resuelto por
 * `company_name` igual que ReportePdf y ArticleSalesExport: sesión de ese comercio, o la ventana de
 * transición de ese dueño; si no, o si no hay comercio con ese nombre, 404. Sin token: no se
 * comparten fuera del sistema.
 */
Route::middleware('descarga.comercio:comercio,company_name')->group(function () {
    Route::get('/reportes/inventario/{company_name}/{periodo}', 'ReporteController@inventario');

    Route::get('/reportes/clientes/{company_name}/{periodo}', 'ReporteController@clientes');

    Route::get('/reportes/excel-articulos/{company_name}/{mes}', 'ReporteController@excel_articulos');
});

/*
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * PDF Y EXPORTS: NINGUNA RUTA DE ACÁ ABAJO ES PÚBLICA (misión pdf-de-venta-publico, 10/10/2026)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Todas pasan por el middleware `descarga.comercio` (App\Http\Middleware\DescargaDelComercio):
 * se sirven con la sesión del comercio dueño del recurso, con un `?t=<token>` válido para ESE
 * recurso (solo venta, presupuesto, cuenta corriente, movimiento y pedido de la tienda, los que se
 * comparten fuera del sistema), o durante la ventana de transición de links viejos
 * (`users.pdf_links_legacy_until` del dueño, registrada con Log::info). Si no, 404.
 *
 * Hasta esta misión el grupo `web` no tenía ninguna capa de autenticación y los controladores hacen
 * `find($id)` pelado: en una base compartida, desde el frente de cualquier comercio se leían los
 * comprobantes de todos.
 *
 * 🔴 UNA RUTA NUEVA DE PDF O EXPORT VA ADENTRO DE UNO DE ESTOS GRUPOS, con su tipo y su parámetro
 * (o `sesion` si no tiene id). La lista de rutas protegidas está fijada en
 * tests/Feature/PdfDeVentaPublico: una ruta de PDF que quede afuera la marca ese test.
 *
 * Quedan afuera a propósito: `client/pdf` (exige sesión en su controlador desde el 5/10/2026),
 * `storage`/`imported-files`/`exported-files` (confinados por StoragePathHelper) y todo lo que no es
 * PDF ni export. `super-budget` (y `caja`, `power-bi/articulos`, `n8n/productos-disponibles`) se
 * mudaron a `routes/mantenimiento.php`, cerradas por `solo.mantenimiento` (misión
 * cerrar-rutas-web-publicas, 10/10/2026).
 *
 * 🔴 Y UNA RUTA DE MANTENIMIENTO, DEBUG O ADMINISTRACIÓN INTERNA NO VA EN ESTE ARCHIVO: va en
 * `routes/mantenimiento.php`, que entero pasa por `solo.mantenimiento`. Toda ruta sin protección
 * tiene que estar declarada pública con su motivo en
 * tests/Feature/Seguridad/RutasPublicas/1_Ninguna_ruta_publica_sin_saberlo_Test.
 */

// PDF
// 🔴 La opción `tienda` va SOLO en sale/pdf/{id}: es la única ruta cuyo controlador exige el
// SalePdfAccessToken de `?origin=tienda`. Ponérsela a otra ruta de venta la abre a cualquiera.
Route::get('sale/pdf/{id}', 'SaleController@pdf')->middleware('descarga.comercio:sale,id,tienda');
Route::middleware('descarga.comercio:sale,id')->group(function () {
    Route::get('sale/ticket-pdf/{id}', 'SaleController@ticketPdf');
    Route::get('sale/ticket-raw/{id}', 'SaleController@ticketRaw');
    Route::get('sale/sale-ticket-pdf/{id}', 'SaleController@saleTicketPdf');
});

// El {id} de estas dos es el del comprobante de ARCA (afip_tickets), no el de la venta.
Route::middleware('descarga.comercio:afip_ticket,id')->group(function () {
    Route::get('sale/afip-ticket-pdf/{id}', 'SaleController@afipTicketPdf');
    Route::get('sale/afip-ticket-a4-pdf/{id}', 'SaleController@afipTicketA4Pdf');
});

Route::get('sale/delivered-articles-pdf/{id}', 'SaleController@deliveredArticlesPdf')->middleware('descarga.comercio:sale,id');
Route::get('sale/etiqueta-envio/pdf/{sale_id}', 'SaleController@etiqueta_envio')->middleware('descarga.comercio:sale,sale_id');


Route::get('client/pdf', 'ClientController@pdf');

Route::get('road-map/pdf/{id}', 'RoadMapController@pdf')->middleware('descarga.comercio:road_map,id');


// Deposit Movement
Route::get('deposit-movement/pdf/{id}', 'DepositMovementController@pdf')->middleware('descarga.comercio:deposit_movement,id');


// Article
// {ids} es la lista 12-15-40: la sesión tiene que ser dueña de TODOS los artículos.
Route::middleware('descarga.comercio:articles,ids')->group(function () {
    Route::get('article/pdf/{ids}/{moneda_id?}', 'ArticleController@pdf');
    Route::get('article/tickets-pdf/{ids}', 'ArticleController@ticketsPdf');
    Route::get('article/bar-codes-pdf/{ids}', 'ArticleController@barCodePdf');
    Route::get('article/bar-codes-etiquetas-pdf/{ids}', 'ArticleController@barCodeEtiquetasPdf');
    Route::get('article/list-pdf/{ids}', 'ArticleController@listPdf');
});
// Por perfil o personalizados: sin id de recurso, el controlador lee el dueño con userId().
Route::get('article/table-pdf', 'ArticleController@tablePdf')->middleware('descarga.comercio:sesion');
Route::get('article/article-offer-pdf/{article_pdf_id}/{ids}', 'ArticleController@articleOfferSheetPdf')->middleware('descarga.comercio:articles,ids');
Route::get('article/pdf-personalizado', 'ArticleController@pdfPersonalizado')->middleware('descarga.comercio:sesion');


Route::get('articles-stock-minimo/excel', 'InventoryPerformanceController@stock_minimo_excel')->middleware('descarga.comercio:sesion');

Route::get('budget/pdf/{id}/{with_prices}/{with_images}', 'BudgetController@pdf')->middleware('descarga.comercio:budget,id');
Route::middleware('descarga.comercio:order_production,id')->group(function () {
    Route::get('order-production/pdf/{id}/{with_prices}', 'OrderProductionController@pdf');
    Route::get('order-production/articles-pdf/{id}', 'OrderProductionController@articlesPdf');
});

#Route::get('/current-acount/pdf/{credit_account_id}/{months_ago}', 'CurrentAcountController@pdfFromModel');
// Con {months_ago} > 0 el primer parámetro es una CUENTA corriente; con 0, UN movimiento (así lo lee
// pdfFromModel). El middleware decide el tipo con la misma comparación.
Route::get('/current-acount/pdf/{credit_account_id}/{months_ago}/{type?}', 'CurrentAcountController@pdfFromModel')->middleware('descarga.comercio:cuenta_corriente,credit_account_id,months_ago');
Route::get('/current-acount/pdf/{id}', 'CurrentAcountController@pdf')->middleware('descarga.comercio:current_acount,id');

Route::get('order/pdf/{id}/', 'OrderController@pdf')->middleware('descarga.comercio:order,id');
Route::get('provider-order/pdf/{id}', 'ProviderOrderController@pdf')->middleware('descarga.comercio:provider_order,id');

// Excel
Route::middleware('descarga.comercio:sesion')->group(function () {
    Route::get('article-clients/excel/export/{price_type_id?}', 'ArticleController@clientsExport');
    Route::get('article-base/excel/export', 'ArticleController@baseExport');
    Route::get('client/excel/export', 'ClientController@export');
    Route::get('provider/excel/export', 'ProviderController@export');
});
Route::get('apertura-caja/excel/export/{id}', 'AperturaCajaController@export')->middleware('descarga.comercio:apertura_caja,id');

Route::get('/provider-orders/export/{id}', function ($id) {
    return Maatwebsite\Excel\Facades\Excel::download(new App\Exports\ProviderOrderExport($id), 'pedido_proveedor_'.$id.'.xlsx');
})->middleware('descarga.comercio:provider_order,id');

Route::middleware('descarga.comercio:sesion')->group(function () {
    Route::get('sales/excel/export/{from_date}/{until_date?}', 'SaleController@excel_export');
    Route::get('sales/excel/breakdown-export/{from_date}/{until_date?}', 'SaleController@excel_breakdown_export');
    Route::get('nota-credito/excel/export/{from_date}/{until_date?}', 'NotaCreditoController@excel_export');
    Route::get('cheque/excel/export', 'ChequeController@excel_export');
});



Route::get('sale/charts/{from}/{to}', 'SaleController@charts')->middleware('descarga.comercio:sesion');



// TXT del régimen de información de ARCA: SIN descarga.comercio, a propósito. Los protege su propio
// controlador desde la misión afip-txt-por-duenio (10/10/2026): sin sesión responden 401
// (AfipController::exportVentas y exportAlicuotasTxt) y su test lo fija. Pasarlos además por el
// middleware cambiaba ese 401 por un 404. Son internos (no salen links a terceros): no necesitan token
// ni ventana. El guardián de rutas los tiene en AFUERA_A_PROPOSITO con este motivo.
Route::get('afip-txt/{mes_inicio}/{mes_fin}', 'AfipController@exportVentas');
Route::get('afip-txt-alicuotas/{mes_inicio}/{mes_fin}', 'AfipController@exportAlicuotasTxt');

// Libros de IVA de ARCA: sin id de recurso, el controlador toma el dueño con userId().
Route::middleware('descarga.comercio:sesion')->group(function () {
    Route::get('afip-iva-compras/{mes_inicio}/{mes_fin}', 'AfipController@iva_compras_pdf');
    Route::get('afip-iva-ventas/{mes_inicio}/{mes_fin}', 'AfipController@iva_ventas_pdf');
});





Route::get('acopio-article-delivery/{id}', 'AcopioArticleDeliveryController@pdf')->middleware('descarga.comercio:acopio_article_delivery,id');


Route::get('resumen-caja/pdf/{id}', 'ResumenCajaController@pdf')->middleware('descarga.comercio:resumen_caja,id');
