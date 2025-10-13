<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoLibreController extends Controller
{
    
    function webhook(Request $request) {

        Log::info('MercadoLibreController webhook request:');
        Log::info((array)$request);

        if ($request->topic == 'orders_v2') {

            Log::info('Entro con topic orders_v2');

            $service = new OrderDownloaderService();

            $service->obtener_order($request->resource);
        }
    }

}
