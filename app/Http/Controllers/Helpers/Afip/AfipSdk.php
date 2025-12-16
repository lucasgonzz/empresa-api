<?php

namespace App\Http\Controllers\Helpers\Afip;

use Illuminate\Support\Facades\Log;
use afipsdk\afip\src\Afip;

class AfipSdk
{

    public function __construct($sale) {
        $this->sale = $sale;
        $this->testing = !$this->sale->afip_information->afip_ticket_production;

        $afip = new Afip([
            'CUIT' => 20409378472,
            'access_token' => '4PIO1Ua7HMKjPdaLR3GjLv4nwzFm2n4YDEbldZFA4NTTdq4j4OaQhQEocTkAu4sl' // Obtenido de https://app.afipsdk.com
        ]);

        $ws = $afip->WebService('wsfex');

        // Obtenemos el TA
        $ta = $ws->GetTokenAuthorization();
            
        // Preparamos los datos
        $data = array(
            'Auth' => array( 
                'Token' => $ta->token,
                'Sign' => $ta->sign,
                'Cuit' => $afip->CUIT
            )
        );


        $res = $ws->ExecuteRequest('FEXGetLast_ID', $data);
    }
}
