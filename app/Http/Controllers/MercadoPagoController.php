<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\MercadoPagoCredentialsHelper;
use Illuminate\Http\Request;

class MercadoPagoController extends Controller
{
    /**
     * Consulta un pago en la API de Mercado Pago con las credenciales vigentes del comercio.
     *
     * El access_token sale de `MercadoPagoCredentialsHelper`, el unico lugar que conoce el orden
     * conector conectado -> `payment_methods`. Antes se leia derecho de `payment_methods` y el
     * metodo explotaba con "Call to a member function on null" si el comercio no tenia esa fila
     * (o si faltaba el payment_method_type "MercadoPago"); ahora eso responde 422.
     *
     * @param string|int $payment_id Id del pago en Mercado Pago.
     * @return \Illuminate\Http\JsonResponse
     */
    function payment($payment_id) {
        $access_token = MercadoPagoCredentialsHelper::access_token($this->userId());

        if (empty($access_token)) {
            return response()->json([
                'message' => 'El comercio no tiene una cuenta de Mercado Pago conectada.',
            ], 422);
        }

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
