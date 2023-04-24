<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use Illuminate\Http\Request;

class MercadoPagoController extends Controller
{
    function payment($payment_id) {
        $mercadopago_pm_type = PaymentMethodType::where('name', 'MercadoPago')
                                                                ->first();
        $payment_method = PaymentMethod::where('user_id', $this->userId())
                                        ->where('payment_method_type_id', $mercadopago_pm_type->id)
                                        ->first();
        $access_token = $payment_method->access_token;

        $curl = curl_init(); 
        curl_setopt($curl, CURLOPT_URL, 'https://api.mercadopago.com/v1/payments/'.$payment_id);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token,
        ]);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_ENCODING, '');
        curl_setopt($curl, CURLOPT_TIMEOUT, 0);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        $response = curl_exec($curl);
        $json_data = json_decode($response, true);
        return response()->json(['payment' => $json_data], 200);
    }
}
